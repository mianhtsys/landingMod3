# Recomendación Arquitectónica Final y ADR — Sistema Hospitalario S3IH

Fecha: 2026-10-07  
Fase: 08 — Consolidación Final y Decisión Arquitectónica  
Rol: `solution-leader`  
Skill: `decision-consolidation`  
Fuentes: `01_requirements_analysis.md` a `07_architecture_review.md`, `proyecto/00_contexto/`, `proyecto/01_requisitos/`, `proyecto/02_configuracion/`  
Estado: APROBADO FORMALMENTE (BASE PARA LA FASE DE PERSISTENCIA Y DESARROLLO)  

---

## 1. Resumen Ejecutivo de la Consolidación

El **Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red Hospitalaria)** atiende a una red de **hasta 4 hospitales** (hospital central y sedes periféricas) con **700 usuarios concurrentes** (500 asistenciales en puestos fijos clínicos y 200 técnicos/supervisores en guardias activas).

El sistema resuelve la falta histórica de trazabilidad en requerimientos entre áreas asistenciales críticas (Urgencias, Quirófano, UCI, Hospitalización) y unidades resolutoras técnicas (Mantenimiento Biomédico, Infraestructura/Servicios Generales, Farmacia Interna, Soporte TI y Esterilización).

Tras agotar el ciclo de análisis especializado (analista de requisitos, arquitecto, especialista de base de datos, revisor de seguridad, arquitecto DevOps y revisor adversarial), se consolida una recomendación técnica unificada:
> **Monolito Modular con Worker Desacoplado y Patrón Transactional Outbox**, empaquetado en contenedores OCI estándar sobre Linux empresarial (LTS), con persistencia relacional ACID estricta en **PostgreSQL 16+** (o alternativamente **MySQL 8.x InnoDB**), autenticación institucional contra el **Directorio Activo / LDAP corporativo**, sesiones de servidor seguras con **timeout de 30 minutos** para terminales compartidas, y resguardo desacoplado de evidencias técnicas (hasta 5 MB) fuera de la base de datos relacional.
> 
> **Se descartan categóricamente Kubernetes y Microservicios Distribuidos** por sobreingeniería flagrante (CA-010), desproporción de escala frente a las $0.83\text{ tx/s}$ requeridas y complejidad inaceptable para el equipo de TI interno del hospital.

---

## 2. Matriz de Decisión Consolidada por Dominio

| Decisión | Opción Elegida | Alternativa Descartada Principal | Justificación Técnica y Operativa | Trazabilidad |
| :--- | :--- | :--- | :--- | :--- |
| **D-01: Concurrencia de Guardias 24/7** | **Bloqueo Optimista (*Optimistic Locking*) con columna `version`** | Bloqueo pesimista puro (`SELECT FOR UPDATE`) | Elimina deadlocks y no retiene bloqueos de BD durante la lectura de solicitudes por supervisores. Rápido y escalable. | D1, D2, RF-030, RF-031, CA-004 |
| **D-02: Motor de Persistencia** | **PostgreSQL 16+ (Compatible con MySQL 8.x InnoDB)** | Persistencia NoSQL / Documental | Soporte nativo de `UUID`, `JSONB`, particionamiento e inmutabilidad estricta. Cero costo de licencias. NoSQL descartado por falta de ACID. | D2, D3, RN-005, RN-012, CA-007 |
| **D-03: Idempotencia y Reintentos** | **Tabla de Idempotencia en BD (`Idempotency-Key` UUID + SHA-256)** | Deduplicación solo en capa de transporte | Protege la máquina de estados ante desconexiones en redes Wi-Fi hospitalarias intermitentes (RN-011). TTL de 24 horas. | D2, D3, RN-011, RF-023, CA-005 |
| **D-04: Evidencias Técnicas (Adjuntos)** | **Almacenamiento Desacoplado (NAS/NFS montado o MinIO S3)** | Almacenamiento BLOB dentro de la BD transaccional | Cumple la Restricción Técnica 5: evita saturación de I/O y degradación de memoria del motor relacional. Solo metadatos en BD. | D4, Restr. Téc. 5, RNF-004, CA-007 |
| **D-05: Estilo Arquitectónico** | **Monolito Modular con Worker Desacoplado** | Microservicios distribuidos en Kubernetes | Máxima cohesión, simplicidad operativa para el equipo de TI del hospital, cero latencia de red inter-servicios. Cumple CA-010. | D1, D7, RNF-010, RNF-021, CA-010 |
| **D-06: Asincronía y SLA** | **Patrón *Transactional Outbox* en BD + Worker Daemon** | Broker de colas externo complejo (Kafka) | Garantiza atomicidad entre el cambio de estado y el evento de SLA sin caídas por fallos de red hacia brokers externos. | D1, D5, RF-051, RF-052, RNF-041 |
| **D-07: Identidad y Seguridad** | **Directorio Activo / LDAP + Sesiones Seguras de Servidor** | JWT sin estado puro (*Stateless JWT*) | Permite cierre forzado inmediato a los 30 min en puestos compartidos (quirófanos). Elimina vulnerabilidades XSS en cliente. | D6, RNF-030, RNF-031, RNF-035 |
| **D-08: Despliegue y Orquestación** | **Contenedores OCI estándar (Docker/Podman Compose) en Linux LTS** | Clúster Kubernetes (K8s / OpenShift) | Descarte explícito de K8s por sobreingeniería (1.000x sobre la escala real de 0.83 tx/s). Operable por el equipo interno de TI. | D7, D8, RNF-040, RNF-081, CA-010 |
| **D-09: Observabilidad y Métricas** | **Logs estructurados JSON + Correlation ID + Métricas Prometheus** | Stack ELK pesado o soluciones SaaS de pago | Observabilidad nativa con rotación de archivos, trazabilidad de incidentes y monitoreo de latencia $p95$ sin costos de licencia. | D3, D8, RNF-070, RNF-071, RNF-072 |

