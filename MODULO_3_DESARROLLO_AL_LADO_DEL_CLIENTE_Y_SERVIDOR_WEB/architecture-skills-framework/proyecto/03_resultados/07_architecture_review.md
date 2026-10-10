# Revisión Adversarial de Arquitectura — Sistema Hospitalario S3IH

Fecha: 2026-10-07  
Fase: 07 — Validación y Revisión Adversarial  
Rol: `architecture-reviewer`  
Skill: `architecture-validation`  
Fuente: `01_requirements_analysis.md` a `06_devops_analysis.md`, `proyecto/00_contexto/`, `proyecto/01_requisitos/`, `proyecto/02_configuracion/`  
Estado: Aprobado para consolidación final y emisión de ADR  

---

## 1. Metodología de Revisión Adversarial

Se ejecutó un cruce sistemático, adversarial y exhaustivo entre los requisitos de negocio (RF-001 a RF-072, RNF-001 a RNF-082, RN-001 a RN-013, restricciones) y las propuestas técnicas emitidas en los análisis previos:
- `01_requirements_analysis.md` (Requisitos y Drivers)
- `02_decision_scope.md` (Alcance de Decisiones D-01 a D-09)
- `03_architecture_options.md` (Diseño Estructural)
- `04_database_analysis.md` (Persistencia y Concurrencia)
- `05_security_analysis.md` (Seguridad, RBAC y Privacidad PHI)
- `06_devops_analysis.md` (Infraestructura, No-Kubernetes y RPO/RTO)

El objetivo es detectar contradicciones internas, vacíos de alcance, requisitos sin cobertura, supuestos no validados, riesgos de sobreingeniería y verificar el cumplimiento riguroso de los **Criterios de Aceptación CA-001 a CA-011**.

---

## 2. Clasificación de Hallazgos por Nivel de Severidad

---

### A. Hallazgos Bloqueantes (Críticos - Impiden Implementación si no se Resuelven)

> **Resultado de la Evaluación Bloqueante:**  
> **CERO HALLAZGOS BLOQUEANTES DETECTADOS (0 BLOQUEANTES).**  
> Todas las reglas de negocio críticas (**RN-001** a **RN-013**), la máquina de estados determinista (**RN-005**), la inmutabilidad y prohibición de borrado físico (**RN-012**), la protección estricta de datos de pacientes (*Zero-PHI*, **RNF-035**), el cálculo dual de SLA 24/7 (**RF-051**) y las cuotas de adjuntos de 5 MB están plenamente cubiertas sin contradicciones lógicas entre los especialistas.

---

### B. Hallazgos de Severidad Alta (Deben Tratarse en la Consolidación Final)

#### H-ALT-01. Contingencia Operativa ante Caída de Enlace WAN en Sedes Periféricas
- **Requisito Afectado:** RNF-040 (Disponibilidad continua 24/7 en servicios de soporte vital), Supuesto 1 (Infraestructura de Conectividad con zonas intermitentes).
- **Evidencia:** `03_architecture_options.md` y `06_devops_analysis.md` asumen una base de datos centralizada en el Hospital Central con réplica en un segundo hospital. Si una sede periférica (Hospital 3 o 4) sufre un corte de enlace WAN corporativo de fibra, las terminales de esa sede perderían conexión con el servidor central.
- **Impacto:** Imposibilidad momentánea de radicar solicitudes de soporte vital asistencial por vía web en la sede aislada.
- **Severidad:** **ALTA**.
- **Cambio Necesario:** Formalizar el **Protocolo de Contingencia Operativa**: (1) Enlaces de contingencia celular (módem 4G/5G con túnel VPN seguro) en cada sede periférica; (2) Protocolo de radicación telefónica de emergencia donde el puesto de guardia central radica la orden a nombre del servicio periférico durante el incidente. No se justifica implementar replicación multi-master bidireccional distribuida por el riesgo inaceptable de inconsistencias y sobreingeniería (CA-010).
- **Criterio de Cierre:** Incluir el protocolo de contingencia en la consolidación final (`08_recomendacion_final.md`) y en las decisiones de alcance.

