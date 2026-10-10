# Requisitos funcionales

## Módulo 1: Identidad, Sedes y Servicios Hospitalarios
- **RF-001.** Administrar la estructura multisede de la red hospitalaria, soportando el alta, modificación y baja lógica de hasta 4 hospitales (hospital central y sedes periféricas).
- **RF-002.** Administrar el catálogo de servicios, departamentos y ubicaciones físicas internas por cada hospital de la red (ej. Quirófanos, UCI, Urgencias, Hospitalización, Farmacia, Mantenimiento Biomédico, Infraestructura, TI, Esterilización).
- **RF-003.** Administrar usuarios, perfiles, roles institucionales y asignación de personal a sedes y servicios específicos bajo políticas de mínimo privilegio (solicitante asistencial, técnico resolutor, supervisor de guardia, administrador del sistema).
- **RF-004.** Gestionar cuadrillas técnicas, equipos de guardia y turnos rotativos por sede y servicio resolutor para la asignación ágil de responsabilidades.

## Módulo 2: Catálogo de Solicitudes y Acuerdos de Nivel de Servicio (SLA)
- **RF-010.** Administrar el catálogo tipificado de solicitudes internas por especialidad técnica resolutora (mantenimiento biomédico, infraestructura edilicia, suministros urgentes de farmacia interna, soporte de sistemas hospitalarios, central de esterilización).
- **RF-011.** Configurar niveles de prioridad asistencial (Urgente Asistencial / Vital, Alta, Media, Baja) asociados a los tipos de solicitud.
- **RF-012.** Configurar tiempos límite máximos de primera respuesta (MTTA) y de resolución final (MTTR) de SLA por tipo de solicitud y nivel de prioridad asistencial.
- **RF-013.** Configurar calendarios operativos diferenciados para el cómputo de SLA: calendario continuo 24/7 (para solicitudes asistenciales críticas de soporte vital) y calendario hábil administrativo (para solicitudes rutinarias y administrativas).
- **RF-014.** Administrar causales estandarizadas de suspensión temporal de SLA (estado 'En Espera'), causales de rechazo técnico, causales de cancelación y motivos de reapertura sin requerir modificación de código.

## Módulo 3: Radicación y Registro de Solicitudes
- **RF-020.** Registrar y radicar nuevas solicitudes internas generando de forma automática e inalterable un código correlativo único institucional con prefijo de hospital (ej. `H1-2026-000452`).
- **RF-021.** Proveer un formulario de radicación expedita para emergencias asistenciales con validación rápida de campos esenciales (sede, servicio de origen, ubicación específica de cama/quirófano y descripción de la falla vital).
- **RF-022.** Permitir adjuntar documentos y archivos de soporte técnico (fotografías de la falla biomédica, órdenes de trabajo, manuales en formato PDF o imagen) al momento de radicar o durante el seguimiento, validando formato y cuota máxima.
- **RF-023.** Garantizar la idempotencia en el registro de solicitudes mediante token o clave de unicidad generada por el cliente, evitando duplicidad ante fallas o reintentos de red.
- **RF-024.** Emitir confirmación inmediata de radicación exitosa al usuario solicitante indicando el código correlativo asignado y el tiempo estimado de atención inicial según el SLA configurado.

## Módulo 4: Asignación y Gestión de Responsables
- **RF-030.** Asignar solicitudes a un técnico resolutor individual acreditado o a un equipo de guardia específico según especialidad técnica, sede hospitalaria y turno activo.
- **RF-031.** Permitir la reasignación justificada de solicitudes entre técnicos o equipos de turno, registrando obligatoriamente el usuario autorizador, motivo formal y fecha/hora del cambio.
- **RF-032.** Notificar de manera inmediata al técnico individual o equipo de guardia asignado ante la vinculación de una nueva solicitud o elevación de prioridad.
- **RF-033.** Notificar al usuario solicitante cuando su solicitud haya sido asignada formalmente a un responsable técnico con indicación de su identidad profesional.