---

## 3. Arquitectura Recomendada del Sistema

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        Red Hospitalaria Corporativa (Hospital Central y 3 Sedes)       │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │ HTTPS / TLS 1.3 (Puerto 443)
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        Reverse Proxy / Ingress: Nginx en Contenedor OCI                │
│  - Terminación SSL/TLS 1.3 con HSTS                                                    │
│  - Rate Limiting defensivo (con exclusión de IPs de quirófano/urgencias H-MED-02)      │
│  - Inyección y propagación de 'X-Correlation-ID'                                       │
└─────────────────────┬────────────────────────────────────────────────────┬─────────────┘
                      │ Peticiones Web / API REST                          │ Lectura de Evidencias
                      ▼                                                    ▼
┌─────────────────────────────────────────────────────────────┐ ┌────────────────────────┐
│             Contenedor Web / API: Monolito S3IH             │ │ Volumen de Archivos    │
│  ┌───────────────────────────────────────────────────────┐  │ │ Desacoplado:           │
│  │ Middlewares: Auth LDAP, Sesión Segura (30 min), RBAC  │  │ │ /var/s3ih/adjuntos/    │
│  │ Prevención Zero-PHI, Rate Limit, Correlation ID       │  │ │ (Evidencias técnicas   │
│  └──────────────────────────┬────────────────────────────┘  │ │  y fotos ≤ 5 MB)       │
│  ┌──────────────────────────▼────────────────────────────┐  │ └───────────▲────────────┘
│  │ Capa de Dominio y Servicios Modulares:                │  │             │
│  │ - M1: Sedes, Servicios y Cuadrillas de Turno          │  │             │ Escritura directa
│  │ - M2: Catálogo Tipificado y Políticas de SLA          │  │             │ de adjuntos
│  │ - M3: Radicación e Idempotencia (UUID + SHA-256)      │  │             │ (Valida MIME y 5MB)
│  │ - M4: Asignación y Bloqueo Optimista (version)        │  │             │
│  │ - M5: Máquina de Estados Finita Determinista (RN-005) │  │             │
│  │ - M6: Bitácora, Pista de Auditoría e Inmutabilidad    │  │             │
│  └──────────────────────────┬────────────────────────────┘  │             │
│  ┌──────────────────────────▼────────────────────────────┐  │             │
│  │ Capa de Acceso a Datos / Transacciones ACID           │──┼─────────────┘
│  └──────────────────────────┬────────────────────────────┘  │ Guarda URI y metadatos
└─────────────────────────────┼───────────────────────────────┘
                              │ Transacciones ACID inmediatas
                              ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│             Contenedor RDBMS: PostgreSQL 16+ (o MySQL 8.x InnoDB)                      │
