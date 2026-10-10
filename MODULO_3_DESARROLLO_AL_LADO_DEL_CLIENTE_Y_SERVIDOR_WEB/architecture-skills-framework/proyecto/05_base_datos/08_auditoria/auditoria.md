# Paso 10 — Auditoría e Histórico de Negocio (S3IH)

**Workflow:** `02_database_workflow` — Paso 10 (Artefacto 1 de 2)  
**Skills utilizados:** `database-schema-designer`, `databases` (instalados en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`  
- `proyecto/05_base_datos/06_integridad/integridad.md`  
- `proyecto/05_base_datos/07_seguridad/seguridad.md`  
- `proyecto/00_contexto/reglas_negocio.md` (`RN-005`, `RN-008`, `RN-010`, `RN-012`, `RN-013`)  
- `proyecto/01_requisitos/RF.md` (`RF-040`, `RF-047`, `RF-060`, `RF-072`)  
- `proyecto/01_requisitos/RNF.md` (`RNF-035`, `RNF-050`, `RNF-052`)  

**Objetivo:** Establecer la separación conceptual y técnica entre la auditoría de cambios del sistema y el histórico temporal de negocio del ciclo de vida hospitalario, garantizando inmutabilidad, no repudio y cumplimiento legal por 5 años.

---

## 1. Delimitación Arquitectónica: Auditoría vs. Histórico de Negocio

En el ámbito hospitalario se definen y segregan estrictamente tres mecanismos complementarios de registro:

```
+-------------------------------------------------------------------------------------------------+
|                                 ARQUITECTURA DE REGISTRO S3IH                                   |
+-------------------------------------------------------------------------------------------------+
|  MECANISMO 1: HISTÓRICO TEMPORAL DE NEGOCIO                                                     |
|  - Máquina de estados: sol_historial_estados (append-only)                                      |
|  - Cambios de personal: sol_reasignaciones (supervisión de cuadrillas)                          |
|  - Inconformidad clínica: sol_reaperturas (ventana de 48h)                                      |
|  - Bitácora técnica: sol_bitacora (notas operativas de turno)                                   |
|  --> Propósito: Conocer la evolución del requerimiento médico en la interfaz de usuario.        |
+-------------------------------------------------------------------------------------------------+
|  MECANISMO 2: PISTA LEGAL DE AUDITORÍA (NO REPUDIO)                                             |
|  - Tabla central: audit_eventos (append-only con hash encadenado SHA-256)                         |
|  - Registro de deltas fotográficos: datos_anteriores_json vs datos_nuevos_json                   |
|  - Metadatos: IP del cliente, usuario LDAP, acción, correlation_id                              |
|  --> Propósito: Peritaje legal, cumplimiento normativo y fiscalización forense por 5 años.      |
+-------------------------------------------------------------------------------------------------+
|  MECANISMO 3: VERSIONAMIENTO DE ESQUEMA DDL                                                     |
|  - Evolución controlada del modelo de base de datos sin downtime hospitalario.                  |
|  --> Documentado en: 09_versionamiento/versionamiento.md                                        |
+-------------------------------------------------------------------------------------------------+
```

---

## 2. Histórico Temporal de Negocio: Ciclo de Vida Hospitalario

El histórico de negocio no es una pista técnica abstracta; forma parte de las entidades transaccionales visibles por los profesionales médicos y técnicos:

### 2.1 Pista de Transiciones: `sol_historial_estados` (RF-040, RN-005)
- **Naturaleza:** Append-only estricto. Cada fila representa un cambio de fase en la máquina de estados finita.
- **Estructura Físicamente Auditada:**
  - `solicitud_id`: Requerimiento intervenido.
  - `estado_anterior` y `estado_nuevo`: Validación estricta de la máquina de estados (`Registrada`, `Asignada`, `En Proceso`, `En Espera`, `Atendida`, `Cerrada`, `Rechazada`, `Cancelada`).
  - `causal_codigo`: Obligatorio en suspensiones (`En Espera`) y rechazos técnicos (FK hacia `cat_causales`).
  - `motivo_texto`: Justificación clínica/técnica obligatoria ($\ge 20$ caracteres para cancelaciones y rechazos).
  - `usuario_id`: Funcionario autor de la acción (no repudio).
  - `ip_origen`: IP institucional de la estación de trabajo.
  - `created_at_utc`: Marca temporal de servidor en UTC.
- **Regla de Integridad:** Prohibidas las sentencias `UPDATE` y `DELETE` mediante permisos en motor (`REVOKE UPDATE, DELETE ON sol_historial_estados FROM s3ih_app_user;`).

### 2.2 Auditoría de Asignación y Turnos: `sol_reasignaciones` (RF-031, RN-008)
- **Naturaleza:** Registro formal de transferencia de responsabilidad durante rotaciones de turno 24/7 o emergencias de cuadrilla.
- **Datos Registrados:** `tecnico_anterior_id`, `tecnico_nuevo_id`, `cuadrilla_anterior_id`, `cuadrilla_nueva_id`, `motivo` justificado y `usuario_autorizador_id` (supervisor clínico que aprueba la reasignación).

### 2.3 Registro de Inconformidad: `sol_reaperturas` (RF-047, RN-010)
- **Naturaleza:** Control de fallas no resueltas dentro de la ventana de 48 horas tras la atención técnica.
- **Datos Registrados:** `causal_codigo` (FK catálogo), `motivo_inconformidad` detallado y `usuario_solicitante_id`.

### 2.4 Bitácora Cronológica de Turno: `sol_bitacora` (RF-060)
- **Naturaleza:** Cuaderno de bitácora técnico append-only para anotaciones de guardia (`NOTA_TECNICA`, `CAMBIO_GUARDIA`, `PIEZA_SOLICITADA`, `COMUNICACION`). Permite al personal entrante del turno noche conocer las maniobras efectuadas durante la tarde.

---

## 3. Pista Legal de Auditoría: `audit_eventos` (RF-072, RN-012, RN-013)

### 3.1 Estructura y Captura Fotográfica de Deltas
La tabla `audit_eventos` almacena el registro inmutable de toda transacción crítica del sistema:

| Atributo | Tipo Físico | Restricción | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | AUTO_INCREMENT, PK | Secuencial único de evento |
| `usuario_id` | `INT UNSIGNED` | NOT NULL | Funcionario autor de la operación |
| `ip_origen` | `VARCHAR(45)` | NOT NULL | Dirección IPv4 o IPv6 de la estación de trabajo |
| `accion` | `VARCHAR(50)` | NOT NULL | `CREAR`, `ASIGNAR`, `TRANSICIONAR`, `REABRIR`, `CONFIGURAR` |
| `tabla_afectada`| `VARCHAR(50)` | NOT NULL | Nombre de la tabla intervenida (ej. `sol_solicitudes`) |
| `registro_id` | `BIGINT UNSIGNED` | NOT NULL | Clave primaria del registro alterado |
| `datos_anteriores_json`| `JSON` | NULL | Fotografía del estado de la fila antes del cambio |
| `datos_nuevos_json`| `JSON` | NULL | Fotografía resultante tras la confirmación del commit |
| `hash_encadenado_sha256`| `VARCHAR(64)` | NOT NULL | Firma criptográfica encadenada de la pista de auditoría |
| `correlation_id`| `VARCHAR(36)` | NULL | Identificador de traza HTTP distribuida (`X-Correlation-ID`) |
| `created_at_utc`| `DATETIME` | NOT NULL | Estampa de tiempo exacta del servidor en UTC |

### 3.2 Algoritmo de Encadenamiento Criptográfico (Blockchain-style Integrity)
Para garantizar el no repudio y evitar que incluso un operador de base de datos pueda alterar registros pasados sin ser detectado:

1. Cada inserción calcula el hash SHA-256 tomando como semilla el hash del registro inmediatamente anterior:
   $$\text{Hash}_n = \text{SHA256}(\text{Hash}_{n-1} \parallel \text{id}_n \parallel \text{usuario\_id}_n \parallel \text{tabla\_afectada}_n \parallel \text{registro\_id}_n \parallel \text{datos\_nuevos\_json}_n \parallel \text{created\_at\_utc}_n)$$
2. El primer registro histórico se inicializa con el hash raíz institucional del hospital (*Genesis Hash*).
3. Cualquier alteración de un campo en registros históricos rompe la coincidencia matemática del hash en todas las filas subsecuentes, evidenciando manipulación fraudulenta de la base de datos.

### 3.3 Minimización de Datos y Cumplimiento Zero-PHI en Auditoría
- **Filtrado Preventivo:** Antes de serializar `datos_anteriores_json` y `datos_nuevos_json`, la capa de persistencia enmascara cualquier dato potencialmente confidencial o credencial de red.
- **Zero-PHI (RNF-035):** Las fotografías JSON jamás deben contener nombres de pacientes ni diagnósticos médicos reservados; solo atributos técnicos de infraestructura hospitalaria.

---

## 4. Política de Retención y Almacenamiento a Largo Plazo (5 Años)

1. **Plazo Legal Mandatorio:** En cumplimiento de las regulaciones de acreditación hospitalaria y **RN-013**, la retención mínima activa de la pista de auditoría y de los requerimientos es de **5 años** ($\ge 1.500.000\text{ solicitudes}$ proyectadas en la red de 4 centros).
2. **Estrategia de Tablas Frías / Archivado:**
   - Durante los primeros 24 meses, los datos permanecen en las particiones activas de alta velocidad.
   - Entre los meses 25 y 60, los registros pueden ser particionados por año (`PARTITION BY RANGE (YEAR(fecha_radicacion_utc))`) a almacenamiento secundario de solo lectura (cold storage) dentro de la misma base de datos, manteniendo disponibilidad de consulta para requerimientos judiciales o auditorías ministeriales.
3. **Prohibición de Depuración:** Se rechaza terminantemente la ejecución de sentencias `DELETE`, `TRUNCATE` o rutinas automatizadas de limpieza destructiva sobre las tablas `audit_eventos` y `sol_historial_estados`.

---

## 5. Criterios de Consulta y Monitoreo Forense

Se definen índices compuestos para auditoría forense con tiempos de respuesta menores a 500 ms:

```sql
-- Búsqueda de historial de un registro específico
CREATE INDEX idx_audit_registro_tiempo 
    ON audit_eventos (tabla_afectada, registro_id, created_at_utc DESC);

-- Auditoría de actividad por usuario institucional
CREATE INDEX idx_audit_usuario_tiempo 
    ON audit_eventos (usuario_id, created_at_utc DESC);

-- Trazabilidad distribuida extremo a extremo
CREATE INDEX idx_audit_correlation 
    ON audit_eventos (correlation_id);
```

---

**Conclusión:** La estrategia de auditoría e histórico asegura el cumplimiento pleno de las reglas institucionales hospitalarias, combinando visibilidad de turno para el personal médico con una pista forense criptográfica inalterable por 5 años.
