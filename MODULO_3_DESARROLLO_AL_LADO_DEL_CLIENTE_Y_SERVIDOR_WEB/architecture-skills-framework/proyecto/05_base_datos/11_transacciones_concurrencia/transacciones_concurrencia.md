# Paso 12 — Transacciones y Control de Concurrencia (S3IH)

**Workflow:** `02_database_workflow` — Paso 12  
**Skill utilizado:** `databases` (Transaction Isolation & Locking Patterns)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`  
- `proyecto/05_base_datos/06_integridad/integridad.md`  
- `proyecto/04_decisiones/decisiones_base_datos.md` (`D-01`, `D-03`, `D-06`)  
- `proyecto/01_requisitos/RNF.md` (`RNF-005`, `RNF-020`, `RNF-021`, `RNF-022`)  
- `proyecto/00_contexto/reglas_negocio.md` (`RN-005`, `RN-006`, `RN-007`, `RN-011`)  

**Objetivo:** Establecer las fronteras transaccionales ACID, el nivel de aislamiento de base de datos, el protocolo de concurrencia optimista contra colisiones en puestos clínicos compartidos (D-01) y el patrón Transactional Outbox no bloqueante con `SKIP LOCKED` (D-06). **Sin DDL ni código de aplicación prematuro.**

---

## 1. Desafíos de Concurrencia en Entornos Hospitalarios

La operación clínica en una red de 4 hospitales presenta tres riesgos mayores de concurrencia:

1. **Colisiones en Puestos Clínicos Compartidos (Pabellones / Quirófanos):**  
   Múltiples profesionales operan terminales compartidas simultáneamente. Si dos coordinadores intentan asignar o cambiar el estado de la misma orden clínica en paralelo, un modelo sin control produciría sobreescrituras ciegas (*Lost Updates*).
2. **Contención en el Despacho de Eventos y SLA (Worker Daemon):**  
   Si múltiples instancias o hilos del worker evalúan alertas de SLA o envían correos SMTP al mismo tiempo, pueden competir por las mismas filas de la cola generando bloqueos mutuos (*Deadlocks*) o envíos duplicados a los jefes de servicio.
3. **Reintentos por Inestabilidad Wi-Fi Hospitalaria:**  
   Caídas momentáneas de red en tablets de enfermería provocan reintentos automáticos de peticiones de radicación que podrían duplicar requerimientos en base de datos.

---

## 2. Nivel de Aislamiento de Base de Datos

En cumplimiento de las buenas prácticas del skill `databases` para cargas transaccionales OLTP concurrentes:

| Nivel de Aislamiento | Evaluación en S3IH | Decisión Técnica |
| :--- | :--- | :---: |
| **`READ COMMITTED`** | **Recomendado para S3IH:** Elimina los bloqueos de brecha (*Gap Locks*) innecesarios en InnoDB. Cada sentencia SQL lee su propia instantánea consistente de datos comprometidos, maximizando la concurrencia entre los 700 usuarios y reduciendo la contención en tablas calientes. | **SELECCIONADO** |
| `REPEATABLE READ` | Valor por defecto en MySQL. Aplica *Next-Key Locking* en rangos, lo que en tablas transaccionales de alta frecuencia (`sol_solicitudes`) incrementa la probabilidad de deadlocks entre transacciones concurrentes. | No recomendado para OLTP concurrente |
| `SERIALIZABLE` | Bloqueo pesimista extremo que convierte lecturas en bloqueos compartidos. Inviable para 700 concurrentes; degradaría severamente la latencia ($p95 \gg 200\text{ ms}$). | Descartado |

**Configuración en el Motor:**
- En MySQL 8.x: `transaction-isolation = READ-COMMITTED` en configuración global / por sesión PDO.
- En PostgreSQL 16+: `default_transaction_isolation = 'read committed'`.

---

## 3. Protocolo de Concurrencia Optimista (D-01, CA-004)

Para neutralizar el riesgo de colisiones entre usuarios clínicos sin penalizar el rendimiento con bloqueos pesimistas de larga duración:

### 3.1 Mecanismo de Versión Numérica
La tabla central `sol_solicitudes` incorpora la columna:
```sql
version INT UNSIGNED NOT NULL DEFAULT 1
```

### 3.2 Flujo de Ejecución Transaccional
1. **Fase de Lectura:** La aplicación consulta el requerimiento y lee su versión actual:
   ```sql
   SELECT id, estado, tecnico_asignado_id, version 
   FROM sol_solicitudes 
   WHERE id = :id;
   ```
2. **Fase de Edición del Usuario:** El usuario interactúa con la interfaz en su pantalla (tiempo humano: de 5 a 60 segundos). La base de datos no retiene ningún bloqueo durante este tiempo.
3. **Fase de Actualización Atómica:** Al enviar el cambio de estado o reasignación, la sentencia `UPDATE` verifica atómicamente la versión leída y la incrementa:
   ```sql
   UPDATE sol_solicitudes 
   SET estado = :nuevo_estado, 
       tecnico_asignado_id = :nuevo_tecnico, 
       version = version + 1, 
       updated_at_utc = CURRENT_TIMESTAMP
   WHERE id = :id AND version = :version_leida;
   ```
4. **Manejo de Colisiones:**
   - Si `filas_afectadas == 1`: La actualización fue exitosa; el commit procede.
   - Si `filas_afectadas == 0`: Significa que otro usuario modificó la solicitud mientras tanto. La transacción se revierte (`ROLLBACK`), se informa al usuario mediante código HTTP `409 Conflict` y la interfaz recarga los datos frescos sin deadlocks en el motor.

---

## 4. Fronteras Transaccionales Atómicas (ACID)

Cada operación de negocio hospitalaria se encapsula en una transacción ACID corta y estrictamente local.

> [!IMPORTANT]
> **Regla Mandatoria D-02:** Terminantemente prohibido mantener una transacción de base de datos (`START TRANSACTION ... COMMIT`) abierta mientras se espera I/O externo de red (autenticación LDAP, llamada a API externa o despacho de correos SMTP). Toda operación asíncrona se desacopla mediante la tabla Outbox.

### 4.1 Flujo: Radicación de Solicitud (RF-020, RN-001)
Transacción atómica compuesta:
1. `INSERT INTO sol_solicitudes (...)` con `version = 1`.
2. `INSERT INTO sol_control_tiempo_sla (...)` vinculando el calendario y plazos MTTA/MTTR calculados.
3. `INSERT INTO sol_historial_estados (...)` registrando la transición inicial `-> Registrada`.
4. `INSERT INTO audit_eventos (...)` con el delta de creación y hash encadenado.
5. `INSERT INTO ops_outbox_eventos (...)` programando la notificación inicial a jefatura técnica.
6. `COMMIT`.

### 4.2 Flujo: Cambio de Estado / Suspensión de SLA (RF-040, RN-005, RN-006)
Transacción atómica compuesta:
1. `UPDATE sol_solicitudes SET estado = :nuevo, version = version + 1 WHERE id = :id AND version = :version_leida;` (Control optimista D-01).
2. `INSERT INTO sol_historial_estados (...)` con causal y motivo obligatorio.
3. Si la transición es a `En Espera`:
   - `UPDATE sol_control_tiempo_sla SET esta_pausado = 1, fecha_ultima_pausa_utc = NOW(), fecha_tentativa_reanudacion = :fecha WHERE solicitud_id = :id;`
4. Si la transición es de `En Espera` a `En Proceso`:
   - Cálculo del intervalo pausado y actualización de `segundos_pausados_acumulados` y `esta_pausado = 0`.
5. `INSERT INTO audit_eventos (...)` capturando el delta de auditoría.
6. `COMMIT`.

### 4.3 Flujo: Registro de Cierre Técnico e Informe (RF-044, RN-007, RN-010)
Transacción atómica compuesta:
1. `INSERT INTO sol_informes_atencion (...)` con diagnóstico, tiempo real e insumos.
2. `UPDATE sol_solicitudes SET estado = 'Atendida', fecha_atencion_utc = NOW(), version = version + 1 WHERE id = :id AND version = :version_leida;`
3. `INSERT INTO sol_historial_estados (...)` registrando el pase a `Atendida`.
4. `INSERT INTO ops_outbox_eventos (...)` programando el evento `AUTO_CIERRE_48H` con fecha programada `NOW() + 48 HOURS`.
5. `INSERT INTO audit_eventos (...)`.
6. `COMMIT`.

---

## 5. Patrón Transactional Outbox No Bloqueante con `SKIP LOCKED` (D-06)

Para que el worker daemon despache notificaciones y evalúe SLAs concurrentemente sin generar contención ni bloqueos de fila:

```sql
-- Polling no bloqueante de eventos pendientes
SELECT id, tipo_evento, agregado_id, payload_json 
FROM ops_outbox_eventos 
WHERE estado = 'PENDIENTE' AND scheduled_at_utc <= CURRENT_TIMESTAMP
ORDER BY scheduled_at_utc ASC 
LIMIT 50 
FOR UPDATE SKIP LOCKED;
```

### Propiedades de Concurrencia de `SKIP LOCKED`
- Cada hilo o proceso del worker toma un lote de 50 eventos y los bloquea para su procesamiento.
- Si otro hilo ejecuta el mismo barrido en paralelo, la cláusula `SKIP LOCKED` **salta de inmediato las filas ya bloqueadas**, tomando las 50 siguientes disponibles sin esperar ni bloquearse.
- Una vez despachado el correo SMTP (fuera de la transacción de base de datos), el worker ejecuta:
  ```sql
  UPDATE ops_outbox_eventos 
  SET estado = 'ENVIADO', processed_at_utc = CURRENT_TIMESTAMP 
  WHERE id IN (:lote_ids);
  ```

---

## 6. Prevención y Detección de Deadlocks

Siguiendo las directrices del skill `databases`:

1. **Orden Canónico de Acceso:**  
   Toda transacción multi-tabla adquiere bloqueos en el mismo orden jerárquico estricto:
   $$\text{Tabla Maestra (sol\_solicitudes)} \longrightarrow \text{Tablas Hijas (sol\_sla, sol\_historial, sol\_informe)} \longrightarrow \text{Outbox / Auditoría}$$
2. **Transacciones de Ultracorta Duración:**  
   Ninguna transacción debe demorar más de 50 milisegundos de tiempo de máquina.
3. **Timeout de Bloqueo Agresivo:**  
   Configuración de `innodb_lock_wait_timeout = 5` segundos en el motor. Si por alguna anomalía se genera una espera imprevista, la base de datos aborta la transacción a los 5 segundos evitando encolamientos masivos.

---

## 7. Conclusión y Resumen de Estado

| Mecanismo de Concurrencia | Estado | Garantía Técnica Implementada |
| :--- | :---: | :--- |
| **Nivel de Aislamiento** | **`READ COMMITTED`** | Máximo paralelismo OLTP sin contención de gap locks en 700 concurrentes. |
| **Bloqueo Optimista (D-01)** | **APROBADO** | Columna `version` en `sol_solicitudes`; cero colisiones ciegas en puestos compartidos. |
| **Fronteras Transaccionales** | **APROBADO** | Transacciones cortas ACID; prohibición estricta de I/O externo dentro de commits. |
| **Polling Outbox (D-06)** | **APROBADO** | `FOR UPDATE SKIP LOCKED` nativo para despacho de eventos sin deadlocks. |
| **Idempotencia (D-03)** | **APROBADO** | Deduplicación HTTP en $< 2\text{ ms}$ mediante hash y clave única con TTL 24h. |

---

**Estado del Paso:** Paso 12 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 13 — Plan de Migraciones Forward y Rollback (`proyecto/05_base_datos/12_migraciones/plan_migraciones.md`), utilizando `database-schema-designer` y `databases`.
