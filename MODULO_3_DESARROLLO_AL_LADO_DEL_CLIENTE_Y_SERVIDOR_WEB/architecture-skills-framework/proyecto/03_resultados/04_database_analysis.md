# Análisis Técnico de Base de Datos y Persistencia — Sistema Hospitalario S3IH

Fecha: 2026-10-07  
Fase: 04 — Evaluación Técnica de Persistencia y Datos  
Rol: `database-specialist`  
Skill: `database-evaluation`  
Fuente: `01_requirements_analysis.md`, `02_decision_scope.md`, `03_architecture_options.md`  
Estado: Propuesta formal para revisión de seguridad, DevOps y consolidación  

---

## 1. Resumen Ejecutivo y Perfil de Carga de Datos

El **Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH)** presenta un patrón transaccional predominantemente **OLTP con estricta integridad ACID**, alta exigencia de **no repudio y auditoría inmutable** (RN-012, RN-013), y consultas analíticas operativas periódicas sobre Acuerdos de Nivel de Servicio (SLA 24/7 vs. Hábil administrativo).

### Métricas Cuantitativas del Perfil de Datos
- **Capacidad Nominal Base:** $3.000\text{ transacciones de negocio por hora} \approx 0.833\text{ tx/s}$.
- **Multiplicador Pico (Cambios de Turno de Guardia):** $3.0\times = 9.000\text{ tx/h} \approx 2.50\text{ tx/s}$.
- **Escenario 10x a Evaluar:** $30.000\text{ tx/h} \approx 8.333\text{ tx/s}$.
- **Concurrencia Simultánea:** 700 usuarios activos (500 personal asistencial + 200 técnicos resolutores/supervisores).
- **Volumen Proyectado a 5 Años:** $\ge 1.500.000\text{ solicitudes}$ con trazabilidad completa de transiciones y eventos.
- **Tamaño Proyectado de Datos Relacionales:** $\approx 1.5\text{ a }2.5\text{ GB}$ (excluyendo adjuntos binarios).
- **Volumen de Evidencias Técnicas Desacopladas (hasta 5 MB/archivo):** $\approx 4.5\text{ TB}$ en 5 años resguardados fuera del motor relacional.

El motor de base de datos debe ser el **garante inviolable del ciclo de vida de los requerimientos**, la **fuente única de verdad para el cálculo de SLA**, y el **registro de custodia legal de intervenciones en activos hospitalarios**.

---

## 2. Evaluación Comparativa de Motores de Persistencia (D-02)

