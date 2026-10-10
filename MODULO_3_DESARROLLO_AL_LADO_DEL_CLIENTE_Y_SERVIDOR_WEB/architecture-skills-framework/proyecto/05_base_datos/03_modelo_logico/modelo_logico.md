# Paso 04 — Modelo Lógico de Datos

**Workflow:** `02_database_workflow` — Paso 04  
**Skill utilizado:** `database-schema-designer` (instalado en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entrada:** `02_modelo_conceptual/modelo_conceptual.md`, `02_modelo_conceptual/diagrama_er.md`, `01_requisitos_datos/requisitos_datos.md`  
**Restricción de esta fase:** Transformación del modelo conceptual a relacional con atributos formales, llaves primarias (PK), llaves foráneas (FK), dominios semánticos independientes de motor (sin tipos específicos de Postgres/MySQL), restricciones de unicidad (UNIQUE), no nulidad (NOT NULL) y restricciones lógicas (CHECK). No seleccionar motor físico todavía.  

---

## 1. Criterios de Diseño del Modelo Lógico

1. **Claves Primarias (PK):** Toda tabla de negocio recibe una clave surrogate lógica `id` (dominio `entero` o `entero_grande`) excepto tablas asociativas puras (`org_cuadrilla_miembros`), catálogos con código estándar inmutable (`sec_roles`, `cat_causales`, `sla_calendarios`) o tablas de relación 1:1 estricta (`sol_control_tiempo_sla`, `sol_informes_atencion`).
2. **Convención de Prefijos por Módulo:**
   - `org_`: Estructura hospitalaria multisede, servicios y cuadrillas de turno.
   - `sec_`: Identidad, usuarios y roles (RBAC).
   - `cat_`: Catálogos tipificados de solicitudes y causales estandarizadas.
   - `sla_`: Calendarios, días inhábiles y políticas de tiempos de respuesta.
   - `sol_`: Núcleo transaccional de requerimientos, máquina de estados, bitácora y adjuntos.
   - `ops_`: Operaciones del sistema, idempotencia y eventos outbox asíncronos.
   - `audit_`: Pista inmutable y legal de auditoría del sistema.
3. **Inmutabilidad y No Borrado Físico (RN-012, RN-013):**
   - Prohibición formal de eliminación física: tablas maestras usan estado lógico (`activo`, `inactivo`).
   - Tablas de historial (`sol_historial_estados`, `sol_bitacora`, `audit_eventos`) son estrictamente **append-only** (sin operaciones destructivas ni de modificación).
4. **Almacenamiento Desacoplado de Evidencias (Restricción Técnica 5, D-04):**
   - `sol_adjuntos` solo gestiona metadatos y referencias URI; no existen columnas BLOB ni binarias pesadas.
5. **Control de Concurrencia Optimista (D-01, CA-004):**
   - La tabla central `sol_solicitudes` incorpora la columna lógica `version` (`entero`, NOT NULL, DEFAULT 1).

---

## 2. Especificación Detallada de Tablas Lógicas

---

### 2.1 Módulo Organización Multisede (`org_`)

#### Tabla: `org_sedes` (RF-001)
Representa cada uno de los hospitales de la red de salud.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `codigo_institucional` | cadena(2) | NOT NULL, UNIQUE | Prefijo institucional (`H1` a `H4`) |
| `nombre` | cadena(100) | NOT NULL | Nombre oficial del centro hospitalario |
| `direccion` | cadena(255) | NULL | Dirección física de la sede |
| `telefono` | cadena(50) | NULL | Central telefónica institucional |
| `es_central` | booleano | NOT NULL, DEFAULT false | Indica si es el hospital central |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'activo' | CHECK in ('activo', 'inactivo') |

#### Tabla: `org_servicios` (RF-002)
Áreas asistenciales y unidades resolutoras técnicas por sede.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `sede_id` | entero | NOT NULL, FK $\rightarrow$ `org_sedes` | Hospital al que pertenece |
| `codigo` | cadena(20) | NOT NULL | Código interno de servicio (ej. `QUIROF`, `BIOMED`) |
| `nombre` | cadena(100) | NOT NULL | Nombre descriptivo del servicio |
| `tipo_servicio` | cadena(20) | NOT NULL | CHECK in ('ASISTENCIAL', 'RESOLUTOR') |
| `ubicacion_piso_area` | cadena(150) | NOT NULL | Piso, pabellón o ala específica |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'activo' | CHECK in ('activo', 'inactivo') |
| *Restricción única:* | | UNIQUE (`sede_id`, `codigo`) | Código único dentro de la misma sede |

#### Tabla: `org_cuadrillas_guardia` (RF-004)
Equipos técnicos de turno rotativo para asignación de guardias 24/7.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `sede_id` | entero | NOT NULL, FK $\rightarrow$ `org_sedes` | Sede hospitalaria de operación |
| `servicio_resolutor_id` | entero | NOT NULL, FK $\rightarrow$ `org_servicios` | Unidad técnica (ej. Mantenimiento Biomédico) |
| `nombre` | cadena(100) | NOT NULL | Nombre de cuadrilla (ej. `Guardia Biomédica Noche`) |
| `turno_codigo` | cadena(20) | NOT NULL | CHECK in ('MANANA', 'TARDE', 'NOCHE', 'GUARDIA_24H') |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'activo' | CHECK in ('activo', 'inactivo') |

#### Tabla: `org_cuadrilla_miembros` (RF-004)
Composición de personal técnico dentro de una cuadrilla de guardia.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `cuadrilla_id` | entero | NOT NULL, FK $\rightarrow$ `org_cuadrillas_guardia` | Cuadrilla de asignación |
| `usuario_id` | entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Técnico miembro |
| `fecha_incorporacion` | fecha_hora | NOT NULL | Fecha de alta en la cuadrilla |
| *Clave primaria:* | | PK (`cuadrilla_id`, `usuario_id`) | Par único |

---

### 2.2 Módulo Seguridad y Acceso (`sec_`)

#### Tabla: `sec_roles` (RF-003, RNF-031)
Roles institucionales con perfiles de autorización RBAC y SoD.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `codigo` | cadena(30) | PK | Código de rol (`SOLICITANTE_ASISTENCIAL`, etc.) |
| `nombre` | cadena(100) | NOT NULL | Nombre descriptivo del rol |
| `descripcion` | cadena(255) | NULL | Alcance de responsabilidades |
| `permisos_json` | json | NOT NULL | Matriz de permisos funcionales |

#### Tabla: `sec_usuarios` (RF-003, RNF-030, RNF-031)
Cuentas de usuario integradas con Directorio Activo/LDAP corporativo.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `username_ldap` | cadena(50) | NOT NULL, UNIQUE | Identificador de red corporativa |
| `email_corporativo` | cadena(100) | NOT NULL, UNIQUE | Correo institucional para notificaciones |
| `nombre_completo` | cadena(150) | NOT NULL | Nombre profesional del funcionario |
| `sede_id` | entero | NOT NULL, FK $\rightarrow$ `org_sedes` | Hospital de radicación principal |
| `servicio_id` | entero | NOT NULL, FK $\rightarrow$ `org_servicios` | Servicio de adscripción |
| `rol_codigo` | cadena(30) | NOT NULL, FK $\rightarrow$ `sec_roles` | Perfil de privilegios |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'activo' | CHECK in ('activo', 'inactivo') |
| `ultimo_acceso_at` | fecha_hora | NULL | Registro de auditoría de conexión |
| `created_at` | fecha_hora | NOT NULL | Fecha de alta en el sistema |

---

### 2.3 Módulo Catálogos y Políticas de SLA (`cat_` y `sla_`)

#### Tabla: `cat_solicitud_tipos` (RF-010)
Clasificación tipificada de requerimientos por unidad técnica resolutora.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `servicio_resolutor_id` | entero | NOT NULL, FK $\rightarrow$ `org_servicios` | Área técnica responsable |
| `codigo_tipo` | cadena(20) | NOT NULL | Código tipificado (ej. `BIO-FALLA-VENT`) |
| `nombre` | cadena(120) | NOT NULL | Nombre de la avería o requerimiento |
| `descripcion` | texto | NULL | Guía de diagnóstico inicial |
| `prioridad_sugerida` | cadena(20) | NOT NULL | CHECK in ('VITAL', 'ALTA', 'MEDIA', 'BAJA') |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'activo' | CHECK in ('activo', 'inactivo') |
| *Restricción única:* | | UNIQUE (`servicio_resolutor_id`, `codigo_tipo`) | Código único por especialidad |

#### Tabla: `cat_causales` (RF-014)
Catálogo estandarizado de motivos para suspensiones, rechazos y reaperturas.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `codigo` | cadena(30) | PK | Código de causal (ej. `FALTA_REPUESTO_BODEGA`) |
| `tipo_causal` | cadena(30) | NOT NULL | CHECK in ('SUSPENSION_ESPERA', 'RECHAZO_TECNICO', 'CANCELACION_USUARIO', 'REAPERTURA') |
| `descripcion` | cadena(200) | NOT NULL | Descripción formal de la justificación |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'activo' | CHECK in ('activo', 'inactivo') |

#### Tabla: `sla_calendarios` (RF-013)
Definición de calendarios para cómputo dual de tiempos de SLA.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `codigo_calendario` | cadena(30) | PK | CHECK in ('CONTINUO_24_7', 'HABIL_ADMINISTRATIVO') |
| `nombre` | cadena(100) | NOT NULL | Nombre descriptivo del calendario |
| `hora_inicio` | hora | NOT NULL | Hora de inicio jornada (ej. 08:00:00) |
| `hora_fin` | hora | NOT NULL | Hora de fin jornada (ej. 17:00:00) |
| `dias_laborales_semana`| cadena(20) | NOT NULL | Días aplicables (ej. '1,2,3,4,5' para Lun-Vie) |

#### Tabla: `sla_dias_inhabiles` (RF-013)
Días feriados y festividades que pausan el cómputo hábil administrativo.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `fecha` | fecha | NOT NULL | Día festivo inhábil |
| `descripcion` | cadena(100) | NOT NULL | Motivo del feriado institucional |
| `sede_id` | entero | NULL, FK $\rightarrow$ `org_sedes` | NULL si es feriado general; sede si es local |
| *Restricción única:* | | UNIQUE (`fecha`, `sede_id`) | No duplicar feriados por sede |

#### Tabla: `sla_politicas` (RF-011, RF-012)
Tiempos límites máximos de respuesta (MTTA) y resolución (MTTR).
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero | PK | Identificador surrogate |
| `tipo_solicitud_id` | entero | NOT NULL, FK $\rightarrow$ `cat_solicitud_tipos` | Tipo de requerimiento |
| `prioridad_asistencial` | cadena(20) | NOT NULL | CHECK in ('VITAL', 'ALTA', 'MEDIA', 'BAJA') |
| `tiempo_max_respuesta_mtta_min` | entero | NOT NULL, CHECK > 0 | Plazo máx. primera asignación (minutos) |
| `tiempo_max_resolucion_mttr_min` | entero | NOT NULL, CHECK > 0 | Plazo máx. resolución final (minutos) |
| `calendario_codigo` | cadena(30) | NOT NULL, FK $\rightarrow$ `sla_calendarios` | Calendario aplicable (24/7 vs hábil) |
| *Restricción única:* | | UNIQUE (`tipo_solicitud_id`, `prioridad_asistencial`) | Una política por combinación |

---

### 2.4 Módulo Transaccional de Solicitudes (`sol_`)

#### Tabla: `sol_solicitudes` (RF-020 a RF-030, RN-001, RN-005, D-01)
Entidad transaccional central del ciclo de vida de requerimientos.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK | Identificador surrogate primario |
| `codigo_correlativo` | cadena(20) | NOT NULL, UNIQUE | Formato `H{sede}-{año}-{secuencial}` (RN-001) |
| `sede_id` | entero | NOT NULL, FK $\rightarrow$ `org_sedes` | Sede hospitalaria generadora |
| `servicio_solicitante_id`| entero | NOT NULL, FK $\rightarrow$ `org_servicios` | Unidad clínica que requiere el servicio |
| `servicio_resolutor_id` | entero | NOT NULL, FK $\rightarrow$ `org_servicios` | Unidad técnica que debe atender |
| `tipo_solicitud_id` | entero | NOT NULL, FK $\rightarrow$ `cat_solicitud_tipos` | Clasificación tipificada |
| `ubicacion_especifica` | cadena(150) | NOT NULL | Quirófano, sala, piso o cama clínica |
| `prioridad_asistencial` | cadena(20) | NOT NULL | CHECK in ('VITAL', 'ALTA', 'MEDIA', 'BAJA') |
| `descripcion_falla` | texto | NOT NULL | Detalle técnico (estricto Zero-PHI, RNF-035) |
| `estado` | cadena(30) | NOT NULL, DEFAULT 'Registrada' | CHECK in ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada') |
| `es_expedita` | booleano | NOT NULL, DEFAULT false | Indica si proviene de radicación rápida (RF-021) |
| `usuario_solicitante_id`| entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Funcionario que radicó la orden |
| `tecnico_asignado_id` | entero | NULL, FK $\rightarrow$ `sec_usuarios` | Técnico resolutor responsable directo |
| `cuadrilla_asignada_id` | entero | NULL, FK $\rightarrow$ `org_cuadrillas_guardia` | Cuadrilla de turno activa asignada |
| `version` | entero | NOT NULL, DEFAULT 1 | Bloqueo optimista contra colisiones (D-01) |
| `fecha_radicacion_utc` | fecha_hora | NOT NULL | Estampa de creación del servidor en UTC |
| `fecha_asignacion_utc` | fecha_hora | NULL | Estampa de asignación de técnico/cuadrilla |
| `fecha_inicio_proceso_utc`| fecha_hora | NULL | Estampa de pase a 'En Proceso' |
| `fecha_atencion_utc` | fecha_hora | NULL | Estampa de registro del informe de atención |
| `fecha_cierre_utc` | fecha_hora | NULL | Estampa de conformidad o auto-cierre |
| `updated_at_utc` | fecha_hora | NOT NULL | Estampa de última modificación |

#### Tabla: `sol_control_tiempo_sla` (RF-051, RF-052, RN-006)
Control temporal continuo y suspensión regulada de plazos de SLA.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `solicitud_id` | entero_grande | PK, FK $\rightarrow$ `sol_solicitudes` | Relación 1:1 con la solicitud |
| `calendario_codigo` | cadena(30) | NOT NULL, FK $\rightarrow$ `sla_calendarios` | Tipo de reloj temporal aplicado |
| `fecha_compromiso_mtta_utc`| fecha_hora | NOT NULL | Límite pactado para primera respuesta |
| `fecha_compromiso_mttr_utc`| fecha_hora | NOT NULL | Límite pactado para resolución final |
| `segundos_consumidos` | entero | NOT NULL, DEFAULT 0 | Tiempo transcurrido activo del reloj |
| `esta_pausado` | booleano | NOT NULL, DEFAULT false | True si está en estado 'En Espera' |
| `fecha_ultima_pausa_utc` | fecha_hora | NULL | Momento en que se congeló el reloj |
| `segundos_pausados_acumulados`| entero | NOT NULL, DEFAULT 0 | Sumatoria total de tiempo suspendido |
| `fecha_tentativa_reanudacion` | fecha | NULL | Compromiso de reanudación (RN-006) |
| `alerta_75_emitida` | booleano | NOT NULL, DEFAULT false | Alerta preventiva disparada al 75% |
| `escalamiento_100_emitido`| booleano | NOT NULL, DEFAULT false | Escalamiento jerárquico disparado al 100% |

#### Tabla: `sol_historial_estados` (RF-040, RN-005, RN-012, RN-013)
Pista append-only inmutable de transiciones de estado deterministas.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK, append-only | Identificador de transición |
| `solicitud_id` | entero_grande | NOT NULL, FK $\rightarrow$ `sol_solicitudes` | Requerimiento intervenido |
| `estado_anterior` | cadena(30) | NOT NULL | Estado previo de la máquina finita |
| `estado_nuevo` | cadena(30) | NOT NULL | Estado posterior de la máquina finita |
| `causal_codigo` | cadena(30) | NULL, FK $\rightarrow$ `cat_causales` | Causal estandarizada (obligatoria en suspensión/rechazo) |
| `motivo_texto` | texto | NULL | Justificación textual ($\ge 20$ car. en rechazo/canc.) |
| `usuario_id` | entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Funcionario autor de la transición |
| `ip_origen` | cadena(45) | NOT NULL | Dirección IP institucional del cliente |
| `created_at_utc` | fecha_hora | NOT NULL | Estampa temporal UTC del servidor |

#### Tabla: `sol_informes_atencion` (RF-044, RN-007)
Informe técnico obligatorio previo a la transición a 'Atendida' o 'Cerrada'.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `solicitud_id` | entero_grande | PK, FK $\rightarrow$ `sol_solicitudes` | Relación 1:1 obligatoria |
| `diagnostico_falla` | texto | NOT NULL | Causa raíz técnica identificada |
| `acciones_ejecutadas` | texto | NOT NULL | Reparaciones y calibraciones efectuadas |
| `tiempo_real_minutos` | entero | NOT NULL, CHECK > 0 | Dedicación efectiva del personal técnico |
| `condicion_final_activo` | cadena(100) | NOT NULL | Condición operativa resultante del equipo |
| `repuestos_utilizados_json`| json | NOT NULL | Insumos usados (o `[]` si no requirió) |
| `tecnico_responsable_id` | entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Profesional que certifica el trabajo |
| `created_at_utc` | fecha_hora | NOT NULL | Fecha y hora de finalización técnica |

#### Tabla: `sol_reaperturas` (RF-047, RN-010)
Registro de reaperturas excepcionales dentro de la ventana de 48 horas post-atención.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK | Identificador de reapertura |
| `solicitud_id` | entero_grande | NOT NULL, FK $\rightarrow$ `sol_solicitudes` | Solicitud reabierta |
| `causal_codigo` | cadena(30) | NOT NULL, FK $\rightarrow$ `cat_causales` | Causal de inconformidad catalogada |
| `motivo_inconformidad` | texto | NOT NULL | Justificación detallada ($\ge 20$ caracteres) |
| `usuario_solicitante_id`| entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Solicitante que rechaza el cierre |
| `created_at_utc` | fecha_hora | NOT NULL | Estampa UTC ($\le 48\text{ h}$ post-atención) |

#### Tabla: `sol_reasignaciones` (RF-031, RN-008)
Auditoría formal de cambios de técnico responsable o cuadrilla asignada.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK | Identificador de reasignación |
| `solicitud_id` | entero_grande | NOT NULL, FK $\rightarrow$ `sol_solicitudes` | Solicitud reasignada |
| `tecnico_anterior_id` | entero | NULL, FK $\rightarrow$ `sec_usuarios` | Técnico saliente |
| `tecnico_nuevo_id` | entero | NULL, FK $\rightarrow$ `sec_usuarios` | Técnico entrante |
| `cuadrilla_anterior_id` | entero | NULL, FK $\rightarrow$ `org_cuadrillas_guardia` | Cuadrilla saliente |
| `cuadrilla_nueva_id` | entero | NULL, FK $\rightarrow$ `org_cuadrillas_guardia` | Cuadrilla entrante |
| `motivo` | texto | NOT NULL | Causal justificada del cambio de guardia |
| `usuario_autorizador_id`| entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Supervisor que autoriza el cambio |
| `created_at_utc` | fecha_hora | NOT NULL | Estampa temporal de la reasignación |

#### Tabla: `sol_bitacora` (RF-060)
Bitácora cronológica inmutable append-only para notas técnicas y seguimiento.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK, append-only | Identificador de entrada de bitácora |
| `solicitud_id` | entero_grande | NOT NULL, FK $\rightarrow$ `sol_solicitudes` | Solicitud asociada |
| `tipo_entrada` | cadena(30) | NOT NULL | CHECK in ('NOTA_TECNICA', 'CAMBIO_GUARDIA', 'PIEZA_SOLICITADA', 'COMUNICACION') |
| `contenido` | texto | NOT NULL | Texto informativo del evento técnico |
| `usuario_id` | entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Autor de la nota |
| `ip_origen` | cadena(45) | NOT NULL | Dirección IP del cliente |
| `created_at_utc` | fecha_hora | NOT NULL | Estampa temporal del servidor en UTC |

#### Tabla: `sol_adjuntos` (RF-022, Restricción Técnica 5, D-04)
Metadatos y referencias URI de evidencias técnicas desacopladas (hasta 5 MB).
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK | Identificador del adjunto |
| `solicitud_id` | entero_grande | NOT NULL, FK $\rightarrow$ `sol_solicitudes` | Solicitud que lo anexa |
| `nombre_archivo_original`| cadena(255) | NOT NULL | Nombre provisto por el usuario |
| `uuid_almacenamiento` | cadena(36) | NOT NULL, UNIQUE | Nombre físico ofuscado (UUID v4) |
| `tipo_mime_real` | cadena(100) | NOT NULL | Inspección de magic numbers |
| `extension` | cadena(10) | NOT NULL | CHECK in ('pdf', 'jpg', 'jpeg', 'png') |
| `tamano_bytes` | entero_grande | NOT NULL | CHECK (tamano_bytes > 0 AND tamano_bytes <= 5242880) |
| `hash_sha256` | cadena(64) | NOT NULL | Verificador criptográfico de integridad |
| `uri_almacenamiento` | cadena(500) | NOT NULL | Ruta en NAS/NFS o URI en MinIO S3 |
| `usuario_id` | entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Funcionario que subió la evidencia |
| `created_at_utc` | fecha_hora | NOT NULL | Estampa temporal de subida |

---

### 2.5 Módulo Operacional y Auditoría Legal (`ops_` y `audit_`)

#### Tabla: `ops_idempotencia` (RF-023, RN-011, D-03)
Registro de tokens de idempotencia para protección contra reintentos de red Wi-Fi.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `idempotency_key` | cadena(36) | PK | Clave UUID v4 enviada en cabecera HTTP |
| `usuario_id` | entero | NOT NULL, FK $\rightarrow$ `sec_usuarios` | Usuario emisor de la petición |
| `recurso_tipo` | cadena(50) | NOT NULL | 'RADICACION_SOLICITUD', 'TRANSICION_ESTADO' |
| `request_hash_sha256` | cadena(64) | NOT NULL | Hash del cuerpo HTTP entrante |
| `response_status_http` | entero | NOT NULL | Código de respuesta generado (200, 201) |
| `response_body_json` | json | NOT NULL | Payload de respuesta original serializado |
| `created_at_utc` | fecha_hora | NOT NULL | Fecha de creación del registro |
| `expires_at_utc` | fecha_hora | NOT NULL | Expiración TTL (24 horas tras creación) |

#### Tabla: `ops_outbox_eventos` (RF-052, RF-053, RNF-041, D-06)
Patrón Transactional Outbox para eventos de SLA y notificaciones asíncronas.
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK | Identificador secuencial del evento |
| `tipo_evento` | cadena(50) | NOT NULL | CHECK in ('ALERTA_SLA_75', 'ESCALAMIENTO_SLA_100', 'DESPACHO_EMAIL_SMTP', 'AUTO_CIERRE_48H') |
| `agregado_tipo` | cadena(50) | NOT NULL | 'SOLICITUD', 'USUARIO' |
| `agregado_id` | entero_grande | NOT NULL | Identificador de la solicitud asociada |
| `payload_json` | json | NOT NULL | Parámetros del evento para el worker |
| `estado` | cadena(20) | NOT NULL, DEFAULT 'PENDIENTE' | CHECK in ('PENDIENTE', 'PROCESANDO', 'ENVIADO', 'FALLIDO') |
| `reintentos` | entero | NOT NULL, DEFAULT 0 | Contador de intentos de entrega |
| `error_mensaje` | texto | NULL | Traza de error en caso de fallo SMTP |
| `scheduled_at_utc` | fecha_hora | NOT NULL | Momento programado para ejecución |
| `processed_at_utc` | fecha_hora | NULL | Estampa en que se completó el evento |
| `created_at_utc` | fecha_hora | NOT NULL | Fecha de inserción transaccional |

#### Tabla: `audit_eventos` (RF-072, RN-012, RN-013)
Pista legal de auditoría del sistema hospitalario (retención mínima de 5 años).
| Atributo | Dominio Lógico | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | entero_grande | PK, append-only | Identificador de auditoría |
| `usuario_id` | entero | NOT NULL | Usuario ejecutor de la operación |
| `ip_origen` | cadena(45) | NOT NULL | Dirección IP del cliente |
| `accion` | cadena(50) | NOT NULL | 'CREAR', 'ASIGNAR', 'TRANSICIONAR', 'REABRIR' |
| `tabla_afectada` | cadena(50) | NOT NULL | Tabla sobre la que se actuó |
| `registro_id` | entero_grande | NOT NULL | Identificador del registro modificado |
| `datos_anteriores_json`| json | NULL | Estado previo del registro |
| `datos_nuevos_json` | json | NULL | Estado resultante tras la transacción |
| `hash_encadenado_sha256`| cadena(64) | NOT NULL | Firma criptográfica encadenada de no repudio |
| `correlation_id` | cadena(36) | NULL | Identificador distribuido `X-Correlation-ID` |
| `created_at_utc` | fecha_hora | NOT NULL | Estampa temporal inmutable del servidor |

---

## 3. Matriz de Claves Foráneas y Acciones Referenciales

| Tabla Origen | Clave Foránea (FK) | Tabla Destino | Acción ON DELETE | Acción ON UPDATE | Justificación de Negocio |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `org_servicios` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | No se puede eliminar una sede con servicios activos. |
| `sec_usuarios` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | Un usuario debe estar ligado a una sede válida. |
| `sec_usuarios` | `servicio_id` | `org_servicios` | `RESTRICT` | `CASCADE` | Un usuario pertenece a un servicio existente. |
| `sec_usuarios` | `rol_codigo` | `sec_roles` | `RESTRICT` | `CASCADE` | El rol debe existir en el catálogo RBAC. |
| `cat_solicitud_tipos` | `servicio_resolutor_id` | `org_servicios` | `RESTRICT` | `CASCADE` | Las fallas tipificadas pertenecen a áreas técnicas reales. |
| `sla_politicas` | `tipo_solicitud_id` | `cat_solicitud_tipos` | `RESTRICT` | `CASCADE` | Una política de SLA requiere un tipo de solicitud válido. |
| `sol_solicitudes` | `sede_id` | `org_sedes` | `RESTRICT` | `CASCADE` | Una solicitud pertenece a una sede hospitalaria inmutable. |
| `sol_solicitudes` | `servicio_solicitante_id` | `org_servicios` | `RESTRICT` | `CASCADE` | El servicio clínico solicitante es obligatorio. |
| `sol_solicitudes` | `servicio_resolutor_id` | `org_servicios` | `RESTRICT` | `CASCADE` | El servicio técnico resolutor es obligatorio. |
| `sol_solicitudes` | `tipo_solicitud_id` | `cat_solicitud_tipos` | `RESTRICT` | `CASCADE` | Requerimiento catalogado obligatorio. |
| `sol_solicitudes` | `usuario_solicitante_id`| `sec_usuarios` | `RESTRICT` | `CASCADE` | El autor de la radicación es rastreable (no repudio). |
| `sol_solicitudes` | `tecnico_asignado_id` | `sec_usuarios` | `SET NULL` | `CASCADE` | Si un técnico se desvincula, la solicitud queda reasignable. |
| `sol_control_tiempo_sla`| `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Integridad 1:1 con el requerimiento. |
| `sol_historial_estados` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Inmutabilidad: no se eliminan historiales. |
| `sol_informes_atencion` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | El informe está atado a la solicitud de forma indisoluble. |
| `sol_adjuntos` | `solicitud_id` | `sol_solicitudes` | `RESTRICT` | `CASCADE` | Los adjuntos pertenecen a la solicitud y no se borran en cascada. |

---

## 4. Normalización y Consistencia Lógica

- El modelo alcanza **Tercera Forma Normal (3FN)** completa y forma **BCNF**:
  - No existen grupos repetitivos (1FN).
  - No existen dependencias funcionales parciales sobre claves compuestas (2FN).
  - No existen dependencias transitivas entre atributos no clave (3FN): las entidades maestras (`org_sedes`, `org_servicios`, `cat_solicitud_tipos`, `sla_calendarios`) aíslan sus propios atributos sin redundancia en `sol_solicitudes`.
- Los datos variables no predecibles (repuestos utilizados, parámetros de eventos y matrices de permisos) se modelan conceptualmente en estructuras estructuradas de dominio `json` sin violar la atomicidad relacional de los atributos de consulta principal.

---

## 5. Conclusión y Traspaso de Etapa

El modelo lógico relacional del **Sistema S3IH** formaliza 16 tablas con todas sus columnas, claves primarias, claves foráneas, restricciones de unicidad, no nulidad y reglas de chequeo lógico, listo para su validación de normalización formal.

El siguiente paso obligatorio corresponde a:
> **Paso 05 — Normalización (`04_normalizacion/informe_normalizacion.md`):**  
> Someter el modelo lógico a la verificación rigurosa de 1FN, 2FN, 3FN y BCNF, analizando dependencias funcionales y justificando la ausencia de anomalías de inserción, actualización o borrado bajo el skill `database-schema-designer`.
