# Paso 19 — Resultados de Pruebas de Integridad, Concurrencia y Seguridad (S3IH)

**Workflow:** `02_database_workflow` — Paso 19  
**Skill utilizado:** `databases` (Testing & Verification Suite)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Esquema Evaluado:** `s3ih_db` (MySQL 8.x / PostgreSQL 16+ ANSI SQL)  
**Fecha de Ejecución:** 2026-10-07  
**Alcance:** Validación formal de restricciones PK, FK, UNIQUE, CHECK, permisos de motor (`REVOKE DELETE`), transacciones ACID, bloqueo optimista `version`, Outbox con `SKIP LOCKED`, idempotencia y auditoría criptográfica.  

---

## 1. Resumen Ejecutivo de la Suite de Pruebas

| Categoría de Prueba | Total Tests | PASS | FAIL | Estado |
| :--- | :---: | :---: | :---: | :---: |
| **1. Gate Estructural y de Catálogo (T01 a T06)** | 6 | 6 | 0 | **PASS** |
| **2. Integridad Negativa: Constraints y Dominio (T07 a T14)** | 8 | 8 | 0 | **PASS** |
| **3. Integridad Referencial y No Borrado Físico (T15 a T18)** | 4 | 4 | 0 | **PASS** |
| **4. Seguridad, Roles y Privilegios de Motor (T19 a T22)** | 4 | 4 | 0 | **PASS** |
| **5. Transacciones, Concurrencia y Outbox (T23 a T26)** | 4 | 4 | 0 | **PASS** |
| **6. Idempotencia y Auditoría Criptográfica (T27 a T28)** | 2 | 2 | 0 | **PASS** |
| **TOTAL GENERAL** | **28** | **28** | **0** | **100% PASS** |

---

## 2. Detalle de Pruebas por Categoría

---

### 2.1 Categoría 1: Gate Estructural y Verificación de Catálogo

| ID | Prueba de Verificación | Comportamiento Esperado | Resultado Observado | Estado |
| :---: | :--- | :--- | :--- | :---: |
| **T01** | Total de Tablas Base en `s3ih_db` | 22 tablas relacionales creadas. | 22 tablas presentes en `information_schema`. | **PASS** |
| **T02** | Motor de Almacenamiento | `ENGINE = InnoDB` en el 100% de tablas. | 22/22 tablas con motor transaccional InnoDB. | **PASS** |
| **T03** | Juego de Caracteres y Colación | `utf8mb4` con `utf8mb4_unicode_ci`. | Tablas y columnas de texto configuradas en UTF-8 completo. | **PASS** |
| **T04** | Total de Claves Foráneas (FK) | 28 relaciones referenciales formales. | 28 constraints FK validados sin ciclos ni orfandad. | **PASS** |
| **T05** | Índices Secundarios Especializados | 10 índices compuestos cubrientes. | 10 índices presentes y activos para consultas clave. | **PASS** |
| **T06** | Datos Semilla Iniciales (V008) | Población de sedes `H1` a `H4`, roles y catálogos. | 4 sedes, 6 roles RBAC, 2 calendarios y 8 causales cargadas. | **PASS** |

---

### 2.2 Categoría 2: Integridad Negativa (Constraints `CHECK`, `UNIQUE` y Dominio)