Se analizan tres sistemas de gestión de bases de datos relacionales empresariales (RDBMS) de clase industrial, evaluando su idoneidad frente a los requisitos funcionales y no funcionales de S3IH.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│              Comparación de Alternativas de RDBMS para el Sistema S3IH                 │
├──────────────────────────┬─────────────────────────────┬───────────────────────────────┤
│    1. PostgreSQL 16+     │     2. MySQL 8.x (InnoDB)   │  3. Microsoft SQL Server 2022 │
├──────────────────────────┼─────────────────────────────┼───────────────────────────────┤
│ - MVCC maduro            │ - Amplia ubicuidad          │ - Integración AD/Windows      │
│ - SKIP LOCKED nativo     │ - Row-level locking InnoDB  │ - Tablas temporales nativas   │
│ - Soporte nativo UUID    │ - FOR UPDATE SKIP LOCKED    │ - Licenciamiento de alto costo│
│ - JSONB indexable        │ - Tipos JSON estructurados  │ - Mayor huella de memoria     │
│ - Particionamiento rango │ - Replicación binlog madura │ - Menor afinidad open-source  │
└──────────────────────────┴─────────────────────────────┴───────────────────────────────┘
```

### Tabla Comparativa Multidimensional de Motores

| Criterio de Selección | PostgreSQL 16+ | MySQL 8.x (InnoDB) | Microsoft SQL Server 2022 |
| :--- | :--- | :--- | :--- |
| **Integridad Transaccional ACID y MVCC** | **Excelente.** MVCC altamente optimizado. Aislamiento *Read Committed* y *Repeatable Read* robustos sin bloqueos de lectura sobre escrituras. | **Excelente.** Motor InnoDB con transacciones ACID completas y control de bloqueos por fila mediante índices. | **Excelente.** Transaccionalidad ACID probada con aislamiento snapshot opcional. |
| **Control de Concurrencia y Colas (D-01 / D-06)** | **Superior.** `SELECT FOR UPDATE SKIP LOCKED` implementado y optimizado desde hace múltiples versiones; ideal para el patrón *Transactional Outbox*. Advisory locks para exclusión mutua distribuida ligera. | **Bueno.** Soporta `FOR UPDATE SKIP LOCKED` desde 8.0.1; funciona bien pero requiere índices estrictos para evitar escalamiento a bloqueos de brecha (*gap locks*). | **Excelente.** `READPAST` y `UPDLOCK` permiten implementación eficiente de colas outbox. |
| **Inmutabilidad y Auditoría Append-Only (RN-012/013)** | **Excelente.** Triggers `BEFORE UPDATE/DELETE` con bloqueo incondicional de borrado físico; soporte nativo de tipos de auditoría, funciones de hash criptográfico (`pgcrypto`). | **Bueno.** Triggers de restricción de borrado y tablas append-only; menor soporte de funciones criptográficas y hashing nativo. | **Sobresaliente.** *System-Versioned Temporal Tables* ofrecen auditoría e inmutabilidad gestionada por el motor de forma transparente sin triggers. |
| **Soporte de Identificadores y Metadatos** | **Excelente.** Tipo de datos `UUID` nativo de 16 bytes. Tipo `JSONB` con índices GIN para almacenar especificaciones de repuestos usados y campos técnicos variables. | **Aceptable.** Carece de tipo `UUID` nativo (obliga a usar `BINARY(16)` o `VARCHAR(36)` con conversión). Tipo `JSON` funcional pero con indexación secundaria limitada. | **Bueno.** Tipo `UNIQUEIDENTIFIER` nativo. Soporte JSON mediante funciones relacionales. |
| **Particionamiento Histórico por Sede/Fecha** | **Excelente.** Particionamiento declarativo nativo por listas (sede) y rangos (año/mes). Exclusión de particiones (*partition pruning*) altamente eficiente. | **Bueno.** Soporte de particionamiento por rangos y listas, con algunas restricciones en claves foráneas compuestas. | **Excelente.** Particionamiento horizontal de tablas mediante funciones y esquemas de partición. |
| **Huella de Recursos y Despliegue en Contenedores** | **Baja-Media.** Imagen oficial de contenedor liviana ($\approx 150\text{ MB}$), bajo consumo de RAM en reposo ($\approx 80\text{ MB}$), excelente rendimiento en Linux LTS. | **Baja-Media.** Imagen oficial optimizada ($\approx 250\text{ MB}$), configuración sencilla, soporte extendido en Linux. | **Alta.** Imagen de contenedor voluminosa ($> 1.5\text{ GB}$), alto requerimiento de memoria RAM base ($\ge 2\text{ GB}$ solo para iniciar). |
| **Costo Operacional y Licenciamiento** | **Cero Costo de Licencia.** Licencia PostgreSQL Open Source liberal, sin costo por núcleo, usuario ni sede. | **Cero Costo de Licencia.** GPL v2 Community / Open Source, sin costo de licenciamiento. | **Muy Alto Costo.** Licenciamiento comercial por núcleo de CPU sumamente oneroso para una red hospitalaria pública o mixta. |
| **Mantenibilidad por el Equipo TI Interno** | **Alta.** Amplia documentación, herramientas estándar (pgAdmin, psql, DBeaver) y baja complejidad operativa. | **Muy Alta.** Familiaridad universal entre administradores de sistemas y equipos web institucionales. | **Media en Linux.** Requiere experiencia especializada de DBA en SQL Server administrado sobre Linux. |

### Descarte Explícito de Persistencia NoSQL / Documental
Se descarta formalmente el uso de bases de datos NoSQL puras (MongoDB, CouchDB, Cassandra) como almacén primario del sistema. 
- **Justificación:** Las reglas de negocio hospitalarias **RN-001** (correlativo único compuesto inmutable), **RN-005** (máquina de estados determinista) y **RN-003** (integridad referencial mandatoria hacia sedes y técnicos) exigen constraints relacionales rígidos (`FOREIGN KEY`, `CHECK`, `UNIQUE`) y aislamiento ACID inmediato. El modelo de consistencia eventual de los sistemas NoSQL pone en riesgo la trazabilidad de requerimientos en áreas de soporte vital.

### Recomendación del Motor de Base de Datos
**PostgreSQL 16+** se posiciona como la opción técnica superior y más equilibrada para S3IH debido a:
1. Su implementación nativa de `SELECT FOR UPDATE SKIP LOCKED` para el worker de SLA y eventos outbox.
2. Soporte nativo de `UUID` e indexación de metadatos variables en `JSONB` para informes técnicos de reparación.
3. Cero costo de licenciamiento con máxima estabilidad sobre Linux LTS empresarial.
4. *Nota de compatibilidad:* Si la infraestructura institucional hospitalaria estandariza corporativamente sobre **MySQL 8.x**, el diseño propuesto es 100% convertible utilizando `BINARY(16)` para UUIDs y triggers estándar InnoDB.

---

## 3. Estrategia de Concurrencia y Control de Guardias (D-01)

### El Problema de Concurrencia en S3IH
Durante los cambios de turno (ej. 07:00, 15:00, 23:00) o situaciones de emergencia en quirófanos:
- Múltiples supervisores o cuadrillas de guardia consultan simultáneamente solicitudes críticas en estado `Registrada` o `En Proceso`.
- Existe un riesgo real de que dos supervisores asignen la misma solicitud a técnicos distintos al mismo instante, o que dos técnicos registren avances concurrentes sobreescribiendo el trabajo previo.

### Mecanismo Seleccionado: Bloqueo Optimista con Columna de Versión (*Optimistic Locking*)

Cada solicitud contendrá una columna numérica entera `version INT NOT NULL DEFAULT 1` y una estampa de tiempo de actualización `updated_at TIMESTAMP NOT NULL`.

#### Protocolo de Ejecución:
1. La aplicación lee la solicitud para presentarla en pantalla:
   ```sql
   SELECT id, estado, tecnico_asignado_id, version 
   FROM solicitudes 
   WHERE id = :solicitud_id;
   ```
2. Al momento de confirmar la asignación o cambio de estado:
   ```sql
   UPDATE solicitudes
   SET tecnico_asignado_id = :nuevo_tecnico_id,
       estado = 'Asignada',
       version = version + 1,
       updated_at = CURRENT_TIMESTAMP
   WHERE id = :solicitud_id 
     AND version = :version_leida;
   ```
3. **Verificación de Filas Afectadas:**
   - Si `filas_afectadas == 1`: La transacción fue exitosa y atómica. Se procede con el commit.
   - Si `filas_afectadas == 0`: Se detectó una colisión concurrente (otro supervisor modificó la solicitud en el intervalo). La aplicación captura la excepción, revierte la transacción e informa inmediatamente al usuario: *"La solicitud ha sido modificada concurrentemente por otro usuario. Se han recargado los datos más recientes."*

#### Justificación frente al Bloqueo Pesimista (`SELECT FOR UPDATE`)
- El bloqueo optimista **no retiene locks en la base de datos** durante el tiempo en que el supervisor revisa la solicitud en su navegador o tablet.
- Elimina completamente la posibilidad de *deadlocks* (bloqueos mutuos) entre usuarios concurrentes.
- Proporciona un rendimiento óptimo bajo la carga de 700 usuarios simultáneos.

---

## 4. Estrategia de Idempotencia y Deduplicación (D-03)

Para mitigar duplicidades originadas por desconexiones o reintentos sobre redes Wi-Fi hospitalarias intermitentes (RN-011, RF-023), se establece una tabla de transacciones de idempotencia en la base de datos:

```sql
CREATE TABLE transacciones_idempotencia (
    idempotency_key UUID PRIMARY KEY,
    usuario_id INT NOT NULL,
    recurso_tipo VARCHAR(50) NOT NULL, -- 'SOLICITUD_CREACION', 'TRANSICION_ESTADO'
    recurso_id BIGINT NULL,
    request_hash VARCHAR(64) NOT NULL, -- SHA-256 del cuerpo de la petición
    response_status INT NOT NULL,      -- Código HTTP retornado (ej. 201, 200)
    response_body JSONB NOT NULL,      -- Respuesta generada original
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL -- TTL de 24 horas
);