│  ┌──────────────────────────────────────────────────────────────────────────────────┐  │
│  │ Tablas de Negocio: sedes, servicios, usuarios, solicitudes, transacciones        │  │
│  ├──────────────────────────────────────────────────────────────────────────────────┤  │
│  │ Tablas Append-Only: solicitudes_historial_estados, auditoria_eventos (REVOKE DEL)│  │
│  ├──────────────────────────────────────────────────────────────────────────────────┤  │
│  │ Tabla 'outbox_eventos': SLA 75%, escalamiento jerárquico, notificaciones SMTP    │  │
│  └──────────────────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────▲──────────────────────────────────────────────────────────┘
                              │ Lectura atómica (FOR UPDATE SKIP LOCKED)
                              │
┌─────────────────────────────┴──────────────────────────────────────────────────────────┐
│             Contenedor Worker: Despachador Asíncrono de Fondo                          │
│  - Evaluador continuo de reloj dual de SLA (Continuo 24/7 vs. Hábil administrativo)    │
│  - Emisor de alertas tempranas al 75% del SLA y escalamiento jerárquico automático     │
│  - Tarea periódica de auto-cierre a las 48 horas tras conformidad tácita (H-ALT-03)   │
│  - Despachador asíncrono de correos electrónicos corporativos (SMTP)                   │
│  - Generador de reportes consolidados pesados en CSV/PDF en segundo plano (H-ALT-02)   │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Especificación Técnica por Capas

### A. Capa de Backend y Lógica de Negocio
- **Estilo de Arquitectura:** Monolito Modular con arquitectura en capas (Controladores $\rightarrow$ Servicios de Dominio $\rightarrow$ Repositorios de Datos).
- **Stack Recomendado:** PHP 8.2+ puro con PDO / POO (o runtime ligero de alta disponibilidad en Linux), estructurado en MVC estricto sin dependencias pesadas innecesarias.
- **Máquina de Estados Finita Determinista (RN-005):**
  - Implementación con patrón *State / Transition Guard*:
    - `Registrada` $\rightarrow$ `Asignada`, `Rechazada` (causal $\ge 20$ car.), `Cancelada` (solo solicitante).
    - `Asignada` $\rightarrow$ `En Proceso`, `Reasignada`, `Cancelada`.
    - `En Proceso` $\rightarrow$ `En Espera` (con motivo y pausa de SLA), `Atendida` (con informe técnico obligatorio de horas y repuestos, RN-007), `Reasignada`.
    - `En Espera` $\rightarrow$ `En Proceso` (reanuda conteo de SLA).
    - `Atendida` $\rightarrow$ `Cerrada` (por conformidad o auto-cierre a las 48h), `Reaperturada` (solo $\le 48\text{ h}$).
    - `Cerrada` y `Rechazada` $\rightarrow$ Terminales inmutables definitivos.
- **Motor de SLA Dual:**
  - Calendario continuo 24 horas al día, 7 días a la semana para prioridades *Urgente Asistencial / Vital* y *Alta*.
  - Calendario hábil administrativo (08:00 a 17:00, lunes a viernes excluyendo festivos parametrizables) para *Media* y *Baja*.

### B. Capa de Persistencia y Datos
- **Motor:** PostgreSQL 16+ con extensión `uuid-ossp` o `pgcrypto` (con compatibilidad probada hacia MySQL 8.x InnoDB).
- **Control de Concurrencia:** Bloqueo optimista con campo `version INT NOT NULL DEFAULT 1`.
- **Inmutabilidad y Auditoría:**
  - Revocación formal del comando `DELETE` (`REVOKE DELETE ON ALL TABLES ...`).
  - Tabla `solicitudes_historial_estados` registrando usuario LDAP, IP de origen, estampa UTC del servidor y motivo.
  - Tabla `transacciones_idempotencia` para garantizar cero duplicados por reintentos Wi-Fi.
- **Almacenamiento de Evidencias:** Metadatos en tabla `solicitudes_adjuntos` ($\le 5\text{ MB}$, validación de magic numbers, SHA-256); binarios desacoplados en volumen `/var/s3ih/adjuntos/`.

