# Requisitos no funcionales

## Rendimiento
- **RNF-001.** El tiempo de respuesta en consultas de bandejas de trabajo, búsquedas estándar y filtrado de solicitudes debe ser menor o igual a 400 milisegundos en el percentil 95 ($p95$) bajo condiciones de carga nominal base.
- **RNF-002.** El tiempo de respuesta para la carga del detalle completo de una solicitud, incluyendo su historial de bitácora y listado de adjuntos, debe ser menor o igual a 500 milisegundos en el percentil 95 ($p95$).
- **RNF-003.** El tiempo de procesamiento para transacciones de negocio de escritura (radicación de nueva solicitud, asignación a responsable y transiciones de estado) debe ser menor o igual a 800 milisegundos en el percentil 95 ($p95$).
- **RNF-004.** La subida, validación y almacenamiento de archivos adjuntos técnicos (hasta 5 MB por archivo) debe completarse en un tiempo no mayor a 2.000 milisegundos sobre la red corporativa hospitalaria.

## Capacidad
- **RNF-010.** El sistema debe soportar una capacidad nominal base de al menos 3.000 transacciones de negocio por hora distribuidas entre las sedes de la red hospitalaria (aproximadamente 0.83 transacciones de negocio por segundo continuo).
- **RNF-011.** El sistema debe soportar un multiplicador de carga pico de al menos 3.0x durante los cambios de turno hospitalario y picos asistenciales (9.000 transacciones de negocio por hora, aproximadamente 2.5 transacciones por segundo), sin degradar los tiempos de respuesta por encima de 1.500 milisegundos.
- **RNF-012.** La arquitectura debe soportar una concurrencia simultánea estimada de 700 usuarios activos (500 usuarios asistenciales consultando o radicando y 200 usuarios técnicos/supervisores actualizando solicitudes).
- **RNF-013.** La persistencia de datos debe dimensionarse para garantizar el almacenamiento histórico acumulado de al menos 5 años de operación continua ($\ge 1.500.000$ solicitudes con su trazabilidad de auditoría completa) sin degradación del rendimiento de los índices principales.

## Escalabilidad
- **RNF-020.** La capa de cómputo del sistema debe permitir escalamiento horizontal para absorber incrementos de carga sin requerir modificaciones en el código fuente ni reingeniería del modelo de dominio.
- **RNF-021.** La arquitectura debe validar su viabilidad en un escenario de crecimiento de 10x la carga nominal base (30.000 transacciones de negocio por hora, aproximadamente 8.33 transacciones de negocio por segundo) mediante adición proporcional de recursos de cómputo y optimización de almacenamiento.
- **RNF-022.** El modelo de datos debe permitir el crecimiento gradual de la red hospitalaria hasta un máximo de 4 hospitales mediante particionamiento o indexación eficiente por sede, sin requerir reestructuración de la base de datos.

## Seguridad
- **RNF-030.** La autenticación debe integrarse con el directorio corporativo hospitalario institucional (LDAP, Active Directory, SAML u OIDC), asegurando control de sesiones con cierre automático tras un máximo de 30 minutos de inactividad en puestos compartidos.
- **RNF-031.** La autorización debe regirse estrictamente por control de acceso basado en roles (RBAC) bajo el principio de mínimo privilegio, validando permisos por rol funcional, sede hospitalaria y servicio asignado.
- **RNF-032.** Todas las comunicaciones en tránsito deben estar cifradas mediante protocolos criptográficos robustos y actualizados (TLS 1.3 / HTTPS). Los datos sensibles y respaldos deben contar con cifrado en reposo.
- **RNF-033.** Ninguna credencial, secreto de configuración ni clave criptográfica debe almacenarse en el código fuente ni en repositorios. Las contraseñas de cuentas locales de contingencia deben protegerse mediante funciones de derivación de claves seguras con salting robusto.
- **RNF-034.** Todas las entradas de usuario y llamadas a interfaces de servicio deben validarse y sanearse rigurosamente. Los archivos adjuntos deben someterse a validación estricta de tipo MIME real, tamaño máximo permitido (5 MB) y extensión de archivo permitida (PDF, JPG, PNG).
- **RNF-035.** El sistema debe asegurar el aislamiento estricto de datos, impidiendo el registro o exposición de datos clínicos protegidos de pacientes (historias clínicas / PHI) dentro de los campos descriptivos de las solicitudes de mantenimiento o soporte.

## Disponibilidad
- **RNF-040.** El sistema debe garantizar una disponibilidad global mínima de 99.9% anual para la recepción y gestión de solicitudes de servicios asistenciales críticos 24/7 (indisponibilidad no planificada menor a 8.76 horas al año).
- **RNF-041.** El sistema debe implementar mecanismos de degradación elegante y tolerancia a fallos: ante la indisponibilidad temporal de servicios periféricos externos (servidor de correo electrónico, generación de reportes pesados), las funciones transaccionales centrales de radicación, asignación y cambio de estado deben continuar operando con normalidad.

## Recuperación
- **RNF-050.** El Objetivo de Punto de Recuperación (RPO) debe ser menor o igual a 60 minutos en caso de falla catastrófica del entorno de persistencia, garantizando políticas de respaldo periódico y conservación de registros transaccionales.
- **RNF-051.** El Objetivo de Tiempo de Recuperación (RTO) debe ser menor o igual a 180 minutos (3 horas) para la restauración y puesta en marcha operativa total del sistema ante incidentes mayores.
- **RNF-052.** La estrategia de copias de seguridad debe asegurar la coherencia referencial sincronizada entre los registros de la base de datos transaccional y los archivos binarios adjuntos resguardados en el repositorio de archivos.

## Mantenibilidad
- **RNF-060.** La arquitectura debe aplicar principios de modularidad, alta cohesión y bajo acoplamiento, separando claramente las responsabilidades de interfaz, lógica de negocio y cálculo de SLA, y persistencia de datos.
- **RNF-061.** Los parámetros operativos (umbrales de SLA, calendarios de trabajo, tipos de solicitudes, causales de suspensión y catálogos de servicios) deben ser modificables dinámicamente mediante interfaz o archivos de configuración sin requerir recompilación de código ni interrupción del servicio.

## Observabilidad
- **RNF-070.** El sistema debe emitir registros de eventos (logs estructurados, ej. JSON) con niveles estandarizados (DEBUG, INFO, WARN, ERROR), marcas de tiempo sincronizadas en UTC, contexto de usuario y trazabilidad por sede hospitalaria.
- **RNF-071.** El sistema debe exponer métricas operativas de rendimiento en tiempo real (tasa de transacciones por segundo, latencia por endpoint, tasa de errores HTTP, ocupación de conexiones a base de datos y tamaño de colas de notificación).
- **RNF-072.** Cada transacción debe incluir un identificador de correlación (*correlation ID*) propagado a través de las capas del sistema para permitir la trazabilidad completa en el diagnóstico de incidentes.

## Portabilidad
- **RNF-080.** El sistema debe ejecutarse de forma agnóstica en sistemas operativos tipo Linux estándar empresarial.
- **RNF-081.** Todos los componentes de software deben ser construidos y empaquetados en imágenes de contenedor bajo especificaciones abiertas OCI, permitiendo su ejecución indistinta en centros de datos locales o nubes privadas.
- **RNF-082.** El sistema debe soportar la separación estricta de entornos (desarrollo, pruebas y producción) mediante parametrización por variables de entorno externas, sin dependencias atadas al entorno de compilación.