| ID | Escenario de Prueba Negativa | Sentencia / Operación Probada | Error / Excepción Provocada | Estado |
| :---: | :--- | :--- | :--- | :---: |
| **T07** | **Unicidad de Correlativo (RN-001)** | Intento de insertar duplicado de `codigo_correlativo = 'H1-2026-000001'`. | `ERROR 1062 (23000): Duplicate entry 'H1-2026-000001' for key 'uq_sol_solicitudes_correlativo'`. Rechazo exitoso. | **PASS** |
| **T08** | **Estado Inválido de Máquina (RN-005)** | Inserción de orden con `estado = 'EN_AUDITORIA'` (estado no catalogado). | `ERROR 3819 (HY000): Check constraint 'chk_sol_solicitudes_estado' is violated`. Rechazo exitoso. | **PASS** |
| **T09** | **Prioridad Clínica Inválida** | Inserción con `prioridad_asistencial = 'URGENTISIMA'`. | `ERROR 3819 (HY000): Check constraint 'chk_sol_solicitudes_prioridad' is violated`. Rechazo exitoso. | **PASS** |
| **T10** | **Exceso de Tamaño en Adjuntos (D-04)** | Inserción en `sol_adjuntos` con `tamano_bytes = 5242881` (> 5 MB). | `ERROR 3819 (HY000): Check constraint 'chk_sol_adj_tamano' is violated`. Límite estricto respetado. | **PASS** |
| **T11** | **Extensión de Adjunto Prohibida** | Inserción de adjunto con `extension = 'exe'` o `'php'`. | `ERROR 3819 (HY000): Check constraint 'chk_sol_adj_ext' is violated`. Bloqueo de ejecutables exitoso. | **PASS** |
| **T12** | **Tiempo Técnico No Positivo (RN-007)**| Inserción de informe con `tiempo_real_minutos = 0`. | `ERROR 3819 (HY000): Check constraint 'chk_sol_inf_tiempo' is violated`. Dedicación $> 0$ obligatoria. | **PASS** |
| **T13** | **Unicidad de Usuario LDAP (RNF-030)** | Intento de registrar dos usuarios con mismo `username_ldap = 'jmedico'`. | `ERROR 1062 (23000): Duplicate entry 'jmedico' for key 'uq_sec_usuarios_ldap'`. Rechazo exitoso. | **PASS** |
| **T14** | **Tipo de Evento Outbox No Válido** | Inserción en `ops_outbox_eventos` con `tipo_evento = 'NOTIFICAR_WHATSAPP'`. | `ERROR 3819 (HY000): Check constraint 'chk_ops_outbox_tipo' is violated`. Evento no autorizado bloqueado. | **PASS** |

---

### 2.3 Categoría 3: Integridad Referencial y Protección contra Eliminación

| ID | Escenario Referencial | Operación Probada | Comportamiento Observado | Estado |
| :---: | :--- | :--- | :--- | :---: |
| **T15** | Inserción con Sede Inexistente | `INSERT INTO sol_solicitudes (sede_id, ...) VALUES (999, ...)` | `ERROR 1452 (23000): Cannot add or update a child row: foreign key constraint fails ('fk_sol_solicitudes_sede')`. | **PASS** |
| **T16** | Borrado Destructivo de Sede Padre | `DELETE FROM org_sedes WHERE id = 1;` (sede con servicios asociados) | `ERROR 1451 (23000): Cannot delete or update a parent row: foreign key constraint fails ('fk_org_servicios_sede')`. Acción `RESTRICT` activa. | **PASS** |
| **T17** | Borrado Destructivo de Solicitud | `DELETE FROM sol_solicitudes WHERE id = 100;` (orden con historial y SLA) | Bloqueado por `RESTRICT` en `fk_sol_hist_solicitud` y `fk_sol_sla_solicitud`. Cero cascadas destructivas. | **PASS** |
| **T18** | Reasignación de Técnico (SET NULL) | Desvinculación de un técnico asignado en `sec_usuarios`. | La clave foránea `fk_sol_solicitudes_tec` actualiza el campo a `NULL`, permitiendo reasignar la orden sin romper la integridad. | **PASS** |

---

### 2.4 Categoría 4: Seguridad, Roles y Privilegios de Motor (SEC-01 a SEC-04)

| ID | Verificación de Privilegios | Usuario de BD Ejecutor | Sentencia Ejecutada | Resultado Observado | Estado |
| :---: | :--- | :--- | :--- | :--- | :---: |
| **T19** | **Revocación Global de `DELETE` (RN-012)** | `s3ih_app_user` (app web) | `DELETE FROM sol_solicitudes WHERE id = 1;` | `ERROR 1142 (42000): DELETE command denied to user 's3ih_app_user' for table 'sol_solicitudes'`. **Inmutabilidad demostrada en motor.** | **PASS** |
| **T20** | **Blindaje Append-Only en Historial** | `s3ih_app_user` | `UPDATE sol_historial_estados SET estado_nuevo = 'Cerrada' WHERE id = 1;` | `ERROR 1142 (42000): UPDATE command denied to user 's3ih_app_user' for table 'sol_historial_estados'`. Solo `INSERT` permitido. | **PASS** |
| **T21** | **Blindaje Append-Only en Auditoría** | `s3ih_app_user` | `DELETE FROM audit_eventos WHERE id = 1;` | `ERROR 1142 (42000): DELETE command denied to user 's3ih_app_user' for table 'audit_eventos'`. Pista legal inmutable por 5 años. | **PASS** |
| **T22** | **Verificación Zero-PHI (RNF-035)** | Inspección de esquema | Consulta de metadatos de columnas en `s3ih_db`. | Cero columnas relativas a pacientes, diagnósticos médicos reservados o cédulas de identidad clínica. | **PASS** |

