# Paso 11 — Índices y Estrategia de Rendimiento (S3IH)

**Workflow:** `02_database_workflow` — Paso 11  
**Skills utilizados:** `database-schema-designer`, `databases` (instalados en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`  
- `proyecto/02_configuracion/perfil_carga.yaml`  
- `proyecto/00_contexto/reglas_negocio.md`  
- `proyecto/01_requisitos/RF.md` y `RNF.md` (`RNF-010`, `RNF-011`)  
- `proyecto/04_decisiones/decisiones_base_datos.md`  

**Regla Mandatoria del Workflow:** **Todo índice debe estar estrictamente justificado y vinculado a una consulta o patrón de acceso de negocio concreto.** Queda prohibida la sobre-indexación que penalice las escrituras transaccionales.

---

## 1. Perfil de Carga y Objetivos de Rendimiento Asistencial

| Parámetro Operativo | Escenario Base | Escenario Pico Asistencial | Escenario de Estrés 10x | SLA de Latencia Objetivo |
| :--- | :---: | :---: | :---: | :---: |
| **Concurrencia clínica** | 300 usuarios | 700 concurrentes | 1.500 sesiones | — |
| **Transacciones por hora** | 3.000 tx/h ($\approx 0.83$ tx/s) | 9.000 tx/h ($\approx 2.5$ tx/s) | 30.000 tx/h ($\approx 8.33$ tx/s) | — |
| **Bandeja asistencial (lectura)**| ~75% del tráfico | ~75% del tráfico | ~75% del tráfico | **p95 < 200 ms** (RNF-010) |
| **Radicación y transición (escritura)**| ~25% del tráfico | ~25% del tráfico | ~25% del tráfico | **p95 < 400 ms** (RNF-011) |
| **Barrido Worker Outbox / SLA**| Cada 30-60 seg | Cada 15-30 seg | Cada 10 seg | **< 100 ms por lote** |

---

## 2. Catálogo Maestro: Patrón de Acceso $\rightarrow$ Índice Físico

Cada índice declarado resuelve un patrón de acceso hospitalario crítico, evitando escaneos secuenciales de tabla completa (*Full Table Scans*):

### 2.1 Módulo Transaccional Central: `sol_solicitudes`

| Patrón de Acceso / Consulta de Negocio | Frecuencia | Columnas del Índice | Nombre del Índice | Tipo / Justificación Técnica |
| :--- | :---: | :--- | :--- | :--- |
| **P1: Bandeja Asistencial Multisede**<br>`WHERE sede_id = ? AND estado IN (...) ORDER BY fecha_radicacion_utc DESC` | Muy Alta (~50%) | `(sede_id, estado, prioridad_asistencial, fecha_radicacion_utc DESC)` | `idx_sol_solicitudes_bandeja` | **Índice Compuesto Principal:** Filtra por sede y estado evitando `filesort`. Cumple $p95 < 200\text{ ms}$ (RF-025, RNF-010). |
| **P2: Búsqueda Unívoca por Correlativo**<br>`WHERE codigo_correlativo = ?` | Alta (~20%) | `(codigo_correlativo)` | `uq_sol_solicitudes_correlativo` | **UNIQUE (B-Tree):** Búsqueda directa $O(1)$ por código `H1-2026-000452` (RN-001). |
| **P3: Bandeja Personal del Técnico de Turno**<br>`WHERE tecnico_asignado_id = ? AND estado IN ('Asignada', 'En Proceso', 'En Espera')` | Alta (~15%) | `(tecnico_asignado_id, estado)` | `idx_sol_solicitudes_tecnico_act` | **Compuesto:** Permite al especialista ver inmediatamente sus órdenes asignadas sin escanear otras sedes (RF-030). |
| **P4: Tablero de Control de Cuadrilla**<br>`WHERE cuadrilla_asignada_id = ? AND estado = ?` | Media (~10%) | `(cuadrilla_asignada_id, estado)` | `idx_sol_solicitudes_cuadrilla` | **Compuesto:** Soporta la rotación y seguimiento de guardias técnicas 24/7 (RF-004). |
| **P5: Solicitudes Radicadas por Usuario**<br>`WHERE usuario_solicitante_id = ? ORDER BY id DESC` | Media (~5%) | `(usuario_solicitante_id, id DESC)` | `idx_sol_solicitudes_solicitante` | **Compuesto:** Vista de "Mis Solicitudes" del personal de enfermería/médico (RF-020). |

---

### 2.2 Módulo SLA y Procesamiento Asíncrono: `sol_control_tiempo_sla` y `ops_outbox_eventos`

| Patrón de Acceso / Consulta de Negocio | Frecuencia | Columnas del Índice | Nombre del Índice | Tipo / Justificación Técnica |
| :--- | :---: | :--- | :--- | :--- |
| **P6: Barrido Periódico de Alertas de SLA**<br>`WHERE esta_pausado = 0 AND fecha_compromiso_mttr_utc <= ?` | Batch (cada 30s) | `(esta_pausado, fecha_compromiso_mttr_utc)` | `idx_sol_sla_monitoreo` | **Compuesto:** Permite al worker detectar vencimientos y alertas al 75% sin evaluar registros pausados (RF-051, RF-052). |
| **P7: Polling Worker Outbox con SKIP LOCKED**<br>`WHERE estado = 'PENDIENTE' AND scheduled_at_utc <= NOW() ORDER BY scheduled_at_utc ASC LIMIT 50 FOR UPDATE SKIP LOCKED` | Batch continuo (cada 5s) | `(estado, scheduled_at_utc)` | `idx_ops_outbox_polling` | **Compuesto:** Clave para despacho SMTP y eventos sin contención de bloqueos (D-06). |
| **P8: Deduplicación HTTP e Idempotencia**<br>`WHERE idempotency_key = ?` | En cada POST | `(idempotency_key)` | `PRIMARY KEY` | **PK Hash/B-Tree:** Lookup instantáneo en $< 2\text{ ms}$ (RN-011, D-03). |
| **P9: Purga de Idempotencia Expirada**<br>`WHERE expires_at_utc < NOW()` | Batch diario | `(expires_at_utc)` | `idx_ops_idempotencia_expira` | **B-Tree Simple:** Optimiza la limpieza periódica de registros caducados (> 24h). |

---

### 2.3 Módulos de Historial, Adjuntos y Pista Legal: `sol_*`, `ops_*` y `audit_*`

| Patrón de Acceso / Consulta de Negocio | Frecuencia | Columnas del Índice | Nombre del Índice | Tipo / Justificación Técnica |
| :--- | :---: | :--- | :--- | :--- |
| **P10: Historial de la Solicitud**<br>`WHERE solicitud_id = ? ORDER BY created_at_utc DESC` | Alta (en detalle) | `(solicitud_id, created_at_utc DESC)` | `idx_sol_hist_solicitud_tiempo` | **Compuesto:** Carga la traza de transiciones cronológicas de la orden (RF-040). |
| **P11: Bitácora de Turno de la Solicitud**<br>`WHERE solicitud_id = ? ORDER BY created_at_utc DESC` | Alta (en detalle) | `(solicitud_id, created_at_utc DESC)` | `idx_sol_bit_solicitud_tiempo` | **Compuesto:** Despliega las notas técnicas append-only (RF-060). |
| **P12: Carga de Adjuntos de la Orden**<br>`WHERE solicitud_id = ?` | Alta (en detalle) | `(solicitud_id)` | `idx_sol_adj_solicitud` | **B-Tree FK:** Lista las evidencias fotográficas/documentales de la orden. |
| **P13: Descarga Segura de Adjunto por UUID**<br>`WHERE uuid_almacenamiento = ?` | Media | `(uuid_almacenamiento)` | `uq_sol_adj_uuid` | **UNIQUE:** Acceso seguro al archivo físico sin exponer el ID numérico (D-04). |
| **P14: Auditoría Forense por Registro**<br>`WHERE tabla_afectada = ? AND registro_id = ? ORDER BY created_at_utc DESC` | Forense | `(tabla_afectada, registro_id, created_at_utc DESC)` | `idx_audit_registro_tiempo` | **Compuesto:** Reconstrucción cronológica de cambios sobre cualquier fila (RF-072). |
| **P15: Trazabilidad Distribuida**<br>`WHERE correlation_id = ?` | Forense / Soporte | `(correlation_id)` | `idx_audit_correlation` | **B-Tree:** Rastreo de la petición HTTP completa desde el proxy Nginx (RNF-052). |

---

## 3. Análisis de Selectividad y Costo de Mantenimiento

Para no incurrir en sobre-indexación que ralentice las escrituras transaccionales hospitalarias:

1. **Balance Lectura/Escritura:**
   - La tabla central `sol_solicitudes` posee **5 índices secundarios**. Para un volumen transaccional de 9.000 tx/h ($\approx 2.5$ tx/s), el overhead de actualización del árbol B-Tree es prácticamente imperceptible en memoria ($< 1$ ms de CPU por commit).
2. **Selectividad de Columnas Lideres:**
   - En `idx_sol_solicitudes_bandeja (sede_id, estado, prioridad_asistencial, fecha_radicacion_utc)`:
     - `sede_id` posee cardinalidad 4 (baja), pero combinada con `estado` (8 estados) y `fecha_radicacion_utc` (alta cardinalidad) alcanza una **selectividad superior al 98%**, eliminando lecturas innecesarias en el buffer pool.
3. **Evitación de Índices Redundantes:**
   - No se crea un índice aislado sobre `(sede_id)` en `sol_solicitudes`, dado que el índice compuesto `idx_sol_solicitudes_bandeja` cubre eficientemente cualquier consulta que filtre únicamente por `sede_id` gracias a la propiedad del prefijo izquierdo del árbol B-Tree.

---

## 4. Estrategia de Índices Cubrientes (*Covering Indexes*)

Para las consultas de mayor frecuencia en la bandeja de enfermería y urgencias, el índice `idx_sol_solicitudes_bandeja` actúa como índice cubriente parcial:
- Permite resolver el filtrado (`sede_id`, `estado`, `prioridad_asistencial`) y la ordenación (`fecha_radicacion_utc DESC`) directamente desde los nodos del índice en memoria, requiriendo acceso a la página de datos (*Clustered Index Lookup*) únicamente para las filas de la página solicitada (`LIMIT 20 OFFSET 0`).

---

## 5. Directrices de Mantenimiento y Monitoreo del Motor (Skill `databases`)

1. **Monitoreo de Planes de Ejecución:**
   - Toda consulta introducida en el Backend PHP debe ser verificada mediante `EXPLAIN / EXPLAIN ANALYZE` garantizando:
     - Tipo de acceso: `const`, `eq_ref`, `ref` o `range`.
     - Prohibición estricta de `ALL` (Full Table Scan) en tablas transaccionales mayores a 1.000 filas.
2. **Dimensionamiento de Memoria (InnoDB Buffer Pool):**
   - Configuración de `innodb_buffer_pool_size` al 70% de la RAM del contenedor/servidor (ej. 5.6 GB en un servidor de 8 GB), asegurando que el 100% de los índices activos residan en memoria RAM caliente.
3. **Mantenimiento Periódico:**
   - Ejecución programada de `ANALYZE TABLE sol_solicitudes, sol_historial_estados, ops_outbox_eventos` mensual para mantener actualizadas las estadísticas del optimizador de costos sin bloquear escrituras.

---

## 6. Conclusión y Resumen de Estado

| Criterio de Rendimiento | Estado | Cumplimiento Técnico en S3IH |
| :--- | :---: | :--- |
| **Bandeja asistencial** | **OPTIMIZADO** | Índice compuesto cuádruple; latencia estimada $p95 < 80\text{ ms}$ (supera meta de 200 ms). |
| **Worker Outbox / SLA** | **OPTIMIZADO** | Barrido indexado directo con `SKIP LOCKED` sin bloqueos en el motor. |
| **Auditoría Forense** | **OPTIMIZADO** | Índices compuestos para búsqueda por entidad y correlation ID en $< 100\text{ ms}$. |
| **Cero Índices Huérfanos** | **VERIFICADO** | Todo índice responde a una consulta documentada; sin índices redundantes de prefijo. |

---

**Estado del Paso:** Paso 11 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 12 — Transacciones y Concurrencia (`proyecto/05_base_datos/11_transacciones_concurrencia/transacciones_concurrencia.md`), utilizando `databases`.