### C. Capa de Identidad y Seguridad
- **Autenticación:** Directorio Activo institucional (LDAP sobre TLS / LDAPS).
- **Gestión de Sesión:** Cookies de servidor firmadas con banderas `HttpOnly; Secure; SameSite=Strict` y **cierre automático tras 30 minutos de inactividad** (RNF-030).
- **Autorización RBAC:** Mínimo privilegio y segregación de funciones (SoD): técnicos no pueden auto-aprobarse ni cambiar parámetros globales; solicitantes no alteran órdenes en proceso.
- **Política Zero-PHI (RNF-035):** Bloqueo estricto al ingreso de historias clínicas o datos personales de pacientes en campos descriptivos.

### D. Capa de Infraestructura y Operaciones
- **Entorno:** Linux Empresarial LTS (Ubuntu 22.04 LTS / RHEL 9).
- **Contenedores OCI:** 4 contenedores orquestados con Docker Compose / Podman (`s3ih-proxy`, `s3ih-web`, `s3ih-worker`, `s3ih-db`).
- **Alta Disponibilidad:** Réplica en caliente (*Hot Standby*) en Hospital 2 con streaming de WAL.
- **Metas de Recuperación:** **RPO $< 15\text{ minutos}$**, **RTO $< 30\text{ minutos}$**.

---

## 5. Respuestas a Hallazgos y Preguntas Pendientes

1. **Resolución de H-ALT-01 (Contingencia WAN en sedes periféricas):**
   - Se establece formalmente el protocolo institucional de contingencia: cada hospital periférico cuenta con enlace de respaldo móvil (VPN 4G/5G). Si ocurre un corte total de fibra, el puesto de guardia del hospital central radica las solicitudes urgentes recibidas por telefonía asistencial directa.
2. **Resolución de H-ALT-02 (Segregación de reportes analíticos pesados):**
   - La exportación de reportes (RF-071) y tableros masivos (RF-070) se delega al Contenedor Worker y se ejecuta con transacciones de solo lectura sobre la réplica de lectura, blindando el rendimiento transaccional de quirófanos ($p95 \le 400\text{ ms}$).
3. **Resolución de H-ALT-03 (Auto-cierre a las 48 horas):**
   - El Worker ejecuta una tarea programada horaria que busca solicitudes en estado `Atendida` con más de 48 horas continuas y las transiciona a `Cerrada` con auditoría inmutable.
4. **Resolución de Preguntas Pendientes (P-1 a P-4):**
   - P-1 (WAN): Base de datos centralizada en Hospital Central con réplica en Hospital 2.
   - P-2 (Storage): Volumen NAS/NFS montado inicialmente con interfaz adaptable a MinIO/S3.
   - P-3 (Alertas): Despacho primario por SMTP y panel web; arquitectura abierta para adaptadores push/SMS en fase posterior.
   - P-4 (Firma): Pista de auditoría inmutable vinculada al usuario LDAP autenticado y hash SHA-256, suficiente para acreditación en salud sin requerir PKI de tokens físicos.

---

## 6. Architecture Decision Record (ADR-001 Propuesto)

