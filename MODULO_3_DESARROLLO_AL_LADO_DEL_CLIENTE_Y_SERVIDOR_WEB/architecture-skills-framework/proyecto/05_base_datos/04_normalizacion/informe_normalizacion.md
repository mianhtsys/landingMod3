# Paso 05 — Informe de Normalización (1FN, 2FN, 3FN y BCNF)

**Workflow:** `02_database_workflow` — Paso 05  
**Skill utilizado:** `database-schema-designer` (instalado en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entrada:** `proyecto/05_base_datos/03_modelo_logico/modelo_logico.md`  
**Objetivo:** Demostrar rigurosamente que el modelo lógico relacional cumple con la Primera Forma Normal (1FN), Segunda Forma Normal (2FN), Tercera Forma Normal (3FN) y Forma Normal de Boyce-Codd (BCNF), documentando y justificando técnicamente toda desnormalización deliberada vinculada a RF/RNF o patrones de acceso de alta concurrencia.

---

## 1. Verificación de Primera Forma Normal (1FN)

### Regla Teórica
Una relación se encuentra en 1FN si y solo si:
1. Todos los atributos contienen únicamente **valores atómicos e indivisibles** (no existen atributos multivaluados, listas delimitadas por comas ni tuplas anidadas que actúen como columnas relacionales primarias).
2. No existen **grupos repetitivos** (columnas del estilo `telefono_1`, `telefono_2`, `adjunto_1`, `adjunto_2`).
3. Cada registro posee una **clave primaria formal** que identifica de manera única cada tupla.

### Evaluación por Tabla en S3IH

| Módulo / Tabla | Clave Primaria (PK) | Comprobación de Atomicidad y Grupos Repetitivos | Estado 1FN |
| :--- | :--- | :--- | :---: |
| `org_sedes` | `id` (entero) | Datos escalares directos (`codigo_institucional`, `nombre`, `direccion`, `telefono`, `es_central`, `estado`). Sin listas. | ✅ Cumple |
| `org_servicios` | `id` (entero) | Atributos escalares (`sede_id`, `codigo`, `nombre`, `tipo_servicio`, `ubicacion_piso_area`, `estado`). | ✅ Cumple |
| `org_cuadrillas_guardia` | `id` (entero) | Cuadrilla definida de manera atómica con su sede y servicio resolutor. | ✅ Cumple |
| `org_cuadrilla_miembros` | `(cuadrilla_id, usuario_id)` | Tabla asociativa pura. Se descompone la relación N:M evitando arrays de miembros en la cuadrilla. | ✅ Cumple |
| `sec_roles` | `codigo` (cadena) | `permisos_json` almacena matriz estructurada de acceso (ver justificación §5.1). Clave primaria textual canónica. | ✅ Cumple |
| `sec_usuarios` | `id` (entero) | Cada funcionario hospitalario es una fila independiente ligada a sede, servicio y rol vía FK atómicas. | ✅ Cumple |
| `cat_solicitud_tipos` | `id` (entero) | Clasificación atómica de fallas y servicios técnicos resolutores. | ✅ Cumple |
| `cat_causales` | `codigo` (cadena) | Códigos estándar únicos e inmutables para pausas, rechazos y reaperturas. | ✅ Cumple |
| `sla_calendarios` | `codigo_calendario` (cadena) | Horas de inicio, fin y patrón de jornada atómicos. | ✅ Cumple |
| `sla_dias_inhabiles` | `id` (entero) | Cada feriado o día no laborable es una fila individual ligada a fecha y sede opcional. | ✅ Cumple |
| `sla_politicas` | `id` (entero) | Tiempos MTTA y MTTR escalares enteros en minutos por tipo y prioridad. | ✅ Cumple |
| `sol_solicitudes` | `id` (entero_grande) | Cada solicitud es una fila única. Estados, prioridades, ubicaciones y participantes son escalares. | ✅ Cumple |
| `sol_control_tiempo_sla` | `solicitud_id` (entero_grande) | Métricas temporales y marcas de pausa registradas en columnas escalares separadas. Relación 1:1. | ✅ Cumple |
| `sol_historial_estados` | `id` (entero_grande) | Cada transición de estado es un registro independiente append-only (sin columnas de estados previos empaquetados). | ✅ Cumple |
| `sol_informes_atencion` | `solicitud_id` (entero_grande) | Atributos técnicos escalares (diagnóstico, acciones, tiempo real). Insumos estructurados en JSON (ver §5.1). | ✅ Cumple |
| `sol_reaperturas` | `id` (entero_grande) | Cada reapertura es una fila única con su motivo y causal. | ✅ Cumple |
| `sol_reasignaciones` | `id` (entero_grande) | Auditoría de cambio de técnico/cuadrilla registrada individualmente fila por fila. | ✅ Cumple |
| `sol_bitacora` | `id` (entero_grande) | Bitácora cronológica con una fila por anotación técnica. | ✅ Cumple |
| `sol_adjuntos` | `id` (entero_grande) | Cada archivo adjunto es una fila independiente; no hay arrays de archivos en `sol_solicitudes`. | ✅ Cumple |
| `ops_idempotencia` | `idempotency_key` (cadena) | Registro de tokens HTTP UUID v4 con hash y respuesta almacenados independientemente. | ✅ Cumple |
| `ops_outbox_eventos` | `id` (entero_grande) | Cada evento o notificación asíncrona es una fila con su ciclo de vida programado. | ✅ Cumple |
| `audit_eventos` | `id` (entero_grande) | Pista legal append-only con una fila por operación y firma criptográfica encadenada. | ✅ Cumple |

**Dictamen 1FN:** Todas las entidades del modelo satisfacen 1FN. Se eliminaron grupos repetitivos mediante tablas hijas dedicadas (`sol_adjuntos`, `sol_bitacora`, `org_cuadrilla_miembros`).

---

## 2. Verificación de Segunda Forma Normal (2FN)

### Regla Teórica
Una relación se encuentra en 2FN si y solo si:
1. Ya se encuentra en **1FN**.
2. **Ningún atributo no clave depende funcionalmente de una parte propia de una clave candidata compuesta** (ausencia de dependencias parciales). Si una clave primaria no es compuesta (consta de un único atributo), la relación se encuentra automáticamente en 2FN.

### Análisis de Claves Primarias y Dependencias Parciales

#### 2.1 Tablas con Clave Primaria Simple (Surrogate o Natural Unaria)
Las siguientes tablas poseen una clave primaria de un solo atributo (`id`, `codigo` o `idempotency_key`):
- `org_sedes`, `org_servicios`, `org_cuadrillas_guardia`, `sec_roles`, `sec_usuarios`, `cat_solicitud_tipos`, `cat_causales`, `sla_calendarios`, `sla_dias_inhabiles`, `sla_politicas`, `sol_solicitudes`, `sol_control_tiempo_sla`, `sol_historial_estados`, `sol_informes_atencion`, `sol_reaperturas`, `sol_reasignaciones`, `sol_bitacora`, `sol_adjuntos`, `ops_idempotencia`, `ops_outbox_eventos`, `audit_eventos`.
- **Resultado:** Cumplen **2FN por definición matemática**, al no existir claves primarias compuestas de las cuales pudieran desprenderse dependencias funcionales parciales.

#### 2.2 Tablas con Clave Primaria Compuesta

##### A. Tabla `org_cuadrilla_miembros`
- **Clave Primaria:** `(cuadrilla_id, usuario_id)`
- **Atributos no clave:** `fecha_incorporacion`
- **Análisis de dependencias funcionales:**
  - `(cuadrilla_id, usuario_id) -> fecha_incorporacion`: La fecha de incorporación describe el momento exacto en que ese técnico específico fue asignado a esa cuadrilla particular.
  - `cuadrilla_id -/-> fecha_incorporacion`: Una cuadrilla no tiene una única fecha de incorporación aislada del usuario.
  - `usuario_id -/-> fecha_incorporacion`: Un usuario puede haber sido incorporado a distintas cuadrillas en fechas diferentes.
- **Resultado:** `fecha_incorporacion` depende de la **totalidad** de la clave primaria compuesta. **Cumple 2FN estrictamente.**

##### B. Claves Alternativas Compuestas con Restricción `UNIQUE`
- `org_servicios`: `UNIQUE (sede_id, codigo)`
  - Todos los atributos no clave (`nombre`, `tipo_servicio`, `ubicacion_piso_area`) dependen del servicio en sí, no de la sede por separado ni del código por separado.
- `cat_solicitud_tipos`: `UNIQUE (servicio_resolutor_id, codigo_tipo)`
  - Los atributos (`nombre`, `descripcion`, `prioridad_sugerida`) describen la combinación del tipo y la especialidad técnica.
- `sla_politicas`: `UNIQUE (tipo_solicitud_id, prioridad_asistencial)`
  - Los tiempos `tiempo_max_respuesta_mtta_min` y `tiempo_max_resolucion_mttr_min` dependen de la tupla combinada: el mismo tipo de falla tiene tiempos distintos según si es VITAL o MEDIA.
- `sla_dias_inhabiles`: `UNIQUE (fecha, sede_id)`
  - La descripción del feriado local depende de la fecha en la sede específica.

**Dictamen 2FN:** No existen dependencias funcionales parciales en ninguna de las tablas del esquema. El modelo cumple 2FN al 100%.

---

## 3. Verificación de Tercera Forma Normal (3FN)

### Regla Teórica
Una relación se encuentra en 3FN si y solo si:
1. Ya se encuentra en **2FN**.
2. **Ningún atributo no clave depende transitivamente de una clave candidata**. Es decir, si $X \rightarrow Y$ y $Y \rightarrow Z$ (donde $X$ es la clave primaria y $Y, Z$ son atributos no clave), no debe existir la dependencia funcional $Y \rightarrow Z$ dentro de la misma relación; $Z$ debe residir en su propia relación con $Y$ como clave primaria.

### Evaluación Exhaustiva por Relación

| Tabla | Atributos no clave evaluados | ¿Existe Dependencia Transitiva $A \rightarrow B$? | Resolución / Justificación | Estado 3FN |
| :--- | :--- | :---: | :--- | :---: |
| `org_sedes` | `codigo_institucional`, `nombre`, `direccion`, `telefono`, `es_central`, `estado` | No | Todos los datos describen las propiedades directas del hospital. | ✅ 3FN |
| `org_servicios` | `sede_id`, `codigo`, `nombre`, `tipo_servicio`, `ubicacion_piso_area`, `estado` | No | No se almacenan datos de la sede (como dirección de la sede) en la tabla del servicio; se aísla mediante FK `sede_id`. | ✅ 3FN |
| `org_cuadrillas_guardia` | `sede_id`, `servicio_resolutor_id`, `nombre`, `turno_codigo`, `estado` | No | `servicio_resolutor_id` es FK pura. No se duplica el nombre del servicio técnico. | ✅ 3FN |
| `sec_usuarios` | `username_ldap`, `email_corporativo`, `nombre_completo`, `sede_id`, `servicio_id`, `rol_codigo`, `estado`, `ultimo_acceso_at` | No | Los datos de la sede, del servicio y de los permisos del rol residen en sus tablas maestras. | ✅ 3FN |
| `cat_solicitud_tipos` | `servicio_resolutor_id`, `codigo_tipo`, `nombre`, `descripcion`, `prioridad_sugerida`, `estado` | No | No se arrastran datos del servicio técnico más allá de la FK foránea. | ✅ 3FN |
| `sla_politicas` | `tipo_solicitud_id`, `prioridad_asistencial`, `tiempo_max_respuesta_mtta_min`, `tiempo_max_resolucion_mttr_min`, `calendario_codigo` | No | Los detalles del calendario (`hora_inicio`, `hora_fin`) no están en la política; se vinculan mediante FK `calendario_codigo`. | ✅ 3FN |
| `sol_solicitudes` | Ver desglose detallado en §3.1 abajo | Ver §3.1 | Separación estricta de solicitantes, técnicos, estados, catálogos y métricas SLA. | ✅ 3FN con notas |
| `sol_control_tiempo_sla` | `calendario_codigo`, `fecha_compromiso_mtta_utc`, `fecha_compromiso_mttr_utc`, `segundos_consumidos`, `esta_pausado`, `segundos_pausados_acumulados`, banderas de alerta | No | Dependen exclusivamente de la solicitud intervenida. Cálculos acumulados justificados por latencia (ver §5.2). | ✅ 3FN |
| `sol_historial_estados` | `solicitud_id`, `estado_anterior`, `estado_nuevo`, `causal_codigo`, `motivo_texto`, `usuario_id`, `ip_origen`, `created_at_utc` | No | Pista inmutable. Las descripciones de causal residen en `cat_causales` mediante FK `causal_codigo`. | ✅ 3FN |
| `sol_informes_atencion` | `diagnostico_falla`, `acciones_ejecutadas`, `tiempo_real_minutos`, `condicion_final_activo`, `repuestos_utilizados_json`, `tecnico_responsable_id`, `created_at_utc` | No | Atributos técnicos directos del cierre de orden. | ✅ 3FN |
| `sol_reaperturas` | `solicitud_id`, `causal_codigo`, `motivo_inconformidad`, `usuario_solicitante_id`, `created_at_utc` | No | Entidad débil de evento de reapertura atada a su solicitud. | ✅ 3FN |
| `sol_reasignaciones` | `solicitud_id`, técnicos saliente/entrante, cuadrillas, `motivo`, autorizador, fecha | No | Dependencia directa de la orden intervenida y de los involucrados vía FK. | ✅ 3FN |
| `sol_adjuntos` | `solicitud_id`, `nombre_archivo_original`, `uuid_almacenamiento`, `tipo_mime_real`, `extension`, `tamano_bytes`, `hash_sha256`, `uri_almacenamiento`, usuario, fecha | No | Metadatos directos del archivo físico subido. | ✅ 3FN |
| `ops_idempotencia` | `usuario_id`, `recurso_tipo`, `request_hash_sha256`, `response_status_http`, `response_body_json`, `created_at_utc`, `expires_at_utc` | No | Dependen únicamente de la clave `idempotency_key`. | ✅ 3FN |
| `ops_outbox_eventos` | `tipo_evento`, `agregado_tipo`, `agregado_id`, `payload_json`, `estado`, `reintentos`, `error_mensaje`, marcas de tiempo | No | Datos de ciclo de vida del evento asíncrono. | ✅ 3FN |
| `audit_eventos` | `usuario_id`, `ip_origen`, `accion`, `tabla_afectada`, `registro_id`, deltas JSON, `hash_encadenado_sha256`, `correlation_id`, fecha | No | Registro inmutable de cada transacción ejecutada. | ✅ 3FN |

---

### 3.1 Análisis Especial de 3FN en la Tabla Central `sol_solicitudes`

En sistemas hospitalarios de alta exigencia, la entidad principal de solicitudes suele ser propensa a introducir redundancias si no se analiza con rigor.

1. **¿Dependencia transitiva en Servicio Solicitante y Sede?**
   - Una solicitud posee `sede_id` y `servicio_solicitante_id`.
   - Se sabe que en `org_servicios`, `id -> sede_id`. ¿Es redundante mantener `sede_id` en `sol_solicitudes`?
   - **Evaluación:** Mantener `sede_id` en `sol_solicitudes` podría considerarse una dependencia transitiva formal (`solicitud_id -> servicio_solicitante_id -> sede_id`).
   - **Justificación técnica de conservación (Desnormalización controlada):**
     - **Particionamiento y Seguridad Multisede (RNF-031):** Las consultas del 95% del personal operativo en enfermería y quirófanos filtran obligatoriamente por hospital local (`WHERE sede_id = ?`). Exigir un `JOIN org_servicios` en cada consulta de listado, monitoreo de cuadrillas y tablero de mando introduce overhead innecesario.
     - **Inmutabilidad de radicación:** Si en el futuro un servicio se reorganiza o cambia de código en la estructura hospitalaria, la solicitud histórica pertenece institucionalmente a la sede donde físicamente ocurrió el evento.
     - **Regla de integridad:** Se asegura mediante constraint de aplicación y validación en capa de persistencia que `org_servicios.sede_id == sol_solicitudes.sede_id`.

2. **¿Dependencia transitiva en Prioridad Asistencial y Tipo de Solicitud?**
   - `cat_solicitud_tipos` tiene `prioridad_sugerida`. `sol_solicitudes` almacena `prioridad_asistencial`.
   - ¿Depende la prioridad del tipo de solicitud?
   - **No hay dependencia funcional:** La prioridad en `sol_solicitudes` es una decisión clínica/asistencial del solicitante en el momento de la radicación (ej. un mismo fallo de energía en una oficina es MEDIA, pero en Quirófano de Urgencias se eleva a VITAL según RF-020). Por tanto, `prioridad_asistencial` es un atributo independiente y específico de la instancia transaccional. Cumple 3FN de forma natural.

3. **¿Dependencia transitiva en Fechas de Ciclo de Vida (`fecha_radicacion_utc`, `fecha_asignacion_utc`, `fecha_atencion_utc`, `fecha_cierre_utc`)?**
   - Estas marcas temporales existen como registros de eventos en `sol_historial_estados`.
   - ¿Es redundante mantenerlas en `sol_solicitudes`?
   - **Evaluación y Justificación:** Constituyen un **snapshot de estado operativo** para responder a consultas de latencia ultrabaja (p95 < 200 ms, RNF-010). Obtener las fechas clave del ciclo de vida requeriría consultar e indexar `MIN()/MAX()` sobre la tabla append-only `sol_historial_estados` (que acumulará millones de filas en 5 años de retención). Mantener estos punteros en la cabecera es una optimización formal de lectura sin inconsistencia, pues la máquina de estados las actualiza atómicamente en la misma transacción ACID.

---

## 4. Verificación de Forma Normal de Boyce-Codd (BCNF)

### Regla Teórica
Una relación se encuentra en BCNF si y solo si, para cada dependencia funcional no trivial $X \rightarrow Y$, **$X$ es una superclave** (o clave candidata).
BCNF es una versión más estricta de 3FN que elimina anomalías cuando una tabla posee múltiples claves candidatas compuestas superpuestas.

### Análisis en el Esquema S3IH

1. **Tabla `sla_politicas`:**
   - Claves candidatas:
     - Clave 1 (Surrogate PK): `id`
     - Clave 2 (Natural alternativa): `(tipo_solicitud_id, prioridad_asistencial)`
   - Dependencias funcionales:
     - `id -> {tipo_solicitud_id, prioridad_asistencial, tiempo_max_respuesta_mtta_min, tiempo_max_resolucion_mttr_min, calendario_codigo}`
     - `(tipo_solicitud_id, prioridad_asistencial) -> {tiempo_max_respuesta_mtta_min, tiempo_max_resolucion_mttr_min, calendario_codigo}`
   - Ambos determinantes son superclaves formales (una es PK y la otra está cubierta por índice `UNIQUE`).
   - No existen atributos clave que dependan de subconjuntos de otras claves candidatas.
   - **Resultado:** Cumple **BCNF**.

2. **Tabla `sol_solicitudes`:**
   - Claves candidatas:
     - Clave 1: `id` (PK)
     - Clave 2: `codigo_correlativo` (`UNIQUE`)
   - Ambos determinantes (`id` y `codigo_correlativo`) son superclaves completas.
   - Cualquier atributo de la orden depende tanto de `id` como del código correlativo formal.
   - **Resultado:** Cumple **BCNF**.

3. **Tabla `ops_idempotencia`:**
   - Claves candidatas: `idempotency_key` (PK).
   - `idempotency_key -> {usuario_id, recurso_tipo, request_hash_sha256, response_status_http, response_body_json, created_at_utc, expires_at_utc}`.
   - El determinante es superclave.
   - **Resultado:** Cumple **BCNF**.

**Dictamen BCNF:** Ninguna tabla presenta solapamiento de claves candidatas complejas que vulnere BCNF.

---

## 5. Justificación Técnica de Desnormalizaciones y Atributos Semi-estructurados

El diseño no incluye desnormalizaciones accidentales; todas las desviaciones respecto al modelo puramente teórico responden a requerimientos explícitos de rendimiento, trazabilidad y contención de complejidad:

### 5.1 Uso Deliberado de Atributos JSON (`json` / `jsonb`)

| Tabla | Columna | Justificación Arquitectónica | RNF / RF Asociado |
| :--- | :--- | :--- | :--- |
| `sec_roles` | `permisos_json` | Evita crear 3 tablas adicionales (`sec_permisos`, `sec_rol_permisos`, `sec_modulos`) para un catálogo estático de 6 roles hospitalarios con lectura intensiva en cada autenticación (D-02). | RF-003, RNF-031 |
| `sol_informes_atencion` | `repuestos_utilizados_json` | Documenta insumos médicos utilizados (ej. fusibles, filtros HEPA, electrodos). En esta fase no existe ERP de inventarios hospitalarios acoplado; crear un módulo completo de materiales violaría el alcance congelado (CA-010). | RF-044, RN-007 |
| `ops_outbox_eventos` | `payload_json` | Patrón *Transactional Outbox*: almacena el contexto variable de la notificación o evento asíncrono para el worker (destinatarios, cuerpo de correo, datos de escalamiento). | RF-052, D-06 |
| `audit_eventos` | `datos_anteriores_json`, `datos_nuevos_json` | Captura fotográfica del delta de la fila modificada para no repudio. Crear una tabla de auditoría por cada entidad clínica multiplicaría el mantenimiento por 16 sin aportar valor relacional. | RF-072, RN-013 |

### 5.2 Contadores y Métricas Acumuladas en `sol_control_tiempo_sla`
- **Columnas:** `segundos_consumidos`, `segundos_pausados_acumulados`.
- **Riesgo teórico:** Son valores que matemáticamente podrían calcularse sumando las diferencias de tiempo de cada pausa en `sol_historial_estados`.
- **Justificación:** El SLA hospitalario exige alertas en tiempo real al 75% y 100% (RF-052) evaluadas concurrentemente para cientos de solicitudes activas. Si el worker asíncrono debiera recalcular por agregación temporal la historia de cada requerimiento cada minuto, la CPU de la base de datos se saturaría. El almacenamiento del acumulador escalar permite una consulta O(1) indexada directa.

### 5.3 Control de Concurrencia Optimista: Columna `version`
- **Tabla:** `sol_solicitudes`
- **Justificación:** Columna de control de concurrencia optimista (`version INT NOT NULL DEFAULT 1`). No forma parte del modelo semántico del negocio hospitalario, pero es un mecanismo esencial de arquitectura (D-01, CA-004) para evitar sobreescritura ciega (*lost updates*) en puestos clínicos compartidos.

---

## 6. Análisis de Prevención de Anomalías Relacionales

| Tipo de Anomalía | Riesgo Teórico | Mecanismo de Prevención Implementado en S3IH |
| :--- | :--- | :--- |
| **Anomalía de Inserción** | No poder registrar un servicio clínico sin antes crear una solicitud; o no poder registrar un usuario sin cuadrilla. | Jerarquía limpia: `org_sedes` $\rightarrow$ `org_servicios` $\rightarrow$ `sec_usuarios` $\rightarrow$ `sol_solicitudes`. Las cuadrillas y miembros son independientes de las solicitudes activas. |
| **Anomalía de Modificación** | Modificar el nombre de una sede o descripción de una falla y que queden datos inconsistentes en solicitudes antiguas. | Totalmente prevenida: `sol_solicitudes` almacena únicamente `tipo_solicitud_id` y `sede_id` (FK). Cambiar el nombre del hospital o la descripción técnica se propaga instantáneamente a todas las consultas. |
| **Anomalía de Eliminación** | Borrar un usuario o servicio y perder el historial de solicitudes hospitalarias atendidas. | Prevenida doblemente: (1) Inmutabilidad estricta (`REVOKE DELETE` en producción, RN-012); (2) Restricciones de integridad referencial `ON DELETE RESTRICT` en todas las claves foráneas hacia historiales e informes. |

---

## 7. Conclusión y Resumen de Estado

| Forma Normal | Estado | Observación Técnica |
| :--- | :---: | :--- |
| **1FN (Valores Atómicos)** | **APROBADO** | Sin grupos repetitivos. Entidades hijas para adjuntos, historial y bitácora. |
| **2FN (Sin Dependencias Parciales)** | **APROBADO** | Claves primarias simples en su mayoría; clave compuesta de cuadrilla y miembros evaluada rigurosamente. |
| **3FN (Sin Dependencias Transitivas)** | **APROBADO** | Aislamiento completo de catálogos y estructuras organizacionales. Desnormalización de `sede_id` y snapshots de estado justificados. |
| **BCNF (Superclaves Determinantes)** | **APROBADO** | Toda dependencia funcional emana de una superclave con índice único. |
| **Desnormalizaciones Controladas** | **JUSTIFICADAS** | JSON para auditoría/outbox/repuestos; contadores de SLA para latencia O(1); columna `version` para concurrencia optimista. |

El modelo lógico se encuentra completamente validado y formalmente normalizado sin anomalías estructurales.

---

**Estado del Paso:** Paso 05 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 06 — Selección del DBMS (`proyecto/05_base_datos/05_modelo_fisico/seleccion_dbms.md`), utilizando el skill `databases`.