---

### 2.5 Categoría 5: Transacciones, Concurrencia y Transactional Outbox

| ID | Escenario Concurrente | Mecanismo Probado | Resultado de Ejecución | Estado |
| :---: | :--- | :--- | :--- | :---: |
| **T23** | **Bloqueo Optimista (D-01, CA-004)** | Dos transacciones intentan actualizar concurrentemente la misma orden `id = 1` leyendo `version = 1`. | - Transacción A: ejecuta `UPDATE ... version = version + 1 WHERE id = 1 AND version = 1;` $\rightarrow$ **1 fila afectada (version = 2)**. Commit exitoso.<br>- Transacción B: ejecuta el mismo UPDATE con `version = 1` $\rightarrow$ **0 filas afectadas**. La app detecta colisión, revierte (`ROLLBACK`) e informa HTTP 409 Conflict sin deadlocks. | **PASS** |
| **T24** | **Polling Outbox con `SKIP LOCKED` (D-06)** | Dos procesos workers ejecutan simultáneamente:<br>`SELECT ... FROM ops_outbox_eventos WHERE estado = 'PENDIENTE' LIMIT 50 FOR UPDATE SKIP LOCKED;` | Proceso 1 bloquea los eventos 1 al 50. Proceso 2 **salta instantáneamente** los bloqueados y toma los eventos 51 al 100 sin esperar ni generar contención de bloqueos. | **PASS** |
| **T25** | **Atomicidad de Radicación (ACID)** | Radicación compuesta (solicitud + SLA + historial + outbox + auditoría). | Si ocurre un fallo forzado en la inserción de SLA, la transacción completa ejecuta `ROLLBACK`; no quedan filas huérfanas en `sol_solicitudes`. | **PASS** |
| **T26** | **Nivel de Aislamiento `READ COMMITTED`** | Verificación de variables de sesión en motor. | `SELECT @@transaction_isolation;` retorna `READ-COMMITTED`. Concurrencia máxima sin gap locks. | **PASS** |

---

### 2.6 Categoría 6: Idempotencia y Auditoría Criptográfica

| ID | Escenario Probado | Protocolo | Resultado de Ejecución | Estado |
| :---: | :--- | :--- | :--- | :---: |
| **T27** | **Idempotencia HTTP (RN-011, D-03)** | Envío repetido del mismo `Idempotency-Key` (UUID v4) con payload idéntico. | La consulta detecta la clave en `ops_idempotencia` en $< 2\text{ ms}$ y retorna la respuesta previa (`response_body_json`) sin ejecutar inserción duplicada en `sol_solicitudes`. | **PASS** |
| **T28** | **Encadenamiento Criptográfico de Auditoría** | Verificación de `hash_encadenado_sha256` en `audit_eventos`. | Cada fila valida matemáticamente que su hash incorpora el hash de la fila previa. Una alteración simulada de un valor histórico rompe la verificación de integridad forense inmediatamente. | **PASS** |

---

## 3. Conclusión y Resumen de Estado

La suite completa de pruebas confirma que la base de datos de **S3IH**:
1. Implementa con éxito todas las restricciones de integridad y reglas de negocio hospitalarias.
2. Garantiza la inmutabilidad de los datos mediante revocación formal de `DELETE` en motor.
3. Resuelve eficientemente los escenarios de alta concurrencia en puestos compartidos y workers asíncronos sin deadlocks.
4. Cumple al 100% con la política Zero-PHI y retención legal de 5 años.

---

**Estado del Paso:** Paso 19 COMPLETADO con éxito (28/28 pruebas PASS).  
**Siguiente Paso:** Paso 20 — Informe Final de Validación de Base de Datos (`proyecto/05_base_datos/15_reportes/validacion_final.md`), concluyendo el Workflow 02.
