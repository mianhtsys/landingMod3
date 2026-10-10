# Descripción del sistema

## Nombre
Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red Hospitalaria).

## Problema
Una institución hospitalaria conformada por una red de hasta 4 hospitales (hospital central y sedes periféricas) enfrenta graves dificultades operativas debido a la descentralización, descontrol y falta de trazabilidad en la gestión de sus solicitudes internas entre servicios asistenciales (Urgencias, Quirófano, UCI, Hospitalización, etc.) y unidades resolutoras técnicas y administrativas (Mantenimiento Biomédico, Infraestructura/Servicios Generales, Farmacia Interna, Tecnologías de la Información, Central de Esterilización).

Actualmente, los requerimientos se canalizan a través de vías informales (llamadas telefónicas no registradas, notas en papel, mensajes de texto personales), lo que ocasiona:
1. Pérdida recurrente de solicitudes e incertidumbre sobre su estado real de atención.
2. Falta de claridad en la asignación de responsables individuales o equipos de turno de guardia.
3. Incumplimiento sistemático de los Acuerdos de Nivel de Servicio (SLA) asistenciales y técnicos, impactando directamente la continuidad de atención a pacientes y la seguridad clínica.
4. Ausencia de registros auditables de intervenciones sobre equipos biomédicos e infraestructura hospitalaria crítica.
5. Inexistencia de métricas consolidadas para la toma de decisiones por parte de la dirección hospitalaria.

## Actores
1. **Personal Asistencial Solicitante:** Médicos, personal de enfermería y jefes de servicio asistencial que detectan fallas técnicas o requieren suministros e intervenciones en áreas clínicas.
2. **Personal Técnico Resolutor:** Ingenieros biomédicos, técnicos de mantenimiento edilicio, farmacéuticos internos, especialistas de soporte TI y personal de esterilización encargados de diagnosticar, reparar y resolver las solicitudes.
3. **Supervisores y Jefaturas de Servicio Resolutor:** Responsables de coordinar cuadrillas de guardia 24/7, balancear cargas de trabajo, reasignar solicitudes complejas y aprobar suspensiones justificadas o adquisiciones de repuestos.
4. **Administradores del Sistema Hospitalario:** Personal de TI encargado de administrar sedes, servicios, catálogos de solicitudes, calendarios de turnos, niveles de SLA y privilegios de acceso.
5. **Dirección Médica y Comités de Calidad/Acreditación:** Consumidores de indicadores de desempeño (tiempos de respuesta, cumplimiento de SLA por sede) y pistas de auditoría para procesos de acreditación en salud.

## Canales
1. **Portal Web Institucional Responsivo:** Accesible de forma segura desde estaciones de trabajo fijas (puestos de enfermería, terminales de quirófano, áreas técnicas) y terminales móviles institucionales (tablets en carros de guardia) conectados a la red hospitalaria.
2. **Servicio de Notificaciones y Alertas:** Notificaciones automáticas de asignación, proximidad de vencimiento y escalamiento hacia correo electrónico corporativo y canales de mensajería interna hospitalaria.

## Procesos principales
1. **Radicación de Solicitudes:** Registro formal del requerimiento con asignación de código correlativo único por sede, clasificación tipificada, nivel de prioridad asistencial y adjuntos fotográficos/técnicos de fallas.
2. **Asignación y Enrutamiento:** Derivación inmediata a técnicos individuales o equipos de turno de guardia según especialidad, servicio y sede.
3. **Gestión del Ciclo de Vida y Transiciones de Estado:** Control riguroso de estados (Registrada, Asignada, En Proceso, En Espera justificada, Atendida, Cerrada, Rechazada, Cancelada) con validación de precondiciones y motivos técnicos.
4. **Control Temporal y Escalamiento de SLA:** Cómputo dinámico de plazos diferenciados (reloj continuo 24/7 para servicios asistenciales críticos vs. horario hábil administrativo), emisión de alertas tempranas y escalamiento jerárquico ante demoras.
5. **Resolución y Cierre Técnico:** Registro obligatorio de actividades realizadas, tiempo invertido, repuestos empleados y confirmación de conformidad de servicio.
6. **Auditoría y Analítica Operativa:** Registro inmutable de cada acción realizada y generación de métricas de calidad de atención (MTTA, MTTR, tasa de cumplimiento de SLA).

## Objetivo del análisis
Definir los requisitos funcionales, requisitos no funcionales, reglas del negocio y restricciones de forma rigurosa, cuantitativa y verificable, eliminando ambigüedades, contradicciones, duplicidades y sesgos tecnológicos prematuros, para servir de base objetiva en la evaluación y selección de la arquitectura más idónea y proporcionada para la red de hasta 4 hospitales.
