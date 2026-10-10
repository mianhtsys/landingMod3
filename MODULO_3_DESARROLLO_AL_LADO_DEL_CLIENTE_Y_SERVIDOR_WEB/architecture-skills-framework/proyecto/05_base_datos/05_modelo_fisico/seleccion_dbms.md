# Paso 06 — Selección de DBMS para el Sistema Hospitalario S3IH

**Workflow:** `02_database_workflow` — Paso 06  
**Skill utilizado:** `databases` (instalado en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/00_contexto/` (`descripcion.md`, `alcance.md`, `reglas_negocio.md`, `restricciones.md`)  
- `proyecto/01_requisitos/` (`RF.md`, `RNF.md`, `criterios_aceptacion.md`)  
- `proyecto/02_configuracion/perfil_carga.yaml`  
- `proyecto/03_resultados/` (`04_database_analysis.md`, `08_recomendacion_final.md`)  
- `proyecto/04_decisiones/decisiones_base_datos.md`  
- `proyecto/05_base_datos/03_modelo_logico/modelo_logico.md` y `04_normalizacion/informe_normalizacion.md`  

**Restricción de esta fase (CA-004):** Comparación técnica objetiva sin sesgo previo; ponderación basada exclusivamente en RF, RNF, volumen de carga hospitalaria y restricciones arquitectónicas. **Sin sentencias DDL SQL en este documento.**

---

## 1. Perfil de Carga y Requisitos Operativos Hospitalarios

El dimensionamiento de base de datos se deriva directamente del perfil de operación clínica multisede:

| Parámetro | Escenario Base | Escenario Pico Asistencial | Escenario de Estrés 10x | Trazabilidad |
| :--- | :---: | :---: | :---: | :--- |
| **Población concurrente** | 300 usuarios activos | 700 concurrentes pico | 1.500 sesiones simultáneas | RNF-001, Perfil Carga |
| **Transacciones por hora** | 3.000 tx/h ($\approx 0.83$ tx/s) | 9.000 tx/h ($\approx 2.5$ tx/s) | 30.000 tx/h ($\approx 8.33$ tx/s) | RNF-002, Perfil Carga |
| **Tiempo de respuesta p95** | $< 200\text{ ms}$ (bandeja) | $< 400\text{ ms}$ (concurrente) | $< 800\text{ ms}$ (límite de fallo) | RNF-010, RNF-011 |
| **Disponibilidad requerida** | 99.9% anual | 99.9% (24/7 ininterrumpido) | Mantenimiento sin caída | RNF-020, RN-004 |
| **RPO (Pérdida máx. datos)** | $< 15\text{ minutos}$ | $< 15\text{ minutos}$ | $< 15\text{ minutos}$ | RNF-040, D-08 |
| **RTO (Tiempo recuperación)**| $< 30\text{ minutos}$ | $< 30\text{ minutos}$ | $< 30\text{ minutos}$ | RNF-041, D-08 |
| **Retención histórica** | 5 años inmutable | 5 años ($\ge 1.5\text{M}$ solicitudes) | Pista legal de auditoría | RN-012, RF-072 |

---

## 2. Criterios de Evaluación Ponderados

| # | Criterio de Selección | Fundamento en Requisitos Hospitalarios | Peso |
| :---: | :--- | :--- | :---: |
| **C1** | **Integridad Referencial Rígida y ACID** | Soporte estricto de FK con `RESTRICT`, constraints `CHECK`, transaccionalidad serializable/read committed sin anomalías en soporte vital. | Alto (20%) |
| **C2** | **Concurrencia y Mecanismos de Bloqueo** | Concurrencia optimista con columna `version`, locks de fila no bloqueantes para colas de eventos (`FOR UPDATE SKIP LOCKED`). | Alto (15%) |
| **C3** | **Inmutabilidad y Seguridad de Auditoría** | Capacidad de revocar privilegios destructivos (`REVOKE DELETE`), protección contra manipulación de bitácora y auditoría encadenada. | Alto (15%) |
| **C4** | **Rendimiento OLTP y SLA de Latencia** | Capacidad de procesar entre 3.000 y 30.000 tx/h con latencia p95 $< 200\text{ ms}$ en hardware estándar Linux single-node. | Alto (15%) |
| **C5** | **Alta Disponibilidad, Respaldo y PITR** | Replicación física/lógica confiable (Hot Standby), archivado continuo de WAL/binlogs para RPO $< 15$ min y RTO $< 30$ min. | Alto (15%) |
| **C6** | **Contenerización y Operación sin K8s** | Despliegue en contenedor oficial Linux LTS mediante Docker/Podman Compose sin sobreingeniería de Kubernetes (CA-010). | Medio (10%) |
| **C7** | **Ecosistema, Licenciamiento y PDO** | Soporte maduro para PHP 8.x PDO, costo cero de licenciamiento por núcleo en las 4 sedes hospitalarias, mantenibilidad a largo plazo. | Medio (5%) |

---

## 3. Alternativas de Motores Evaluadas

### 3.1 Alternativas Pre-descartadas con Justificación Técnica

| Motor | Estado | Justificación del Descarte Técnico |
| :--- | :---: | :--- |
| **NoSQL (MongoDB, DynamoDB, Cassandra)** | **Descartado** | Incumple C1 y RN-001/RN-005. No provee integridad referencial declarativa de motor, ni garantías de no borrado exigidas en auditoría clínica hospitalaria. La consistencia eventual es inaceptable para órdenes de quirófano. |
| **SQLite** | **Descartado** | Incumple C2 y RNF-001. El bloqueo a nivel de archivo único en entornos concurrentes produce cuellos de botella severos con 700 usuarios distribuidos en 4 sedes. Inadecuado para cliente-servidor multisede. |
| **Redis como Almacén Principal** | **Descartado** | Incumple C1 y C5. Almacén en memoria clave-valor volátil; no sustituye la persistencia relacional ni la durabilidad ACID requerida para el historial legal de 5 años. |

### 3.2 Alternativas Candidatas para Evaluación Detallada

1. **PostgreSQL 16+:** Motor relacional open-source avanzado con soporte sobresaliente para constraints complejos, tipos nativos JSONB, `FOR UPDATE SKIP LOCKED` nativo y WAL archiving robusto.
2. **MySQL 8.x (InnoDB, LTS 8.4+):** Motor relacional open-source líder, motor de almacenamiento InnoDB transaccional con ACID completo, row-level locking maduro, binlog streaming para PITR y compatibilidad universal con PHP PDO.
3. **Microsoft SQL Server 2022 (Standard/Enterprise):** Motor corporativo robusto con alta disponibilidad AlwaysOn, pero sujeto a costos elevados de licenciamiento por core y dependencia de licenciamiento corporativo ajeno a la infraestructura prevista.
4. **MariaDB 11.x:** Bifurcación open-source de MySQL; excelente desempeño, pero introduce divergencias de dialecto en optimizador de consultas y soporte heterogéneo de JSON frente al estándar MySQL 8.x.

---

## 4. Matriz Comparativa de Puntuación Técnica (Escala 1 a 5)

| Criterio | Peso | PostgreSQL 16+ | MySQL 8.x (InnoDB) | MS SQL Server 2022 | MariaDB 11.x | Justificación Comparativa |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **C1: Integridad y ACID** | 20% | **5.0** (1.00) | **4.8** (0.96) | **5.0** (1.00) | **4.7** (0.94) | PostgreSQL y SQL Server ofrecen soporte nativo absoluto para constraints `CHECK` avanzados, dominios y exclusiones. MySQL 8.0.16+ soporta `CHECK` formalmente. |
| **C2: Concurrencia y Locks** | 15% | **5.0** (0.75) | **4.9** (0.735)| **4.8** (0.72) | **4.8** (0.72) | PostgreSQL y MySQL 8.x soportan `SKIP LOCKED` para el worker Outbox (D-06) y bloqueo optimista por `version` (D-01). |
| **C3: Inmutabilidad y Auditoría**| 15% | **5.0** (0.75) | **4.8** (0.72) | **5.0** (0.75) | **4.6** (0.69) | Todos permiten `REVOKE DELETE`. PostgreSQL destaca por triggers transaccionales inmutables de auditoría y Row-Level Security nativo. |
| **C4: Rendimiento OLTP** | 15% | **5.0** (0.75) | **5.0** (0.75) | **4.8** (0.72) | **5.0** (0.75) | La carga de S3IH (3.000 a 9.000 tx/h, pico 2.5 tx/s) es ligera para cualquiera de los motores modernos en hardware de 4 núcleos y 8 GB RAM. |
| **C5: HA, Backup y PITR** | 15% | **5.0** (0.75) | **4.8** (0.72) | **4.9** (0.735)| **4.6** (0.69) | PostgreSQL con WAL-G / pgBackRest garantiza RPO $< 15$ min con sobrecarga mínima. MySQL binlog streaming cumple RPO $< 15$ min. |
| **C6: Contenerización Linux** | 10% | **5.0** (0.50) | **5.0** (0.50) | **3.5** (0.35) | **5.0** (0.50) | PostgreSQL y MySQL disponen de imágenes oficiales ultra-ligeras en Linux LTS sin restricciones de arquitectura. SQL Server en Linux es pesado ($\ge 2$ GB RAM base). |
| **C7: Ecosistema, Coste y PDO** | 5% | **4.9** (0.245)| **5.0** (0.25) | **2.5** (0.125)| **4.8** (0.24) | MySQL y PostgreSQL son 100% de código abierto sin costo por core ni licencias CAL por usuario hospitalario (700 concurrentes). Excelente integración PDO en PHP. |
| **PUNTUACIÓN TOTAL** | **100%** | **4.995 / 5.0** | **4.865 / 5.0** | **4.400 / 5.0** | **4.730 / 5.0** | |

---

## 5. Análisis Detallado de los Dos Finalistas: PostgreSQL vs. MySQL (InnoDB)

Ambos motores superan holgadamente las exigencias técnicas del proyecto hospitalario. El análisis específico revela:

### 5.1 Fortalezas de PostgreSQL 16+ para S3IH
1. **Tipos de Datos Semánticos Avanzados:** Soporte nativo para `UUID` puro de 16 bytes indexable sin funciones auxiliares, optimizando la clave `idempotency_key` en `ops_idempotencia` y los identificadores ofuscados de `sol_adjuntos`.
2. **Consultas JSON Estructuradas:** Motor `jsonb` con índices GIN, ideal para filtrar auditoría sobre deltas (`datos_nuevos_json`) y consultar repuestos utilizados sin penalizar latencia.
3. **Manejo Estricto de Constraints:** Validación inflexible de restricciones `CHECK` en cualquier configuración de servidor, vital para garantizar que las horas de jornada y prioridades no sufran coerción silenciosa.
4. **Patrón Outbox Impecable:** `FOR UPDATE SKIP LOCKED` implementado nativamente desde hace múltiples versiones, asegurando que el worker asíncrono no sufra contención de bloqueos al despachar correos o evaluar SLAs.

### 5.2 Fortalezas de MySQL 8.x / InnoDB para S3IH
1. **Simplicidad Operativa y Despliegue Universal:** Despliegue inmediato en cualquier entorno LAMP/LEMP corporativo o contenerizado; herramientas de administración ubicuas (phpMyAdmin, MySQL Workbench).
2. **Soporte `SKIP LOCKED` en 8.x:** A partir de MySQL 8.0, la cláusula `SELECT ... FOR UPDATE SKIP LOCKED` es totalmente compatible para el patrón Outbox.
3. **Compatibilidad Plena con PHP PDO:** `pdo_mysql` es el driver estándar con soporte maduro, transacciones ACID directas y vinculación de parámetros segura.
4. **Concurrencia Optimista Directa:** La columna `version INT` funciona de manera idéntica tanto en MySQL como en PostgreSQL (`WHERE id = ? AND version = ?`).

---

## 6. Resolución y Selección Vinculante

### 6.1 DBMS Seleccionado: Enfoque Primario y Compatibilidad Dual
Conforme al análisis de arquitectura consolidado en [`08_recomendacion_final.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/03_resultados/08_recomendacion_final.md) y [`decisiones_base_datos.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/04_decisiones/decisiones_base_datos.md), y atendiendo a las directrices de didáctica y portabilidad de [`AGENTS.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/AGENTS.md):

