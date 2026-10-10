# Criterios de aceptación

- **CA-001. Trazabilidad integral y bidireccional:** Cada decisión de diseño arquitectónico, regla de negocio y componente estructural debe mapearse formalmente a al menos un Requisito Funcional (RF) o Requisito No Funcional (RNF) documentado, demostrando trazabilidad completa.

- **CA-002. Neutralidad tecnológica inicial demostrada:** La definición de requisitos y reglas de negocio no debe contener mención prematura ni atadura forzada a frameworks, lenguajes, marcas comerciales o motores de base de datos específicos, garantizando una evaluación imparcial de alternativas en la fase de arquitectura.

- **CA-003. Comparación objetiva de alternativas arquitectónicas:** Deben documentarse y compararse cuantitativamente al menos dos alternativas arquitectónicas viables, contrastando sus ventajas, desventajas, costos operacionales y alineación con los drivers arquitectónicos y restricciones del hospital.

- **CA-004. Control de concurrencia y prevención de carreras comprobable:** La arquitectura propuesta debe detallar el mecanismo formal para prevenir colisiones, sobreescrituras y condiciones de carrera durante la asignación simultánea de solicitudes o transiciones de estado concurrentes por múltiples supervisores durante cambios de guardia.

- **CA-005. Idempotencia y tolerancia a reintentos:** Las transacciones de radicación y cambios de estado deben especificar el tratamiento de tokens de idempotencia o claves únicas para asegurar que ante fallas o reintentos de red no se generen duplicados ni estados inconsistentes.

- **CA-006. Validación de capacidad y evaluación del escenario 10x:** La propuesta debe justificar técnicamente la capacidad del sistema para procesar 3.000 transacciones de negocio por hora, absorber picos de 9.000 tx/h y sostener la viabilidad del crecimiento proyectado a 10x (30.000 tx/h) sin requerir reingeniería estructural.

- **CA-007. Estrategia de persistencia y almacenamiento desacoplado de adjuntos:** Debe justificarse la estrategia de persistencia transaccional y el resguardo desacoplado de evidencias técnicas y fotográficas (hasta 5 MB), demostrando que la base de datos no sufrirá degradación por almacenamiento masivo de binarios.

- **CA-008. Continuidad operativa 24/7 y cálculo de SLA asistencial:** El diseño debe validar el soporte continuo 24/7 para servicios asistenciales críticos (Urgencias, Quirófanos, UCI), incluyendo la diferenciación respecto al calendario hábil administrativo, emisión de alertas al 75% y escalamiento jerárquico automático.

- **CA-009. Recuperación ante desastres (RPO y RTO):** La propuesta debe incluir procedimientos concretos y viables de respaldo, réplica y restauración que garanticen un RPO $\le 60$ minutos y un RTO $\le 180$ minutos.

- **CA-010. Proporcionalidad operacional y ausencia de sobreingeniería:** La solución recomendada debe ser proporcionada a la escala de una red de hasta 4 hospitales y gestionable por el equipo interno de TI, descartando explícitamente arquitecturas excesivamente complejas o fragmentadas que no estén justificadas por los requisitos.

- **CA-011. Ausencia de hallazgos bloqueantes en revisión adversarial:** El paquete completo de especificaciones y arquitectura no debe contener ambigüedades, contradicciones internas, vacíos de alcance, inconsistencias entre RF y RNF ni requisitos no verificables al finalizar la revisión técnica.