## Módulo 5: Ciclo de Vida y Transiciones de Estado
- **RF-040.** Gestionar el ciclo de vida completo de cada solicitud controlando los estados: Registrada, Asignada, En Proceso, En Espera, Atendida, Cerrada, Rechazada y Cancelada.
- **RF-041.** Ejecutar una máquina de estados determinista que impida transiciones no autorizadas, saltos de flujo o cambios sin cumplir las precondiciones exigidas.
- **RF-042.** Garantizar la idempotencia en cada transición de estado para prevenir inconsistencias o cambios repetidos provocados por reintentos de red del cliente.
- **RF-043.** Controlar la transición al estado 'En Espera', exigiendo el registro de una causal externa catalogada (ej. falta de repuesto, tercero bajo garantía), justificación técnica y pausando de forma inmediata el reloj de cómputo de SLA.
- **RF-044.** Registrar obligatoriamente el informe técnico de atención (acciones ejecutadas, tiempo dedicado, condición final del activo y repuestos utilizados) como precondición ineludible para transicionar a 'Atendida'.
- **RF-045.** Permitir el rechazo justificado de solicitudes por improcedencia técnica o duplicidad, exigiendo un motivo detallado y notificando al solicitante.
- **RF-046.** Permitir la cancelación voluntaria por parte del solicitante únicamente mientras la solicitud se encuentre en estado 'Registrada' o 'Asignada' (antes del inicio de atención técnica en 'En Proceso'), requiriendo justificación.
- **RF-047.** Permitir la reapertura justificada de solicitudes en estado 'Atendida' o 'Cerrada' exclusivamente dentro de una ventana máxima de 48 horas post-atención, reiniciando el flujo con trazabilidad completa.

## Módulo 6: Control Temporal, Alertas y Escalamiento
- **RF-050.** Registrar y auditar con estampa de tiempo del servidor las fechas clave del ciclo de vida: fecha/hora de radicación, fecha/hora de asignación, inicio de atención técnica, pausas de SLA, atención efectiva y cierre formal.
- **RF-051.** Calcular y monitorear dinámicamente el tiempo transcurrido de atención respecto al SLA pactado, aplicando las reglas del calendario correspondiente (continuo 24/7 vs. hábil administrativo).
- **RF-052.** Emitir alertas automáticas preventivas cuando una solicitud alcance el umbral del 75% del tiempo límite de SLA sin haber sido atendida.
- **RF-053.** Ejecutar el escalamiento jerárquico automático de solicitudes asistenciales críticas vencidas o próximas a vencer, notificando a las jefaturas de servicio y supervisores de guardia de la red hospitalaria.

## Módulo 7: Bitácora, Búsqueda y Consultas Operativas
- **RF-060.** Registrar en una bitácora cronológica inmutable todas las notas técnicas de seguimiento, cambios de guardia, piezas solicitadas y comunicaciones entre solicitante y técnico.
- **RF-061.** Proveer búsqueda avanzada y filtrado multicriterio de solicitudes por sede hospitalaria, servicio solicitante, servicio resolutor, tipo de solicitud, prioridad asistencial, responsable asignado, rango de fechas y estado de atención.
- **RF-062.** Proveer bandejas de trabajo personalizadas por responsable técnico y supervisor de guardia, priorizando visualmente las solicitudes críticas de soporte vital y las próximas a vencer.

## Módulo 8: Indicadores de Gestión y Auditoría Inmutable
- **RF-070.** Generar tableros de indicadores de desempeño y calidad hospitalaria: porcentaje global de cumplimiento de SLA, tiempo medio de asignación (MTTA), tiempo medio de resolución (MTTR), tasa de reaperturas y volumen por servicio/sede.
- **RF-071.** Exportar reportes detallados y consolidados de solicitudes e indicadores en formatos abiertos estándar (CSV, PDF) con filtros por período, hospital y especialidad.
- **RF-072.** Registrar una pista de auditoría inmutable de todas las operaciones críticas (creación, asignación, cambios de estado, reaperturas y consultas de datos) con estampa de tiempo UTC, usuario responsable y dirección de origen.