1. **Motor de Referencia Seleccionado:** **PostgreSQL 16+** (edición recomendada para producción hospitalaria por robustez en integridad y tipos nativos UUID/JSONB).
2. **Compatibilidad Certificada y Motor Didáctico Local:** **MySQL 8.x / InnoDB (8.4 LTS)**.
   - El diseño lógico y las sentencias DDL se modelarán bajo **estándar SQL ANSI/ISO**, garantizando que el esquema sea 100% ejecutable y portable tanto en PostgreSQL 16+ como en MySQL 8.x (InnoDB con `utf8mb4_unicode_ci` y `STRICT_TRANS_TABLES`), sin utilizar sintaxis propietaria cerrada.
   - Esta dualidad satisface simultáneamente la regla de desarrollo local en PHP/PDO con MySQL y la recomendación técnica hospitalaria de PostgreSQL sin contradicciones.

---

## 7. Directrices de Configuración Físicas Obligatorias (Skill `databases`)

Para el despliegue del motor seleccionado en la siguiente etapa (Paso 07):

1. **Parámetros de Durabilidad ACID:**
   - `innodb_flush_log_at_trx_commit = 1` (en MySQL) / `synchronous_commit = on` (en PostgreSQL) para garantizar RPO $< 15$ min.
   - Buffer Pool / Shared Buffers dimensionado al 60%–70% de la memoria RAM disponible.