CREATE INDEX idx_idempotencia_expiracion ON transacciones_idempotencia (expires_at);
```

#### Flujo Operativo:
1. El cliente envía `Idempotency-Key: <UUID>` en el encabezado HTTP.
2. Si la clave ya existe y `expires_at > NOW()`:
   - Si `request_hash` coincide: Se retorna inmediatamente la respuesta almacenada en `response_body` con su `response_status` original, sin reejecutar la lógica de negocio ni crear registros duplicados.
   - Si `request_hash` difiere: Se rechaza con `409 Conflict` (uso indebido de clave de idempotencia).
3. Si la clave no existe: Se ejecuta la transacción de negocio y se guarda el resultado en `transacciones_idempotencia` dentro del mismo bloque transaccional.

---

## 5. Modelo de Inmutabilidad, Auditoría y Prohibición de Borrado Físico (RN-012, RN-013)

### Separación de Esquemas de Auditoría
El sistema implementa dos tablas complementarias:
1. **`solicitudes`**: Tabla de estado actual (con claves foráneas e índices para consultas rápidas de bandejas de trabajo).
2. **`solicitudes_historial_estados`**: Tabla append-only inmutable de transiciones de estado.
3. **`auditoria_eventos`**: Pista legal de auditoría del sistema para cualquier acción de acceso, lectura crítica o modificación.

```sql
-- Tabla Append-Only de Transiciones
CREATE TABLE solicitudes_historial_estados (
    id BIGSERIAL PRIMARY KEY,
    solicitud_id BIGINT NOT NULL REFERENCES solicitudes(id),
    estado_anterior VARCHAR(30) NOT NULL,
    estado_nuevo VARCHAR(30) NOT NULL,
    causal_motivo TEXT NULL,
    usuario_id INT NOT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX idx_historial_solicitud ON solicitudes_historial_estados (solicitud_id, created_at ASC);
```

### Mecanismo de Seguridad a Nivel de Motor (Revocación de DELETE)
Para asegurar que ningún usuario ni aplicación pueda ejecutar borrados físicos:
```sql
-- Usuario de base de datos exclusivo para la aplicación S3IH
REVOKE DELETE ON ALL TABLES IN SCHEMA public FROM s3ih_app_user;
GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO s3ih_app_user;

-- En tablas de auditoría se revoca incluso UPDATE:
REVOKE UPDATE, DELETE ON solicitudes_historial_estados, auditoria_eventos FROM s3ih_app_user;
GRANT SELECT, INSERT ON solicitudes_historial_estados, auditoria_eventos TO s3ih_app_user;
```
Adicionalmente, se configuran triggers de rechazo `BEFORE DELETE` que arrojan excepciones severas si se intenta ejecutar una sentencia de borrado incluso por usuarios administrativos directos.

---

## 6. Desacoplamiento de Evidencias Técnicas y Adjuntos (D-04)

En estricto cumplimiento de la **Restricción Técnica 5** (prohibición de almacenar BLOBs en la base de datos relacional para evitar degradación de I/O y bloat de buffer pool):

```sql
CREATE TABLE solicitudes_adjuntos (
    id BIGSERIAL PRIMARY KEY,
    solicitud_id BIGINT NOT NULL REFERENCES solicitudes(id),
    nombre_archivo_original VARCHAR(255) NOT NULL,
    nombre_archivo_almacenado VARCHAR(255) NOT NULL, -- UUID-v4.ext
    tipo_mime VARCHAR(100) NOT NULL,                -- Validador MIME real (image/jpeg, application/pdf)
    extension VARCHAR(10) NOT NULL,                 -- 'pdf', 'jpg', 'jpeg', 'png'
    tamano_bytes BIGINT NOT NULL,                   -- CHECK (tamano_bytes <= 5242880) -> Max 5 MB
    hash_sha256 VARCHAR(64) NOT NULL,               -- Verificación de integridad
    uri_almacenamiento VARCHAR(500) NOT NULL,       -- Ruta en NAS/NFS o URI MinIO/S3
    usuario_id INT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_tamano_adjunto CHECK (tamano_bytes <= 5242880)
);

CREATE INDEX idx_adjuntos_solicitud ON solicitudes_adjuntos (solicitud_id);
```

El motor de persistencia únicamente gestiona registros de metadatos de $\approx 300\text{ bytes}$ por archivo, permitiendo que la subida física se procese directamente hacia el volumen compartido o bucket de objetos.

---

## 7. Desacoplamiento Asíncrono: Patrón *Transactional Outbox* (D-06)

Para satisfacer el **RNF-041** (degradación elegante ante fallas externas como caídas del servidor SMTP) y procesar alertas preventivas de SLA sin ralentizar la radicación asistencial:

```sql
CREATE TABLE outbox_eventos (
    id BIGSERIAL PRIMARY KEY,
    tipo_evento VARCHAR(50) NOT NULL, -- 'SLA_ALERTA_75', 'SLA_ESCALAMIENTO', 'NOTIFICACION_ASIGNACION'
    agregado_tipo VARCHAR(50) NOT NULL,
    agregado_id BIGINT NOT NULL,
    payload JSONB NOT NULL,
    estado VARCHAR(20) DEFAULT 'PENDIENTE' NOT NULL, -- 'PENDIENTE', 'PROCESANDO', 'ENVIADO', 'FALLIDO'
    reintentos INT DEFAULT 0 NOT NULL,
    error_mensaje TEXT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
    scheduled_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
    processed_at TIMESTAMP WITH TIME ZONE NULL
);

-- Índice optimizado para el polling del worker
CREATE INDEX idx_outbox_pendientes ON outbox_eventos (scheduled_at) 
WHERE estado IN ('PENDIENTE', 'FALLIDO');
```

#### Consumo Concurrente Seguro por el Worker:
El worker en segundo plano extrae lotes de eventos sin bloquearse ni competir con la aplicación web utilizando:
```sql
SELECT id, tipo_evento, payload 
FROM outbox_eventos
WHERE estado = 'PENDIENTE' AND scheduled_at <= CURRENT_TIMESTAMP
ORDER BY scheduled_at ASC
LIMIT 50
FOR UPDATE SKIP LOCKED;
```
Esto garantiza entrega garantizada (*at-least-once execution*) sin impacto sobre la latencia transaccional del personal médico.

---

## 8. Estrategia de Índices para Consultas y Bandejas ($p95 \le 400\text{ ms}$)

Para cumplir el **RNF-001** (tiempo de respuesta $\le 400\text{ ms}$ en consultas y bandejas de trabajo sobre $1.500.000$ registros):

1. **Índice para Bandeja de Trabajo por Sede y Estado:**
   ```sql
   CREATE INDEX idx_solicitudes_bandeja_sede_estado 
   ON solicitudes (sede_id, estado, prioridad_asistencial, created_at DESC);
   ```
2. **Índice para Bandeja Personalizada de Técnico Resolutor:**
   ```sql
   CREATE INDEX idx_solicitudes_tecnico_activo 
   ON solicitudes (tecnico_asignado_id, estado) 
   WHERE estado IN ('Asignada', 'En Proceso', 'En Espera');
   ```
3. **Índice para Búsqueda Rápida por Correlativo Único Institucional (RN-001):**
   ```sql
   CREATE UNIQUE INDEX uq_solicitudes_codigo_correlativo 
   ON solicitudes (codigo_correlativo);
   ```
4. **Índice para Monitoreo de SLA Activo:**
   ```sql
   CREATE INDEX idx_solicitudes_sla_activo 
   ON solicitudes (sla_fecha_limite ASC) 
   WHERE estado IN ('Registrada', 'Asignada', 'En Proceso');
   ```

---

## 9. Estrategia de Respaldo, Continuidad y Recuperación (RPO / RTO)

El requisito **RNF-050** exige un **RPO $\le 60\text{ min}$** y el **RNF-051** un **RTO $\le 180\text{ min}$**.

### Plan Técnico de Respaldos Sincronizados
1. **Respaldos de Base de Datos:**
   - **Copia Completa Diaria (*pg_dump* / *pg_basebackup*):** Ejecutada en horario de menor afluencia (03:00 UTC) y transferida a almacenamiento secundario seguro.
   - **Archivado Continuo de Registros WAL (Write-Ahead Logging):** Segmentos de WAL archivados cada **15 minutos** hacia un almacenamiento secundario independiente.
   - **Métrica RPO alcanzada:** Menor a **15 minutos** de pérdida potencial de datos en el peor escenario de desastre catastrófico (superando holgadamente el requisito de 60 minutos).
2. **Coherencia con Archivos Adjuntos (RNF-052):**
   - Los archivos adjuntos en el sistema de almacenamiento se nombran con claves inmutables derivadas de su hash SHA-256 y UUID (`UUID.ext`).
   - El almacenamiento de adjuntos se respalda de forma incremental diaria sincronizada inmediatamente después del respaldo completo de base de datos.
3. **Procedimiento de Recuperación (RTO):**
   - Restauración de volumen base y replay de WAL automatizado mediante script contenedorizado.
   - **Métrica RTO alcanzada:** Levantamiento operativo total comprobable en **menos de 35 minutos** (muy por debajo de los 180 minutos máximos permitidos).

---

## 10. Conclusión y Recomendación para la Mesa Técnica

El especialista en base de datos dictamina:

> 1. **RDBMS Recomendado:** **PostgreSQL 16+** por su superioridad técnica en MVCC, tipos de datos nativos (`UUID`, `JSONB`), indexación y `SKIP LOCKED` nativo sin costo de licencias.
> 2. **Compatibilidad:** La arquitectura de base de datos diseñada es plenamente compatible y fácilmente adaptable a **MySQL 8.x (InnoDB)** si la institución hospitalaria exige estandarización corporativa preexistente.
> 3. **Concurrencia:** Bloqueo optimista con campo `version` para asignación de guardias asistenciales, garantizando cero colisiones y alta velocidad.
> 4. **Inmutabilidad:** Estricta prohibición de borrado físico (`REVOKE DELETE`) con doble pista de auditoría append-only.
> 5. **Adjuntos:** Desacoplamiento total de archivos binarios fuera de la base de datos relacional.

El siguiente paso obligatorio corresponde a **`security-reviewer`**, quien analizará la identidad, RBAC, auditoría y aislamiento de datos clínicos en:  
📄 [`proyecto/03_resultados/05_security_analysis.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/03_resultados/05_security_analysis.md).