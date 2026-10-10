# Paso 02 — Modelo Conceptual de Datos

**Workflow:** `02_database_workflow` — Paso 02  
**Skill utilizado:** `database-schema-designer` (instalado en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entrada:** `01_requisitos_datos/requisitos_datos.md`, decisiones vigentes en `04_decisiones/`, ADR-001  
**Restricción de esta fase:** Sin sentencias SQL, sin tipos específicos de motor (VARCHAR, INT, etc.), sin llaves primarias/foráneas físicas. Exclusivamente entidades del dominio, atributos conceptuales, relaciones, cardinalidades y reglas de negocio conceptuales.  

---

## 1. Alcance y Contexto Conceptual

El modelo conceptual captura la realidad operativa de la red hospitalaria de hasta 4 hospitales para la gestión, seguimiento, auditoría y resolución de solicitudes internas entre servicios clínicos asistenciales (Urgencias, Quirófano, UCI, etc.) y unidades resolutoras técnicas (Biomédico, Infraestructura, Farmacia Interna, Soporte TI y Esterilización).

El modelo prioriza la **integridad de estados en soporte vital (RN-005)**, la **concurrencia de guardias 24/7**, el **cómputo de SLA dual**, la **inmutabilidad y no repudio legal (RN-012/013)** y el **aislamiento estricto de historias clínicas (Zero-PHI, RNF-035)**.

---

## 2. Diccionario de Entidades Conceptuales

---

### 2.1 Dominio de Organización Multisede y Recursos Humanos (RF-001 a RF-004)

#### Entidad: SedeHospitalaria
Representa cada uno de los centros hospitalarios físicos que integran la red de salud (hospital central y sedes periféricas).
- **Atributos Conceptuales:** Identificador, código institucional (único, ej. `H1` a `H4`), nombre del hospital, dirección física, teléfono institucional, es sede central (booleano), estado operativo.
- **Reglas Conceptuales:**
  - Red dimensionada para un máximo de 4 hospitales (2 iniciales).
  - El código institucional es inmutable y forma el prefijo del código correlativo de solicitudes (RN-001).

#### Entidad: ServicioHospitalario
Representa cada departamento, unidad asistencial o servicio técnico dentro de un hospital.
- **Atributos Conceptuales:** Identificador, sede hospitalaria a la que pertenece, código de servicio, nombre del servicio, tipo de servicio (Asistencial vs. Resolutor Técnico), ubicación física detallada (piso, ala, pabellón), estado operativo.
- **Reglas Conceptuales:**
  - Un servicio pertenece a exactamente una sede hospitalaria.
  - Los servicios asistenciales generan solicitudes; los servicios resolutores diagnostican y atienden solicitudes.

#### Entidad: Usuario
Personal asistencial, técnico, supervisor o administrativo con acceso al sistema.
- **Atributos Conceptuales:** Identificador, nombre de usuario corporativo (LDAP), correo electrónico institucional, nombre y apellidos completos, sede hospitalaria principal, servicio de adscripción, rol asignado, estado activo/inactivo, estampa de último acceso.
- **Reglas Conceptuales:**
  - Autenticado contra el Directorio Activo institucional (RNF-030).
  - Toda acción en el sistema debe asociarse de forma no repudiable a un usuario identificado (RN-013).

#### Entidad: Rol
Perfil funcional que define los privilegios del usuario bajo el principio de mínimo privilegio (RNF-031).
- **Atributos Conceptuales:** Código de rol (`SOLICITANTE_ASISTENCIAL`, `TECNICO_RESOLUTOR`, `SUPERVISOR_GUARDIA`, `ADMINISTRADOR_TI`, `AUDITOR_CALIDAD`), nombre descriptivo, conjunto de permisos operativos.
- **Reglas Conceptuales:**
  - Segregación estricta de funciones (SoD): un técnico no puede auto-aprobarse solicitudes; un solicitante no puede cambiar estados técnicos una vez iniciada la atención.

#### Entidad: CuadrillaGuardia y CuadrillaMiembro
Equipos técnicos de turno rotativo 24/7 por especialidad resolutora y sede.
- **Atributos Conceptuales:**
  - *CuadrillaGuardia:* Identificador, sede hospitalaria, servicio resolutor asociado, nombre de cuadrilla, turno operativo (mañana, tarde, noche, guardia 24h), estado.
  - *CuadrillaMiembro:* Cuadrilla de guardia, técnico resolutor asignado.
- **Reglas Conceptuales:**
  - Permite la asignación ágil de órdenes a un equipo de guardia completo cuando no se designa un técnico individual (RF-004, RF-030).

---

### 2.2 Dominio de Catálogos Tipificados y Políticas de SLA (RF-010 a RF-014)

#### Entidad: CatalogoSolicitudTipo
Clasificación tipificada y estandarizada de requerimientos técnicos e incidencias por especialidad.
- **Atributos Conceptuales:** Identificador, servicio resolutor competente, código tipificado, nombre de la solicitud/falla, descripción del alcance, prioridad asistencial sugerida por defecto, estado.
- **Reglas Conceptuales:**
  - Estandariza las solicitudes evitando descripciones ambiguas en averías biomédicas o edilicias.

#### Entidad: PoliticaSLA
Reglas temporales máximas de primera respuesta (MTTA) y resolución (MTTR) acordadas por tipo de requerimiento y prioridad.
- **Atributos Conceptuales:** Identificador, tipo de solicitud, prioridad asistencial (Vital/Urgente, Alta, Media, Baja), tiempo máximo de primera respuesta (MTTA en minutos), tiempo máximo de resolución final (MTTR en minutos), estado.
- **Reglas Conceptuales:**
  - El cumplimiento de estas metas determina las alertas al 75% y el escalamiento jerárquico (RF-012, RF-052).

#### Entidad: CalendarioSLA y DiaInhabilFeriado
Define el cómputo temporal diferenciado del reloj de SLA (RF-013).
- **Atributos Conceptuales:**
  - *CalendarioSLA:* Código de calendario (`CONTINUO_24_7` vs. `HABIL_ADMINISTRATIVO`), hora inicio jornada, hora fin jornada, días laborales de la semana.
  - *DiaInhabilFeriado:* Fecha del calendario, descripción del feriado, sede específica (o aplicable a toda la red).
- **Reglas Conceptuales:**
  - Para prioridades *Vital* y *Alta*, el reloj de SLA computa de forma ininterrumpida los 365 días del año las 24 horas del día.
  - Para prioridades *Media* y *Baja*, el reloj computa exclusivamente en horario hábil administrativo descontando fines de semana y feriados.

#### Entidad: CausalEstandarizada
Catálogo de justificaciones obligatorias para eventos excepcionales del ciclo de vida (RF-014).
- **Atributos Conceptuales:** Código de causal, tipo de causal (`SUSPENSION_ESPERA`, `RECHAZO_TECNICO`, `CANCELACION_USUARIO`, `REAPERTURA`), descripción estandarizada, estado.
- **Reglas Conceptuales:**
  - Toda suspensión a 'En Espera', rechazo, cancelación o reapertura debe vincularse obligatoriamente a una causal catalogada más su texto explicativo detallado (RN-006, RN-009).

---

### 2.3 Dominio Central: Solicitudes y Concurrencia (RF-020 a RF-033)

#### Entidad: Solicitud
Entidad transaccional central que representa el requerimiento de servicio hospitalario.
- **Atributos Conceptuales:**
  - Identificador conceptual único.
  - Código correlativo institucional inmutable (`H1-2026-XXXXXX`, RN-001).
  - Sede hospitalaria de origen.
  - Servicio asistencial solicitante.
  - Servicio resolutor competente.
  - Tipo de solicitud tipificada.
  - Ubicación física precisa (área, sala, quirófano, cama clínica).
  - Nivel de prioridad asistencial (Vital, Alta, Media, Baja, RN-004).
  - Descripción técnica detallada del problema o insumo requerido (Zero-PHI, RNF-035).
  - Estado del ciclo de vida actual (Registrada, Asignada, En Proceso, En Espera, Atendida, Cerrada, Rechazada, Cancelada).
  - Es radicación expedita (booleano, RF-021).
  - Usuario solicitante.
  - Técnico resolutor individual asignado (opcional si asignada a cuadrilla).
  - Cuadrilla de guardia asignada (opcional si asignada a técnico).
  - Versión de concurrencia optimista (`version` numérico incremental, D-01).
  - Fechas e hitos temporales del servidor en UTC (radicación, asignación, inicio proceso, atención, cierre).
- **Reglas Conceptuales:**
  - El código correlativo es inmutable y único por sede y año (RN-001).
  - Prohibición de borrado físico: una solicitud nunca se elimina (RN-012).
  - Concurrencia optimista: cualquier actualización valida la versión leída previamente para prevenir colisiones durante cambios de guardia concurrentes.

#### Entidad: ControlTiempoSLA
Seguimiento dinámico del estado temporal del requerimiento frente a la política de SLA.
- **Atributos Conceptuales:** Solicitud asociada, calendario aplicable, fecha y hora límite de asignación (MTTA), fecha y hora límite de resolución (MTTR), tiempo total consumido acumulado (en segundos), está pausado actualmente (booleano), fecha y hora de la última pausa, tiempo total pausado acumulado (en segundos), fecha tentativa de reanudación, umbral de alerta 75% alcanzado (booleano), escalamiento 100% ejecutado (booleano).
- **Reglas Conceptuales:**
  - Al transicionar a 'En Espera', se activa la pausa y se congela el cómputo de SLA (RN-006).
  - Al reanudar a 'En Proceso', se suma el intervalo pausado al acumulado de pausas y se posterga la fecha límite de MTTR de forma exacta.

---

### 2.4 Dominio de Ciclo de Vida, Bitácora e Informes (RF-040 a RF-047, RF-060)

#### Entidad: HistorialEstadoSolicitud (Append-Only)
Registro inalterable de cada una de las transiciones de estado por las que atraviesa una solicitud.
- **Atributos Conceptuales:** Identificador de transición, solicitud asociada, estado de origen, estado de destino, causal estandarizada aplicada, justificación textual obligatoria, usuario que ejecutó la transición, dirección IP corporativa de origen, estampa temporal del servidor en UTC.
- **Reglas Conceptuales:**
  - Estrictamente append-only: inserción continua sin posibilidad de modificación ni eliminación física (RN-012, RN-013).
  - Valida que la transición se ajuste estrictamente a la máquina de estados determinista (RN-005).

#### Entidad: ReasignacionSolicitud
Registro de auditoría específico para cambios de técnico o cuadrilla responsable (RF-031).
- **Atributos Conceptuales:** Solicitud, técnico/cuadrilla anterior, técnico/cuadrilla nuevo, motivo formal de la reasignación, usuario autorizador, estampa temporal UTC.

#### Entidad: InformeAtencionTecnica
Documentación obligatoria del trabajo realizado por el técnico antes de dar por atendida la solicitud (RF-044, RN-007).
- **Atributos Conceptuales:** Solicitud asociada (relación 1:1), diagnóstico técnico final de la avería, descripción detallada de acciones correctivas ejecutadas, tiempo real de dedicación (minutos trabajados), condición operativa final del activo o área intervenida, repuestos e insumos utilizados (o declaración expresa de no requerir repuestos), estampa temporal de atención.
- **Reglas Conceptuales:**
  - Precondición ineludible: ninguna solicitud puede pasar al estado 'Atendida' o 'Cerrada' sin la existencia previa y validada de este informe (RN-007).

#### Entidad: ReaperturaSolicitud
Control de solicitudes reabiertas ante inconformidad asistencial (RF-047, RN-010).
- **Atributos Conceptuales:** Solicitud, motivo justificado de la reapertura, usuario solicitante que reabre, estampa temporal UTC.
- **Reglas Conceptuales:**
  - Solo permitida dentro de una ventana máxima estricta de 48 horas continuas post-atención (RN-010). Transcurrido ese plazo, el sistema bloquea conceptualmente la reapertura.

#### Entidad: BitacoraNotaTecnica (Append-Only)
Línea de tiempo cronológica para seguimiento operativo y comunicaciones durante la atención (RF-060).
- **Atributos Conceptuales:** Solicitud, tipo de entrada (nota técnica, cambio de turno/guardia, repuesto pedido a bodega, comunicación con solicitante), contenido del mensaje, usuario autor, dirección IP, estampa temporal UTC.

---

### 2.5 Dominio de Evidencias Técnicas, Asincronía y Auditoría Legal (RF-022, RF-072)

#### Entidad: EvidenciaAdjunta
Metadatos de fotografías, órdenes de servicio o diagramas técnicos asociados a la solicitud.
- **Atributos Conceptuales:** Identificador, solicitud asociada, nombre de archivo original, identificador único de almacenamiento desacoplado (UUID v4), tipo de contenido MIME real verificado, extensión validada, tamaño en bytes (máximo $5.242.880\text{ bytes}$ / 5 MB), hash SHA-256 de integridad, URI del almacenamiento desacoplado, usuario que subió el archivo, estampa temporal.
- **Reglas Conceptuales:**
  - Cero BLOBs en tablas transaccionales (Restricción Técnica 5, D-04).
  - Máximo 5 archivos por solicitud.

#### Entidad: TransaccionIdempotencia
Control de deduplicación de peticiones originadas por fallas de red Wi-Fi hospitalaria (RN-011, D-03).
- **Atributos Conceptuales:** Clave única de idempotencia (UUID enviado por el cliente), usuario autor, tipo de recurso afectado, hash SHA-256 del contenido de la petición, código de respuesta HTTP retornado, cuerpo de respuesta serializado, estampa de creación, estampa de expiración (TTL de 24 horas).

#### Entidad: EventoOutbox
Desacoplamiento asíncrono de eventos de negocio y notificaciones (D-06).
- **Atributos Conceptuales:** Identificador, tipo de evento (`ALERTA_SLA_75`, `ESCALAMIENTO_SLA_100`, `DESPACHO_EMAIL_SMTP`, `AUTO_CIERRE_48H`), entidad agregada asociada, carga útil del evento (payload estructurado), estado de procesamiento (pendiente, procesado, fallido), cantidad de reintentos, estampa de creación, estampa programada de ejecución.

#### Entidad: AuditoriaEvento (Append-Only)
Pista legal de auditoría global del sistema para cumplimiento de normas de salud y acreditación hospitalaria por 5 años (RF-072, RN-013).
- **Atributos Conceptuales:** Identificador de evento, usuario autor, dirección IP corporativa, acción ejecutada, entidad afectada, identificador de la entidad, valores previos, valores posteriores, hash encadenado de no repudio, estampa temporal del servidor en UTC.

---

## 3. Matriz de Relaciones y Cardinalidades Conceptuales

| Entidad Origen | Cardinalidad | Entidad Destino | Descripción de la Relación |
| :--- | :---: | :--- | :--- |
| **SedeHospitalaria** | $1 : N$ | **ServicioHospitalario** | Una sede alberga múltiples servicios clínicos y técnicos. |
| **SedeHospitalaria** | $1 : N$ | **Usuario** | Una sede cuenta con múltiples usuarios adscritos. |
| **ServicioHospitalario** | $1 : N$ | **CuadrillaGuardia** | Un servicio resolutor coordina múltiples cuadrillas de turno. |
| **CuadrillaGuardia** | $N : M$ | **Usuario** | Mediante *CuadrillaMiembro*: un técnico participa en cuadrillas de guardia. |
| **ServicioHospitalario** | $1 : N$ | **CatalogoSolicitudTipo** | Un servicio resolutor tipifica sus solicitudes y fallas técnicas. |
| **CatalogoSolicitudTipo** | $1 : N$ | **PoliticaSLA** | Un tipo de solicitud posee políticas de tiempo por nivel de prioridad. |
| **SedeHospitalaria** | $1 : N$ | **Solicitud** | Una sede genera y centraliza solicitudes correlativas. |
| **ServicioHospitalario** | $1 : N$ | **Solicitud** (Solicitante) | Un servicio asistencial origina solicitudes de soporte. |
| **ServicioHospitalario** | $1 : N$ | **Solicitud** (Resolutor) | Un servicio técnico resuelve solicitudes de su competencia. |
| **Usuario** | $1 : N$ | **Solicitud** (Solicitante) | Un usuario radica requerimientos. |
| **Usuario** | $0..1 : N$ | **Solicitud** (Asignado) | Un técnico resolutor individual tiene solicitudes a su cargo. |
| **CuadrillaGuardia** | $0..1 : N$ | **Solicitud** (Asignada) | Una cuadrilla de turno asume solicitudes de guardia. |
| **Solicitud** | $1 : 1$ | **ControlTiempoSLA** | Cada solicitud posee exactamente un monitor temporal de SLA. |
| **Solicitud** | $1 : N$ | **HistorialEstadoSolicitud** | Una solicitud registra una o más transiciones inmutables. |
| **Solicitud** | $1 : 0..1$ | **InformeAtencionTecnica** | Una solicitud atendida posee exactamente un informe técnico. |
| **Solicitud** | $1 : 0..N$ | **ReaperturaSolicitud** | Una solicitud puede registrar reaperturas controladas ($\le 48\text{ h}$). |
| **Solicitud** | $1 : 0..5$ | **EvidenciaAdjunta** | Una solicitud puede contener hasta 5 archivos adjuntos técnicos. |
| **Solicitud** | $1 : N$ | **BitacoraNotaTecnica** | Una solicitud acumula notas técnicas y eventos de guardia. |
| **Solicitud** | $1 : N$ | **ReasignacionSolicitud** | Una solicitud audita cambios entre técnicos responsables. |

---

## 4. Reglas de Negocio Conceptuales Inviolables

1. **Inmutabilidad y Unicidad del Correlativo (RN-001):**
   - El código `H{sede}-{año}-{secuencial}` se genera de forma atómica e irreversible al momento de radicar y nunca cambia ni se reutiliza.
2. **Datos Mínimos Obligatorios (RN-002):**
   - Ninguna solicitud existe sin sede, servicio solicitante, servicio resolutor, ubicación física específica, prioridad asistencial y usuario autor.
3. **Máquina de Estados Finita Determinista (RN-005):**
   - Transiciones legales unidireccionales:
     - `Registrada` $\rightarrow$ `Asignada`, `Rechazada`, `Cancelada`.
     - `Asignada` $\rightarrow$ `En Proceso`, `Reasignada`, `Cancelada`.
     - `En Proceso` $\rightarrow$ `En Espera`, `Atendida`, `Reasignada`.
     - `En Espera` $\rightarrow$ `En Proceso`.
     - `Atendida` $\rightarrow$ `Cerrada`, `Reaperturada`.
     - `Reaperturada` $\rightarrow$ `En Proceso`.
     - `Cerrada` y `Rechazada` $\rightarrow$ Terminales inmutables definitivos.
4. **Pausa y Reanudación de SLA (RN-006):**
   - La transición a 'En Espera' suspende el reloj de SLA, exigiendo causal y fecha tentativa; al reanudar, se ajusta la fecha de vencimiento postergándola por el tiempo exacto pausado.
5. **Precondición Ineludible de Informe Técnico (RN-007):**
   - No se permite transicionar a `Atendida` sin el registro completo del informe de atención (horas dedicadas, repuestos y condición operativa final).
6. **Causal Obligatoria de Cancelación o Rechazo (RN-009):**
   - Exige causal catalogada y justificación textual $\ge 20$ caracteres.
7. **Ventana Rígida de Reapertura (RN-010):**
   - Bloqueada terminantemente tras 48 horas continuas de notificada la atención.
8. **Prohibición de Borrado Físico (RN-012, RN-013):**
   - Ninguna entidad del modelo conceptual admite eliminación física; solo bajas lógicas y estados terminales auditados por 5 años.
9. **Zero-PHI (RNF-035):**
   - Ninguna entidad contiene atributos destinados a expedientes clínicos, nombres de pacientes o datos protegidos de salud.

---

## 5. Conclusión y Traspaso de Etapa

El modelo conceptual del **Sistema S3IH** formaliza el 100% de los conceptos del dominio hospitalario, sus cardinalidades, reglas de negocio y garantías de integridad sin dependencias prematuras de sintaxis SQL.

El siguiente paso obligatorio corresponde a:
> **Paso 03 — Diagrama E-R (`02_modelo_conceptual/diagrama_er.md`):**  
> Representar el modelo conceptual en un diagrama visual Entidad-Relación versionable utilizando **Mermaid** y formalizando entidades, relaciones y cardinalidades bajo el skill `database-schema-designer`.
