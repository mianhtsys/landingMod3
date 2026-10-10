# Supuestos y restricciones

## Supuestos
1. **Infraestructura de Conectividad:** La institución hospitalaria dispone de una red corporativa privada (LAN/WAN o VPN segura) que interconecta el hospital central y las sedes periféricas, con puntos de acceso Wi-Fi en áreas asistenciales, reconociendo la existencia de zonas con cobertura inalámbrica intermitente.
2. **Directorio Institucional Preexistente:** La red hospitalaria cuenta con un repositorio central de identidades corporativas (Active Directory, LDAP o SSO institucional) donde se encuentran registrados los usuarios y credenciales del personal activo.
3. **Escala Operativa Acotada:** El sistema operará para una red dimensionada en un máximo de 4 hospitales (hospital central y hasta 3 sedes periféricas en crecimiento), con un estimado inicial de 2 sedes activas y un promedio de 20 servicios o departamentos por sede.
4. **Capacidad de Almacenamiento Institucional:** La institución proveerá almacenamiento en disco o repositorio corporativo suficiente para albergar la retención histórica de al menos 5 años de datos de solicitudes y evidencias fotográficas/documentales.

## Restricciones técnicas
1. **Neutralidad de Stack y Ausencia de Sesgo Prematuro:** No se debe preseleccionar ningún lenguaje de programación, framework web, motor de base de datos específico ni proveedor de nube en la definición de requisitos. Las tecnologías se evaluarán y seleccionarán formalmente durante la fase de arquitectura basándose estrictamente en los drivers arquitectónicos y el perfil de carga.
2. **Entorno de Ejecución Corporativo:** El sistema debe operar sobre servidores con sistemas operativos tipo Linux estándar empresarial con soporte extendido (LTS).
3. **Empaquetamiento en Contenedores Estándar:** Todos los componentes de software deben ser empaquetables en imágenes de contenedor conformes a la especificación OCI (Open Container Initiative), facilitando su despliegue y portabilidad entre entornos on-premise y nube privada.
4. **Gestión de Secretos Externa:** Queda estrictamente prohibido incluir contraseñas, claves criptográficas, certificados o cadenas de conexión en el código fuente, plantillas o repositorios de control de versiones. Todo secreto debe inyectarse en tiempo de ejecución mediante mecanismos seguros del entorno.
5. **Almacenamiento Desacoplado de Evidencias Técnicas:** Los archivos adjuntos (fotografías de averías, diagramas, manuales, reportes en PDF) no deben almacenarse como tipos BLOB dentro de las tablas transaccionales de la base de datos relacional para evitar la saturación de I/O y la degradación del rendimiento de las consultas transaccionales.
6. **Desacoplamiento Asíncrono de Servicios Externos:** Las dependencias externas (servicios de correo electrónico SMTP, mensajería, sincronización de catálogos y generación de reportes pesados) deben manejarse de forma asíncrona o no bloqueante, asegurando que su lentitud o caída no interrumpa la radicación ni el cambio de estado de solicitudes asistenciales.

## Restricciones organizacionales
1. **Operación Continua 24/7 en Áreas de Soporte Vital:** Los servicios asistenciales críticos (Urgencias, Quirófanos, UCI) operan 24 horas al día los 365 días del año. Cualquier ventana de mantenimiento o actualización del sistema debe ser programable sin interrumpir la disponibilidad de recepción y atención de solicitudes vitales.
2. **Segregación de Funciones (SoD):** El personal técnico resolutor no puede auto-aprobarse solicitudes ni modificar parámetros de configuración del sistema; el personal solicitante no puede alterar estados técnicos de solicitudes en curso.
3. **Cumplimiento Normativo y Acreditación en Salud:** La trazabilidad de solicitudes, intervenciones técnicas y marcas de auditoría debe preservarse inalterable durante un mínimo legal de 5 años para satisfacer auditorías de calidad asistencial y acreditación hospitalaria.
4. **Mantenibilidad por el Equipo de TI Interno:** La complejidad técnica y operativa de la arquitectura debe ser proporcional a los recursos y capacidades del equipo de soporte de TI de la red hospitalaria, evitando soluciones sobredimensionadas que demanden cuadrillas masivas de especialistas externos.

## Decisiones pendientes
1. **Topología de Infraestructura:** Definir si el despliegue productivo principal residirá en el centro de datos físico on-premise de la red hospitalaria, en una nube privada institucional o en un esquema híbrido.
2. **Motor de Persistencia Transaccional y de Archivos:** Seleccionar formalmente el motor de base de datos relacional y el subsistema de almacenamiento de archivos adjuntos mediante la matriz de decisión y ADR del framework.
3. **Estrategia de Respaldo y Réplica en Sitio Secundario:** Determinar si la réplica ante desastres operará en modalidad activa-pasiva local o distribuida entre hospitales, en función de los enlaces de red disponibles y el presupuesto institucional.
4. **Dimensionamiento Definitivo de Cuotas por Adjunto:** Formalizar el tamaño máximo exacto por archivo (definido preliminarmente en 5 MB) y número máximo de adjuntos por requerimiento (fijado en 5 archivos).