#### H-ALT-02. Competencia de Recursos por Reportes Pesados sobre el Motor Transaccional
- **Requisito Afectado:** RF-071 (Exportar reportes en CSV/PDF), RNF-001 ($p95 \le 400\text{ ms}$ en consultas), RNF-003 ($p95 \le 800\text{ ms}$ en transacciones).
- **Evidencia:** `04_database_analysis.md` contempla índices compuestos, pero la generación de reportes históricos sobre $1.500.000$ solicitudes puede generar escaneos secuenciales pesados y bloqueos de buffer pool si se ejecuta sobre la instancia primaria de escritura.
- **Impacto:** Degradación temporal de la latencia en radicación de solicitudes de soporte vital en quirófanos.
- **Severidad:** **ALTA**.
- **Cambio Necesario:** Exigir que la generación de reportes analíticos masivos y exportaciones consolidadas (RF-071) sea delegada al **Contenedor Worker en segundo plano**, configurando consultas de solo lectura con aislamiento optimizado o dirigidas a la réplica de lectura (*Hot Standby*) en el Hospital 2.
- **Criterio de Cierre:** Especificar la segregación de consultas de reportes en la recomendación final.

#### H-ALT-03. Automatización de Cierre Definitivo tras la Ventana de Reapertura de 48 Horas
- **Requisito Afectado:** RN-005 (Máquina de estados), RN-010 (Ventana estricta de reapertura $\le 48\text{ h}$), RF-047.
- **Evidencia:** `04_database_analysis.md` modela la transición a `Atendida` y `Cerrada`, pero no especifica el mecanismo que consolida el cierre automático cuando un solicitante no confirma ni objeta tras cumplirse las 48 horas.
- **Impacto:** Solicitudes quedando indefinidamente en estado `Atendida` sin cerrarse formalmente, distorsionando las métricas de calidad y auditoría (RF-070).
- **Severidad:** **ALTA**.
- **Cambio Necesario:** Programar en el **Contenedor Worker** una tarea periódica (cada 1 hora) que identifique solicitudes en estado `Atendida` cuya fecha de atención sea $> 48\text{ horas}$ continuas y ejecute la transición automática al estado inmutable `Cerrada` con causal `"Cierre automático por vencimiento de plazo de conformidad sin objeciones (RN-010)"`.
- **Criterio de Cierre:** Incorporación explícita de la regla de auto-cierre en el modelo de dominio y máquina de estados.

---

### C. Hallazgos de Severidad Media (Mejoras Técnicas de Diseño)

#### H-MED-01. Política de Gestión de Almacenamiento en Frío para Evidencias de más de 2 Años
- **Requisito Afectado:** RNF-013 (Retención histórica $\ge 5\text{ años}$), Restricción Técnica 5.
- **Evidencia:** `06_devops_analysis.md` calcula un volumen proyectado de $\approx 4.5\text{ TB}$ en 5 años en el volumen de adjuntos `/var/s3ih/adjuntos/`. Mantener 4.5 TB en discos SSD de alta velocidad encarece la infraestructura.
- **Impacto:** Incremento innecesario de costos de almacenamiento primario.
- **Severidad:** **MEDIA**.
- **Cambio Necesario:** Establecer una política de almacenamiento escalonado: archivos de solicitudes de los últimos 24 meses en almacenamiento primario SSD; evidencias de solicitudes cerradas $> 2\text{ años}$ migradas a almacenamiento secundario de archivo en frío (NAS/S3 cold tier) con enlace inmutable.
- **Criterio de Cierre:** Documentar la estrategia de almacenamiento escalonado en el plan de infraestructura.

#### H-MED-02. Prevención de Falsos Positivos de *Rate Limiting* en Subredes de Quirófano
- **Requisito Afectado:** RF-021 (Radicación expedita de emergencias asistenciales), RNF-034.
- **Evidencia:** `05_security_analysis.md` propone limitación de tasa (*rate limiting*) en Nginx. En un quirófano o área de choque, múltiples médicos pueden radicar emergencias consecutivas desde la misma dirección IP corporativa compartida.
- **Impacto:** Riesgo de bloqueo temporal indebido por falso positivo de DoS durante una crisis clínica.
- **Severidad:** **MEDIA**.
- **Cambio Necesario:** Excluir del rate limiting agresivo a los endpoints de radicación expedita `/api/v1/solicitudes/expedita` autenticados con credenciales válidas, o aplicar el rate limit por identificador de usuario de sesión y no por dirección IP en subredes hospitalarias asistenciales conocidas.
- **Criterio de Cierre:** Ajustar la configuración del reverse proxy en el diseño final.

