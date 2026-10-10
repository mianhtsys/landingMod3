@requirements-analyst
Analiza proyecto/ aplicando requirements-analysis.
No selecciones todavía una tecnología.
Devuelve el contenido para 03_resultados/01_requirements_analysis.md.


Analiza el resultado de requisitos y define:
- decisiones que deben tomarse;
- especialistas necesarios;
- alternativas que deben compararse;
- restricciones críticas;
- preguntas pendientes.

No programes y no selecciones todavía un stack final.
Resultado:
proyecto/03_resultados/02_decision_scope.md



Propón de 2 a 3 alternativas arquitectónicas.
Compara complejidad, escalabilidad, consistencia, costo operativo y mantenibilidad.
No uses microservicios por defecto.
No elijas aún una base de datos definitiva.
Resultado:
03_architecture_options.md


Evalúa la estrategia de persistencia y compara alternativas razonables.
No asumas un motor específico.
Analiza concurrencia, consistencia, recuperación, volumen, auditoría y crecimiento.
Resultado:
04_database_analysis.md

Revisa identidad, autorización, APIs, secretos, auditoría, fraude,
datos, terceros y resiliencia de seguridad.
No programes.
Resultado:
05_security_review.md


Evalúa despliegue, contenedores, escalado, observabilidad,
backup, recuperación y CI/CD.
Justifica explícitamente usar o NO usar Kubernetes.

Resultado:
06_infrastructure.md


Realiza una revisión adversarial de todos los resultados contra RF/RNF.
Clasifica hallazgos en bloqueantes, altos, medios y bajos.
Identifica requisitos sin cobertura.
Resultado:
07_architecture_review.md


Consolida todos los resultados.
Entrega:
- matriz de decisión;
- arquitectura recomendada;
- backend;
- persistencia;
- integraciones;
- seguridad;
- infraestructura;
- capacidad;
- riesgos;
- preguntas pendientes;
- trazabilidad RF/RNF;
- ADR propuesto;
- plan de pruebas antes de implementar.
Resultado:
08_recomendacion_final.md



Continúa este proyecto con la fase de ingeniería de base de datos.

Mantén exactamente la estructura existente.

Lee:
- AGENTS.md
- SKILLS_SOURCES.md
- proyecto/00_contexto/
- proyecto/01_requisitos/
- proyecto/02_configuracion/
- proyecto/03_resultados/
- proyecto/04_decisiones/

Luego sigue:
.agents/workflows/02_database_workflow.md

Usa únicamente los skills existentes instalados en:
.agents/skills/

Continúa desde:
.agents/state/database-workflow.json

Trabaja paso por paso.
No saltes etapas.
No empieces directamente creando tablas o SQL.
No elijas PostgreSQL, MySQL o SQL Server por preferencia:
justifica la selección mediante RF, RNF, restricciones y arquitectura.

Después de cada etapa:
1. indica el skill utilizado;
2. genera el artefacto correspondiente en proyecto/05_base_datos/;
3. resume las decisiones;
4. actualiza el checkpoint;
5. continúa con el siguiente paso si no existe bloqueo.

No conectes ni despliegues hasta que la revisión DBA genere:
STATUS: APPROVED

Nunca escribas credenciales en archivos versionados.
No ejecutes operaciones destructivas sin autorización explícita.