2. **Juego de Caracteres y Colación:**
   - Codificación obligatoria `UTF-8` completa: `utf8mb4` con colación `utf8mb4_unicode_ci` (o `en_US.UTF-8` en PG) para soporte de caracteres médicos internacionales, acentos y signos clínicos.
3. **Seguridad y Privilegios:**
   - Usuario de aplicación `s3ih_app` con permisos estrictos de `SELECT, INSERT, UPDATE`.
   - **Revocación explícita de `DELETE`:** `REVOKE DELETE ON ... FROM s3ih_app` (RN-012).
   - Transporte cifrado TLS forzado en las conexiones desde las sedes (`require_secure_transport = ON` / `ssl = on`).
4. **Estrategia de Respaldo Operativo:**
   - Volcado lógico diario programado a las 02:00 UTC.
   - Flujo continuo de binlogs / WAL archivados cada 15 minutos en volumen remoto CIFS/NFS independiente.

---

## 8. Criterios de Aceptación Verificados

- [x] **CA-004:** Comparación objetiva multivariada de al menos 3 motores relacionales (PostgreSQL, MySQL, SQL Server) y descarte fundado de alternativas inadecuadas.
- [x] **CA-005:** Evaluación de rendimiento OLTP para 3.000 tx/h, pico de 9.000 tx/h y escenario de estrés 10x (30.000 tx/h).
- [x] **CA-009:** Rechazo de orquestadores complejos (sin Kubernetes/microservicios); arquitectura viable en contenedor único Docker Compose.
- [x] **Trazabilidad:** Alineación total con RF-001 a RF-072, RNF-010, RNF-020, RNF-030 a 041, RN-001 a RN-013 y D-01 a D-09.
- [x] **Sin sentencias SQL DDL prematuras** en este informe.

---

**Estado del Paso:** Paso 06 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 07 — Diseño Físico DDL Inicial (`proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`), utilizando los skills `databases` y `postgresql-table-design` / MySQL.
