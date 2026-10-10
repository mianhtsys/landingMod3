# Paso 08 — Integridad Referencial, de Dominio y Reglas de Negocio (S3IH)

**Workflow:** `02_database_workflow` — Paso 08  
**Skills utilizados:** `database-schema-designer`, `databases` (instalados en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`  
- `proyecto/05_base_datos/03_modelo_logico/modelo_logico.md`  
- `proyecto/00_contexto/reglas_negocio.md`  
- `proyecto/01_requisitos/criterios_aceptacion.md`  
- `proyecto/04_decisiones/decisiones_base_datos.md`  

**Objetivo:** Formalizar la arquitectura de integridad en 5 niveles para el motor relacional hospitalario, definiendo constraints declarativos de base de datos, políticas referenciales ante eventos de borrado/actualización, reglas de chequeo de dominio y mecanismos de validación de reglas de negocio críticas sin ambigüedades.

---

## 1. Arquitectura de Integridad en 5 Niveles

El principio arquitectónico rector establece que **la aplicación jamás sustituye a la base de datos en las garantías que el motor relacional puede asegurar por diseño** (integridad declarativa primero):

| Nivel de Integridad | Mecanismo de Implementación | Ámbito de Ejecución | Justificación en S3IH |
| :--- | :--- | :--- | :--- |
| **1. Dominio** | Tipos de datos estrictos, `NOT NULL`, constraints `CHECK`, `DEFAULT` | Cada columna y fila individual | Prevenir inconsistencias tipográficas, desbordamientos numéricos y datos vacíos. |
| **2. Unicidad** | `PRIMARY KEY`, restricciones e índices `UNIQUE` | Tablas individuales | Evitar duplicación de códigos institucionales, usuarios y tokens de idempotencia. |
| **3. Referencial** | `FOREIGN KEY` con cláusulas `ON DELETE` / `ON UPDATE` | Relaciones inter-tabla | Mantener coherencia jerárquica hospitalaria e impedir orfandad de registros. |
| **4. Transaccional (ACID)**| Transacciones `START TRANSACTION ... COMMIT`, bloqueos por fila, bloqueo optimista (`version`) | Operaciones compuestas multi-tabla | Atomicidad indisoluble en transiciones de estado, outbox y cómputo de SLA. |
| **5. Reglas de Negocio** | Validaciones en transacciones ACID + triggers / constraints compuestos | Flujo de negocio hospitalario | Cumplimiento estricto de la máquina de estados, no repudio y política Zero-PHI. |

---

## 2. Integridad de Dominio: Especificación de Constraints `CHECK` y `NOT NULL`

Todas las columnas obligatorias cuentan con `NOT NULL`. A continuación se especifican las restricciones lógicas de dominio formalizadas en el motor:

| Tabla | Columna / Restricción | Definición de Constraint `CHECK` | Fundamento / Regla de Negocio |
| :--- | :--- | :--- | :--- |
| `org_sedes` | `estado` | `CHECK (estado IN ('activo', 'inactivo'))` | Catálogo maestro hospitalario. |
| `org_servicios` | `tipo_servicio` | `CHECK (tipo_servicio IN ('ASISTENCIAL', 'RESOLUTOR'))` | RF-002: Distinción entre áreas clínicas y unidades técnicas de soporte. |
| `org_servicios` | `estado` | `CHECK (estado IN ('activo', 'inactivo'))` | Inactivación lógica en lugar de borrado físico. |
| `org_cuadrillas_guardia`| `turno_codigo` | `CHECK (turno_codigo IN ('MANANA', 'TARDE', 'NOCHE', 'GUARDIA_24H'))` | RF-004: Cobertura operativa continua en turnos rotativos. |
| `sec_usuarios` | `estado` | `CHECK (estado IN ('activo', 'inactivo'))` | Gestión de altas/bajas de personal médico y técnico. |
| `cat_solicitud_tipos` | `prioridad_sugerida` | `CHECK (prioridad_sugerida IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA'))` | RF-010: Clasificación tipificada estandarizada. |
| `cat_causales` | `tipo_causal` | `CHECK (tipo_causal IN ('SUSPENSION_ESPERA', 'RECHAZO_TECNICO', 'CANCELACION_USUARIO', 'REAPERTURA'))` | RF-014: Justificaciones catalogadas mandatorias. |
| `sla_calendarios` | `codigo_calendario`| `CHECK (codigo_calendario IN ('CONTINUO_24_7', 'HABIL_ADMINISTRATIVO'))` | RF-013: Dualidad de cálculo de SLA (24/7 vs hábil). |
| `sla_politicas` | `prioridad_asistencial` | `CHECK (prioridad_asistencial IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA'))` | RF-011: Matriz de tiempos MTTA/MTTR. |
| `sla_politicas` | `tiempo_max_respuesta_mtta_min`| `CHECK (tiempo_max_respuesta_mtta_min > 0)` | Plazos de primera respuesta obligatoriamente positivos. |
| `sla_politicas` | `tiempo_max_resolucion_mttr_min`| `CHECK (tiempo_max_resolucion_mttr_min > 0)` | Plazos de resolución final obligatoriamente positivos. |
| `sol_solicitudes` | `prioridad_asistencial` | `CHECK (prioridad_asistencial IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA'))` | RF-020: Nivel de criticidad clínica asignado. |
| `sol_solicitudes` | `estado` | `CHECK (estado IN ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada'))` | RN-005: Estados válidos y cerrados de la máquina de estados. |
| `sol_solicitudes` | `version` | `CHECK (version >= 1)` | D-01: Contador de concurrencia optimista. |
| `sol_historial_estados` | `estado_anterior`, `estado_nuevo` | `CHECK (estado_anterior IN (...) AND estado_nuevo IN (...))` | Inmutabilidad de la traza de transiciones. |
| `sol_informes_atencion` | `tiempo_real_minutos` | `CHECK (tiempo_real_minutos > 0)` | RF-044, RN-007: Dedicación técnica real obligatoria. |
| `sol_bitacora` | `tipo_entrada` | `CHECK (tipo_entrada IN ('NOTA_TECNICA', 'CAMBIO_GUARDIA', 'PIEZA_SOLICITADA', 'COMUNICACION'))` | RF-060: Clasificación de notas cronológicas. |
| `sol_adjuntos` | `extension` | `CHECK (extension IN ('pdf', 'jpg', 'jpeg', 'png'))` | Restricción Técnica 5: Formatos seguros autorizados. |
| `sol_adjuntos` | `tamano_bytes` | `CHECK (tamano_bytes > 0 AND tamano_bytes <= 5242880)` | Restricción Técnica 5, D-04: Límite estricto de 5 MB por archivo. |
| `ops_outbox_eventos` | `tipo_evento` | `CHECK (tipo_evento IN ('ALERTA_SLA_75', 'ESCALAMIENTO_SLA_100', 'DESPACHO_EMAIL_SMTP', 'AUTO_CIERRE_48H'))` | D-06: Eventos asíncronos autorizados para el worker. |
| `ops_outbox_eventos` | `estado` | `CHECK (estado IN ('PENDIENTE', 'PROCESANDO', 'ENVIADO', 'FALLIDO'))` | Ciclo de vida de entrega outbox. |

---

## 3. Integridad Referencial: Matriz de Claves Foráneas y Acciones ante Eliminación/Actualización

En estricta conformidad con **RN-012** y **RN-013** (inmutabilidad y prohibición de borrado físico en salud), la política referencial del sistema hospitalario es **cero borrado en cascada (`ON DELETE CASCADE` terminantemente prohibido)**:

| Tabla Origen | Clave Foránea (FK) | Tabla Destino | Acción ON DELETE | Acción ON UPDATE | Justificación Hospitalaria y Trazabilidad |
| :--- | :--- | :--- | :---: | :---: | :--- |
| `org_servicios` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | No se puede eliminar una sede hospitalaria si tiene servicios médicos adscritos. |
| `org_cuadrillas_guardia` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | Integridad de sede. |
| `org_cuadrillas_guardia` | `servicio_resolutor_id` | `org_servicios` | `RESTRICT` | `CASCADE` | La cuadrilla técnica pertenece a un servicio resolutor válido. |
| `org_cuadrilla_miembros` | `cuadrilla_id` | `org_cuadrillas_guardia` | `RESTRICT` | `CASCADE` | Miembros atados a la cuadrilla de guardia. |
| `org_cuadrilla_miembros` | `usuario_id` | `sec_usuarios` | `RESTRICT` | `CASCADE` | No se borran técnicos con historial de guardia. |
| `sec_usuarios` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | Un usuario pertenece a un hospital real. |
| `sec_usuarios` | `servicio_id` | `org_servicios` | `RESTRICT` | `CASCADE` | El usuario pertenece a un servicio asistencial o técnico. |
| `sec_usuarios` | `rol_codigo` | `sec_roles` | `RESTRICT` | `CASCADE` | El rol de seguridad RBAC debe existir formalmente. |
| `cat_solicitud_tipos` | `servicio_resolutor_id` | `org_servicios` | `RESTRICT` | `CASCADE` | La especialidad de la falla pertenece a un área técnica real. |
| `sla_dias_inhabiles` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | El día feriado local pertenece a una sede hospitalaria. |
| `sla_politicas` | `tipo_solicitud_id` | `cat_solicitud_tipos` | `RESTRICT` | `CASCADE` | Política de SLA ligada al catálogo de fallas. |
| `sla_politicas` | `calendario_codigo` | `sla_calendarios` | `RESTRICT` | `CASCADE` | Horario de cómputo (24/7 vs hábil) mandatorio. |
| `sol_solicitudes` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | La sede generadora es inmutable. |
| `sol_solicitudes` | `servicio_solicitante_id` | `org_servicios` | `RESTRICT` | `CASCADE` | Servicio asistencial solicitante obligatorio. |
| `sol_solicitudes` | `servicio_resolutor_id` | `org_servicios` | `RESTRICT` | `CASCADE` | Servicio técnico resolutor obligatorio. |
| `sol_solicitudes` | `tipo_solicitud_id` | `cat_solicitud_tipos` | `RESTRICT` | `CASCADE` | Tipo de requerimiento tipificado. |
| `sol_solicitudes` | `usuario_solicitante_id` | `sec_usuarios` | `RESTRICT` | `CASCADE` | Autor de radicación inmutable para no repudio (RF-072). |
| `sol_solicitudes` | `tecnico_asignado_id` | `sec_usuarios` | **`SET NULL`** | `CASCADE` | **Excepción justificada:** Si un técnico se desvincula o reasigna, la solicitud no se destruye; queda sin asignar para intervención de la jefatura. |
| `sol_solicitudes` | `cuadrilla_asignada_id` | `org_cuadrillas_guardia` | `RESTRICT` | `CASCADE` | Cuadrilla de guardia de turno. |
| `sol_control_tiempo_sla`| `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Relación 1:1; el reloj de SLA no puede existir sin solicitud. |
| `sol_historial_estados` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Inmutabilidad de la pista de auditoría. |
| `sol_historial_estados` | `causal_codigo` | `cat_causales` | `RESTRICT` | `CASCADE` | Causal estandarizada obligatoria si aplica. |
| `sol_informes_atencion` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Informe técnico indisoluble de la orden de trabajo. |
| `sol_reaperturas` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Pista inmutable de reapertura. |
| `sol_reasignaciones` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Pista inmutable de cambios de asignación. |
| `sol_bitacora` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Bitácora técnica append-only. |
| `sol_adjuntos` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Los metadatos del archivo adjunto no se borran. |
| `ops_idempotencia` | `usuario_id` | `sec_usuarios` | `RESTRICT` | `CASCADE` | Usuario emisor de la petición HTTP. |

---

## 4. Integridad de Unicidad (Claves Primarias y Restricciones `UNIQUE`)

| Tabla | Restricción / Columnas | Propósito de Negocio | RNF / RF |
| :--- | :--- | :--- | :--- |
| `org_sedes` | `UNIQUE (codigo_institucional)` | Prefijo institucional único (`H1`, `H2`, `H3`, `H4`). | RF-001 |
| `org_servicios` | `UNIQUE (sede_id, codigo)` | Código de área único dentro del mismo centro hospitalario. | RF-002 |
| `sec_usuarios` | `UNIQUE (username_ldap)` | Cuenta de red corporativa unívoca en Directorio Activo. | RF-003, RNF-030 |
| `sec_usuarios` | `UNIQUE (email_corporativo)` | Dirección de correo única para notificaciones institucionales. | RF-003 |
| `cat_solicitud_tipos` | `UNIQUE (servicio_resolutor_id, codigo_tipo)` | Código de falla único por especialidad técnica. | RF-010 |
| `sla_dias_inhabiles` | `UNIQUE (fecha, sede_id)` | Impedir duplicación de feriados locales en el mismo hospital. | RF-013 |
| `sla_politicas` | `UNIQUE (tipo_solicitud_id, prioridad_asistencial)` | Matriz unívoca de plazos de respuesta por tipo y criticidad. | RF-011 |
| `sol_solicitudes` | `UNIQUE (codigo_correlativo)` | Formato oficial inmutable `H{sede}-{año}-{secuencial}`. | RN-001, CA-001 |
| `sol_adjuntos` | `UNIQUE (uuid_almacenamiento)` | Nombre físico ofuscado (UUID v4) irrepetible en almacenamiento. | D-04, RNF-034 |
| `ops_idempotencia` | `PRIMARY KEY (idempotency_key)` | Token UUID v4 irrepetible dentro de la ventana TTL de 24 horas. | RN-011, D-03 |

---

## 5. Implementación de Reglas de Negocio en la Capa Transaccional

A continuación se detalla cómo se garantiza cada regla de negocio crítica mediante transacciones atómicas y restricciones de base de datos:

### RN-001: Formato y Unicidad del Correlativo
- **Regla:** Formato estricto `H{sede}-{año}-{secuencial}` (ej. `H1-2026-000452`).
- **Mecanismo:** Índice `UNIQUE (codigo_correlativo)` en `sol_solicitudes`. Generación transaccional protegida mediante secuencia atómica por sede y año dentro de la transacción de radicación (`START TRANSACTION ... COMMIT`).

### RN-002: Inmutabilidad de Radicación
- **Regla:** Una vez radicada la solicitud, la sede generadora, el servicio clínico solicitante y el usuario emisor son inmodificables.
- **Mecanismo:** La capa de persistencia excluye `sede_id`, `servicio_solicitante_id` y `usuario_solicitante_id` de cualquier sentencia `UPDATE`. Solo se permite alterar estados, asignación técnica y campos de cierre.

### RN-003 y RN-008: Asignación y Reasignación de Guardias
- **Regla:** Una solicitud en estado `Asignada` o `En Proceso` debe contar obligatoriamente con `tecnico_asignado_id` o `cuadrilla_asignada_id`. Si se reasigna, debe registrarse el motivo y el supervisor en `sol_reasignaciones`.
- **Mecanismo:** La transacción de reasignación actualiza atómicamente la orden con bloqueo optimista (`version = version + 1`) e inserta simultáneamente la fila en `sol_reasignaciones`.

### RN-005: Máquina de Estados Finita Determinista
- **Regla:** Transiciones unidireccionales válidas:
  - `Registrada` $\rightarrow$ `Asignada` $\mid$ `Rechazada` $\mid$ `Cancelada`
  - `Asignada` $\rightarrow$ `En Proceso` $\mid$ `Rechazada`
  - `En Proceso` $\rightarrow$ `En Espera` $\mid$ `Atendida`
  - `En Espera` $\rightarrow$ `En Proceso` $\mid$ `Cancelada`
  - `Atendida` $\rightarrow$ `Cerrada` $\mid$ `En Proceso` (vía reapertura)
  - `Cerrada`, `Rechazada`, `Cancelada` $\rightarrow$ Estados finales inmutables.
- **Mecanismo:** Validación por máquina de estados en el Modelo y persistencia obligatoria en la misma transacción dentro de `sol_historial_estados`.

### RN-006: Suspensión de SLA con Causal Catalogada
- **Regla:** El paso al estado `En Espera` exige `causal_codigo` obligatoria (FK hacia `cat_causales`), congela el reloj de SLA y fija una fecha tentativa de reanudación.
- **Mecanismo:** La transacción actualiza `sol_control_tiempo_sla.esta_pausado = 1`, almacena `fecha_ultima_pausa_utc` y registra la causal en `sol_historial_estados`.

### RN-007: Informe Técnico Previo a la Atención
- **Regla:** Para transicionar a `Atendida`, es requisito indispensable haber registrado el informe técnico detallando diagnóstico, acciones, tiempo real e insumos.
- **Mecanismo:** Verificación de existencia previa o inserción en la misma transacción de la tupla 1:1 en `sol_informes_atencion`.

### RN-010: Ventana de Reapertura de 48 Horas
- **Regla:** Una solicitud en `Atendida` solo puede reabrirse dentro de los 48 horas posteriores a `fecha_atencion_utc`.
- **Mecanismo:** Validación estricta: `CURRENT_TIMESTAMP <= fecha_atencion_utc + INTERVAL 48 HOUR`. Tras 48 horas sin inconformidad, el worker la transiciona automáticamente a `Cerrada` vía evento outbox `AUTO_CIERRE_48H`.

### RN-011: Idempotencia Transaccional
- **Regla:** Toda petición HTTP de radicación o transición incluye `Idempotency-Key`.
- **Mecanismo:** Si el token ya existe en `ops_idempotencia` y su hash SHA-256 coincide, se retorna el código y cuerpo previo sin ejecutar inserción ni disparo de alertas; si el hash difiere, se rechaza con error 409 Conflict.

### RN-012 y RN-013: Prohibición de Borrado Físico y Retención de 5 Años
- **Regla:** Prohibido el borrado físico de órdenes, historiales y auditorías.
- **Mecanismo:** Políticas de base de datos a nivel de motor:
  ```sql
  REVOKE DELETE ON ALL TABLES IN SCHEMA public FROM s3ih_app_user;
  REVOKE UPDATE, DELETE ON sol_historial_estados, sol_bitacora, audit_eventos FROM s3ih_app_user;
  ```

### RNF-035: Política Zero-PHI
- **Regla:** Cero información de pacientes ni historias clínicas en fallas locativas y de equipamiento.
- **Mecanismo:** El esquema de base de datos carece por completo de entidades, columnas o relaciones relativas a pacientes. Las descripciones solo admiten texto técnico sobre fallas mecánicas, eléctricas o locativas.

---

## 6. Conclusión y Resumen de Estado

El modelo de integridad hospitalario formaliza la protección total de datos en sus 5 capas, garantizando que ninguna anomalía concurrente, caída de red o fallo operativo vulnere la trazabilidad, disponibilidad o inmutabilidad del sistema de salud.

---

**Estado del Paso:** Paso 08 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 09 — Seguridad y Control de Acceso (`proyecto/05_base_datos/07_seguridad/seguridad.md`), utilizando el skill `databases`.