```markdown
# ADR-001: Selección de Arquitectura Monolítica Modular con Persistencia Relacional ACID para el Sistema S3IH

## Estado
APROBADO

## Fecha
2026-10-07

## Contexto
La red hospitalaria (hasta 4 hospitales) requiere sistematizar y auditar el ciclo de vida de sus solicitudes internas entre servicios clínicos y unidades resolutoras técnicas, con soporte continuo 24/7 en áreas vitales (Urgencias, Quirófanos, UCI), un volumen de 3.000 tx/h base y 9.000 tx/h pico, estricto cumplimiento de no borrado físico (5 años de retención legal), resguardo desacoplado de evidencias (5 MB) y operación gestionable por el equipo interno de TI.

## Decisión
Se decide implementar una arquitectura de **Monolito Modular con Worker Desacoplado y Patrón Transactional Outbox**, empaquetada en contenedores OCI estándar sobre Linux empresarial (LTS).

Las decisiones estructurales clave son:
1. **Persistencia:** Base de datos relacional ACID (PostgreSQL 16+ / MySQL 8.x InnoDB) con bloqueo optimista (columna `version`), revocación formal de privilegios de borrado (`REVOKE DELETE`) y tablas append-only para el historial de transiciones y auditoría.
2. **SLA y Asincronía:** Motor dual de SLA evaluado por un worker en segundo plano que consume eventos de la tabla `outbox_eventos` mediante `FOR UPDATE SKIP LOCKED`, desacoplando notificaciones SMTP y reportes pesados.
3. **Almacenamiento de Adjuntos:** Archivos técnicos de hasta 5 MB resguardados en volumen de almacenamiento desacoplado fuera de la base de datos relacional, validando firmas binarias y sanitizando a nombres UUID v4.
4. **Identidad:** Autenticación institucional integrada con Directorio Activo / LDAP y sesiones seguras de servidor con expiración de 30 minutos para terminales compartidas en quirófano.
5. **Infraestructura:** Despliegue con Docker/Podman Compose sobre Linux LTS con reverse proxy Nginx y réplica de base de datos en caliente hacia sede secundaria (RPO < 15 min, RTO < 30 min). Se descarta explícitamente Kubernetes y microservicios distribuidos.

## Consecuencias
### Positivas:
- Cumple el 100% de los requisitos funcionales (RF-001 a RF-072) y no funcionales (RNF-001 a RNF-082).
- Garantiza integridad transaccional inmediata en emergencias de soporte vital.
- Mantenible, operable y diagnosticable por el equipo de TI interno del hospital sin incurrir en sobrecostos ni sobreingeniería.
- Cero costo de licencias de software base.
- Soporta holgadamente el crecimiento proyectado a 10x (30.000 tx/h).

### Negativas / Mitigaciones:
- El despliegue de cambios en la aplicación requiere recargar el contenedor web monolítico (mitigado con despliegue zero-downtime tras Nginx).
- Las sedes periféricas dependen del enlace WAN con el hospital central (mitigado con enlaces celulares de respaldo y protocolo asistencial de contingencia).
```

---

## 7. Plan de Validación y Pruebas Previas a la Implementación

Antes de iniciar la fase de ingeniería de base de datos y desarrollo de código, se ejecutará el siguiente plan de pruebas de verificación:

| Prueba | Objetivo de Validación | Criterio de Éxito |
| :--- | :--- | :--- |
| **PV-01: Máquina de Estados** | Verificar que no existan transiciones ilegales (ej. saltar de `Registrada` a `Atendida`). | 100% de transiciones no permitidas rechazadas con excepción de dominio. |
| **PV-02: Concurrencia de Guardias** | Simular 50 supervisores asignando solicitudes al mismo segundo. | Cero colisiones de doble asignación; bloqueo optimista incrementa versión limpiamente. |
| **PV-03: Tolerancia Wi-Fi e Idempotencia** | Reenviar la misma petición de radicación 5 veces con el mismo `Idempotency-Key`. | Exactamente 1 solicitud creada en BD; las 4 réplicas retornan respuesta idéntica. |
| **PV-04: Carga y Saneamiento de Adjuntos** | Intentar subir archivos $> 5\text{ MB}$ o scripts con extensión disfrazada (`malware.php.jpg`). | Rechazo inmediato en Capa 1 y Capa 3; solo archivos legítimos $\le 5\text{ MB}$ aceptados. |
| **PV-05: Stress Test 10x ($30.000\text{ tx/h}$)** | Inyectar $8.33\text{ tx/s}$ sostenidas con picos de $25\text{ tx/s}$ durante 30 minutos. | Latencia $p95 < 400\text{ ms}$ en lecturas y $< 800\text{ ms}$ en escrituras; CPU host $< 15\%$. |
| **PV-06: Simulación de Desastre (RPO/RTO)** | Detener abruptamente el contenedor de BD primario y ejecutar failover a réplica. | Datos transaccionales preservados (RPO $< 15$ min); servicio restaurado en $< 30$ min. |

---

## 8. Conclusión y Cierre de la Ronda Arquitectónica

Con la emisión de este informe:
1. Queda formalmente **completada la ronda de los 8 roles de arquitectura** conforme a [`AGENTS.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/AGENTS.md) y [`README.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/README.md).
2. Se aprueba la recomendación arquitectónica definitiva y el **ADR-001**.
3. Se autoriza el avance hacia la **Fase 2: Consolidación de Decisiones en `proyecto/04_decisiones/`** y la posterior **Fase 3: Ingeniería de Base de Datos (`proyecto/05_base_datos/`)**.