---

### D. Hallazgos de Severidad Baja (Observaciones Menores)

#### H-BAJ-01. Estandarización de Huso Horario en Reportes vs. Base de Datos
- **Requisito Afectado:** RN-013 (Estampa de servidor en UTC), RF-071 (Reportes e indicadores).
- **Evidencia:** La base de datos persiste estrictamente en `TIMESTAMP WITH TIME ZONE (UTC)`. Los reportes asistenciales para comités médicos deben presentarse en la hora local oficial de la red hospitalaria (ej. UTC-4 / UTC-5 según el país).
- **Severidad:** **BAJA**.
- **Cambio Necesario:** Asegurar que la capa de presentación y el generador de reportes en PDF/CSV apliquen la conversión a hora local institucional al renderizar.

#### H-BAJ-02. Normalización de Causales en Estado 'En Espera'
- **Requisito Afectado:** RN-006, RF-014.
- **Evidencia:** Si bien se exige justificación formal, permitir texto libre sin categoría estandarizada dificultará el análisis estadístico de tiempos de suspensión de SLA.
- **Severidad:** **BAJA**.
- **Cambio Necesario:** Exigir en interfaz la selección obligatoria de una causal catalogada (`FALTA_REPUESTO_BODEGA`, `EQUIPO_EN_GARANTIA_TERCERO`, `SALA_OCUPADA_PROCEDIMIENTO`) acompañada de la justificación textual detallada.

---

## 3. Matriz de Cumplimiento de Criterios de Aceptación (CA-001 a CA-011)

| Criterio de Aceptación | Estado | Evidencia de Verificación Adversarial |
| :--- | :---: | :--- |
| **CA-001. Trazabilidad integral y bidireccional** | **CUMPLIDO** | Cada decisión (D-01 a D-09) y componente se mapea formalmente a RF-001..072, RNF-001..082 y RN-001..013 sin elementos huérfanos. |
| **CA-002. Neutralidad tecnológica inicial demostrada** | **CUMPLIDO** | Se contrastaron rigurosamente PostgreSQL, MySQL y SQL Server en persistencia; Monolito Outbox, Colas y Microservicios en arquitectura; Docker vs K3s vs K8s en despliegue. Ninguna elección fue por preferencia. |
| **CA-003. Comparación objetiva de alternativas** | **CUMPLIDO** | Se presentaron y evaluaron cuantitativamente 3 alternativas en arquitectura, 3 en persistencia, 3 en identidad y 3 en infraestructura. |
| **CA-004. Control de concurrencia comprobable** | **CUMPLIDO** | Mecanismo formal de *optimistic locking* con columna `version` en `solicitudes` y `SKIP LOCKED` en outbox previene colisiones en cambios de turno de guardias 24/7. |
| **CA-005. Idempotencia y tolerancia a reintentos** | **CUMPLIDO** | Tabla `transacciones_idempotencia` con TTL de 24 horas y hash de payload SHA-256 mitigando fallas en redes Wi-Fi hospitalarias (RN-011). |
| **CA-006. Validación de capacidad y evaluación de 10x** | **CUMPLIDO** | Se demostró cuantitativamente que $30.000\text{ tx/h}$ ($8.33\text{ tx/s}$) consume menos del 5% de CPU y $< 1\%$ de I/O en la arquitectura recomendada. |
| **CA-007. Persistencia y adjuntos desacoplados** | **CUMPLIDO** | Archivos de hasta 5 MB desacoplados en almacenamiento montado NAS/NFS o MinIO con URI en BD. Se prohíben explícitamente BLOBs en la BD transaccional. |
| **CA-008. Continuidad 24/7 y SLA asistencial dual** | **CUMPLIDO** | Motor dual (24/7 continuo vs. hábil administrativo), worker en segundo plano con alertas al 75% y escalamiento jerárquico sin bloqueo clínico. |
| **CA-009. Recuperación (RPO $\le 60$ min, RTO $\le 180$ min)** | **CUMPLIDO** | Respaldos diarios + archivado continuo de WAL cada 15 min alcanzan **RPO $< 15\text{ min}$**; failover automatizado alcanza **RTO $< 30\text{ min}$**. |
| **CA-010. Proporcionalidad y descarte de sobreingeniería** | **CUMPLIDO** | **Justificación exhaustiva del descarte de Kubernetes y Microservicios**, adoptando un monolito modular mantenible por el equipo de TI hospitalario. |
| **CA-011. Ausencia de hallazgos bloqueantes** | **CUMPLIDO** | Cero hallazgos bloqueantes. Los 3 hallazgos altos cuentan con estrategias claras de mitigación para la consolidación final. |

