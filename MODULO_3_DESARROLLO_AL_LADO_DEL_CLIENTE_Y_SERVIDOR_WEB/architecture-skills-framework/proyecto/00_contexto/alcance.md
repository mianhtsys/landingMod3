# Alcance

## Incluye
- **Gestión de Identidad y Accesos:** Autenticación institucional y control de acceso basado en roles (RBAC) bajo el principio de mínimo privilegio para personal asistencial, técnico, supervisores y administradores.
- **Topología Multisede Hospitalaria:** Cobertura de una red de hasta 4 hospitales (hospital central y hasta 3 sedes periféricas en crecimiento), soportando servicios asistenciales, técnicos y administrativos por cada hospital.
- **Catálogo Tipificado de Solicitudes:** Clasificación estandarizada de solicitudes internas por especialidad (Mantenimiento Biomédico, Infraestructura/Servicios Generales, Farmacia Interna, Soporte TI Hospitalario, Central de Esterilización) con prioridades asistenciales (Urgente Asistencial / Vital, Alta, Media, Baja).
- **Radicación con Código Correlativo Único:** Generación de identificador inmutable por sede hospitalaria, registro de detalles técnicos, ubicación precisa (área/quirófano/cama) y soporte de radicación expedita para emergencias clínicas.
- **Asignación Flexible y Control de Turnos:** Asignación y reasignación de solicitudes a técnicos individuales o cuadrillas/equipos de guardia en turno activo, previniendo condiciones de carrera durante cambios de turno.
- **Ciclo de Vida y Flujo de Estados:** Control estricto de transiciones de estado: Registrada, Asignada, En Proceso, En Espera (con suspensión justificada de SLA), Atendida, Cerrada, Rechazada (con causal documentada) y Cancelada.
- **Gestión Temporal y Motor de SLA:** Cómputo diferenciado de tiempos de respuesta (MTTA) y resolución (MTTR) bajo dos calendarios: continuo 24/7 para servicios asistenciales críticos vs. calendario hábil para áreas administrativas.
- **Sistema de Alertas y Escalamiento:** Notificaciones automáticas por proximidad a vencimiento (ej. 75% del tiempo consumido) y escalamiento jerárquico automático ante incumplimiento de plazos asistenciales.
- **Bitácora y Resguardo de Evidencias:** Registro cronológico de notas técnicas, intervenciones y adjuntos multimedia (fotografías de averías, fichas técnicas, órdenes de servicio en PDF/JPG/PNG) con límites estrictos de tamaño.
- **Reapertura Controlada:** Mecanismo excepcional para reabrir solicitudes atendidas o cerradas dentro de una ventana máxima de 48 horas post-cierre, con justificación formal obligatoria.
- **Auditoría Inmutable:** Registro detallado y no repudiable de todas las operaciones críticas con estampa de tiempo del servidor e identificación de usuario.
- **Reportes y Paneles de Control:** Generación de indicadores operativos por sede y servicio (cumplimiento de SLA, tiempos de resolución, carga de trabajo por técnico).

## Fuera de alcance
- **Historia Clínica Electrónica (EHR/HIS):** No incluye la administración de expedientes clínicos ni el registro de datos de salud protegidos de pacientes (PHI).
- **Atención y Agendamiento Ambulatorio:** Fuera del alcance la citación médica, triaje clínico de pacientes o admisión ambulatoria externa.
- **Facturación y Cobranza:** No contempla procesos de facturación a pacientes, empresas aseguradoras ni liquidación de prestaciones médicas.
- **Contratación Pública y Adquisiciones Mayores:** Fuera del alcance los flujos de licitación, cotizaciones a gran escala y contratos corporativos de adquisición de equipamiento médico pesado.
- **Telemetría Médica en Tiempo Real:** No contempla la recepción directa ni el procesamiento de señales fisiológicas de sensores o monitores de signos vitales de cabecera.
- **Gestión de Recursos Humanos General:** No administra contratos laborales, nóminas ni cálculo de salarios del personal hospitalario.

## Integraciones actuales
- **Servicio de Directorio Institucional Corporativo:** Integración para autenticación unificada del personal activo y validación de credenciales (LDAP / Active Directory / SAML / OIDC).
- **Servidor Corporativo de Notificaciones / SMTP:** Integración asíncrona para despacho de avisos, notificaciones de asignación y alertas de vencimiento de SLA.
- **Repositorio de Almacenamiento Institucional de Archivos:** Repositorio desacoplado para el almacenamiento seguro y persistente de fotografías y documentos técnicos adjuntos.

## Integraciones futuras
- **Sistema de Gestión de Activos Fijos y Mantenimiento Mayor (ERP Hospitalario):** Enlace para vincular automáticamente códigos patrimoniales de equipos biomédicos y consultar garantías de fábrica vigentes.
- **Sistema de Información Hospitalaria (HIS):** Sincronización unidireccional del catálogo maestro de camas, quirófanos y salas asistenciales activas.
- **Pasarela de Mensajería Push Institucional:** Envío de notificaciones inmediatas a terminales móviles o receptores de guardia en turnos asistenciales 24/7.