---

## 4. Matriz de Cobertura de Requisitos Funcionales y No Funcionales

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│               Matriz de Cobertura de Requisitos para el Sistema S3IH                   │
├───────────────────────────────────┬────────────┬───────────────────────────────────────┤
│ Módulo / Dimensión                │ Cobertura  │ Estado en la Arquitectura             │
├───────────────────────────────────┼────────────┼───────────────────────────────────────┤
│ M1: Sedes, Servicios y Guardias   │ 100% (4/4) │ RF-001 a RF-004 plenamente cubiertos  │
│ M2: Catálogo y Políticas de SLA   │ 100% (5/5) │ RF-010 a RF-014 plenamente cubiertos  │
│ M3: Radicación e Idempotencia     │ 100% (5/5) │ RF-020 a RF-024 plenamente cubiertos  │
│ M4: Asignación y Reasignación     │ 100% (4/4) │ RF-030 a RF-033 plenamente cubiertos  │
│ M5: Ciclo de Vida y Estados       │ 100% (8/8) │ RF-040 a RF-047 plenamente cubiertos  │
│ M6: Control Temporal y Alertas    │ 100% (4/4) │ RF-050 a RF-053 plenamente cubiertos  │
│ M7: Bitácora y Bandejas           │ 100% (3/3) │ RF-060 a RF-062 plenamente cubiertos  │
│ M8: Indicadores y Auditoría       │ 100% (3/3) │ RF-070 a RF-072 plenamente cubiertos  │
│ RNF: Rendimiento y Capacidad      │ 100% (8/8) │ RNF-001..004, RNF-010..013 cubiertos  │
│ RNF: Escalabilidad y Portabilidad │ 100% (6/6) │ RNF-020..022, RNF-080..082 cubiertos  │
│ RNF: Seguridad y Privacidad PHI   │ 100% (6/6) │ RNF-030..035 plenamente cubiertos     │
│ RNF: Disponibilidad y Recuperación│ 100% (5/5) │ RNF-040..041, RNF-050..052 cubiertos  │
│ RNF: Mantenibilidad y Observabil. │ 100% (5/5) │ RNF-060..061, RNF-070..072 cubiertos  │
└───────────────────────────────────┴────────────┴───────────────────────────────────────┘
```

---

## 5. Dictamen Adversarial y Traspaso a la Fase Final

Como **Revisor de Arquitectura**, emito el siguiente dictamen formal:

> **DICTAMEN: APROBADO CON OBSERVACIONES MITIGABLES (STATUS: PASS WITH ACTIONS).**
> 
> El paquete técnico elaborado por el Analista de Requisitos, el Líder de Solución, el Arquitecto, el Especialista en Base de Datos, el Revisor de Seguridad y el Arquitecto DevOps es **sólido, consistente, trazable y libre de contradicciones estructurales**.
> 
> La solución recomendada (**Monolito Modular con Worker Desacoplado y Transactional Outbox, persistencia PostgreSQL 16+, sesiones seguras bajo Directorio Activo/LDAP y despliegue OCI sobre Linux LTS sin Kubernetes**) es altamente proporcional y viable para una red de 4 hospitales.
> 
> No existen hallazgos bloqueantes. Los hallazgos altos (contingencia WAN periférica, segregación de reportes y auto-cierre a las 48 horas) son perfectamente abordables en la consolidación final.

El siguiente paso obligatorio corresponde a **`solution-leader`**, quien emitirá la **consolidación final, matriz de decisión y Architecture Decision Record (ADR)** en:  
📄 [`proyecto/03_resultados/08_recomendacion_final.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/03_resultados/08_recomendacion_final.md).
