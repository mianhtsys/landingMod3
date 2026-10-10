# Comparación de Alternativas Arquitectónicas — Sistema Hospitalario S3IH

Fecha: 2026-10-07  
Fase: 03 — Diseño y Evaluación Arquitectónica  
Rol: `architect`  
Skill: `architecture-design`  
Fuente: `01_requirements_analysis.md`, `02_decision_scope.md`, drivers D1 a D8, criterios CA-001 a CA-011  
Estado: Propuesta formal para evaluación por especialistas de persistencia, seguridad y DevOps  

---

## 1. Contexto de Diseño y Drivers Rectores

El **Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH)** atiende una red de **hasta 4 hospitales** con un dimensionamiento nominal base de **3.000 transacciones de negocio por hora** ($\approx 0.83\text{ tx/s}$), multiplicador pico de **9.000 tx/h** ($\approx 2.5\text{ tx/s}$) y validación obligatoria para un crecimiento de **10x** (**30.000 tx/h** $\approx 8.33\text{ tx/s}$).

Los factores no negociables que rigen la evaluación son:
1. **Operación continua 24/7 en servicios de soporte vital** (Urgencias, Quirófanos, UCI) con disponibilidad $\ge 99.9\%$ (RNF-040).
2. **Motor de SLA dual** (24/7 continuo para vital vs. hábil administrativo) con alertas preventivas al 75% y escalamiento jerárquico (RF-051 a RF-053).
3. **Integridad transaccional ACID estricta** en transiciones de estado e inmutabilidad (prohibición de borrado físico, RN-005, RN-012).
4. **Almacenamiento desacoplado de evidencias técnicas** de hasta 5 MB (Restricción Técnica 5, RNF-004).
5. **Desacoplamiento asíncrono de dependencias externas** (SMTP, reportes) para evitar bloqueos del flujo asistencial (Restricción Técnica 6, RNF-041).
6. **Proporcionalidad y descarte de sobreingeniería**: Solución operable y mantenible por el equipo de TI interno sin microservicios injustificados ni orquestadores sobredimensionados (CA-010, Restr. Org. 4).

---

## 2. Descripción Detallada de Alternativas

---

### Alternativa A: Monolito Modular con Worker Desacoplado y Transactional Outbox (Recomendada)

Arquitectura basada en un monolito modular empaquetado en contenedores OCI estándar, donde los módulos de negocio comparten un mismo proceso transaccional de aplicación pero mantienen fronteras estrictas de código, acoplados a un **proceso worker en segundo plano** que procesa eventos asíncronos mediante el patrón **Transactional Outbox**.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                          Red Corporativa Hospitalaria (LAN/WAN)                        │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │ HTTPS / TLS 1.3
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        Reverse Proxy / Balanceador L7 (Nginx / Caddy)                 │
└─────────────────────┬────────────────────────────────────────────────────┬─────────────┘
                      │ Peticiones Web / API                               │ Estáticos / Adjuntos (lectura)
                      ▼                                                    ▼
┌─────────────────────────────────────────────────────────────┐ ┌────────────────────────┐
│         Contenedor App: Monolito Modular S3IH               │ │ Repositorio de Archivos│
│  ┌───────────────────────────────────────────────────────┐  │ │ (NAS / NFS montado     │
│  │ Capa de Controladores / Middleware (Auth LDAP + RBAC) │  │ │  o MinIO corporativo)  │
│  └──────────────────────────┬────────────────────────────┘  │ │                        │
│  ┌──────────────────────────▼────────────────────────────┐  │ │ Evidencias fotográficas│
│  │ Capa de Servicios / Dominio Modular:                  │  │ │ y documentos ≤ 5 MB    │
│  │ - M1: Sedes, Servicios y Cuadrillas de Guardia        │  │ └───────────▲────────────┘
│  │ - M2: Catálogo y Políticas de SLA                     │  │             │
│  │ - M3: Radicación e Idempotencia (UUID)                │  │             │ Subida desacoplada
│  │ - M4: Asignación y Control de Concurrencia (Version)  │  │             │
│  │ - M5: Máquina de Estados Finita Determinista          │  │             │
│  │ - M6: Bitácora, Pista de Auditoría e Inmutabilidad    │  │             │
│  └──────────────────────────┬────────────────────────────┘  │             │
│  ┌──────────────────────────▼────────────────────────────┐  │             │
│  │ Capa de Persistencia / Transacciones ACID             │──┼─────────────┘
│  └──────────────────────────┬────────────────────────────┘  │ (Guarda metadatos y ruta)
└─────────────────────────────┼───────────────────────────────┘
                              │ Lectura / Escritura ACID
                              ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│            Base de Datos Relacional Transaccional (PostgreSQL / MySQL 8 InnoDB)         │
│  ┌──────────────────────────────────────────────────────────────────────────────────┐  │
│  │ Tablas de Dominio: Sedes, Usuarios, Solicitudes, Transiciones, Bitácora         │  │
│  ├──────────────────────────────────────────────────────────────────────────────────┤  │
│  │ Tabla 'outbox_eventos': Eventos de SLA al 75%, escalamiento, despacho de emails   │  │
│  └──────────────────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────▲──────────────────────────────────────────────────────────┘
                              │ Polling atómico (FOR UPDATE SKIP LOCKED)
                              │
┌─────────────────────────────┴──────────────────────────────────────────────────────────┐
│             Contenedor Worker: Despachador Asíncrono de Fondo (Daemon)                 │
│  - Evaluador continuo de reloj de SLA (24/7 vs Hábil administrativo)                   │
│  - Generador de alertas de vencimiento (75%) y escalamiento jerárquico                 │
│  - Despachador asíncrono de correos electrónicos corporativos (SMTP)                   │
│  - Generador de reportes consolidados pesados en CSV/PDF                               │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

#### Mecanismos Clave
- **Concurrencia:** Bloqueo optimista (*optimistic locking*) con campo `version` en la tabla de solicitudes. Al asignar o transicionar, la sentencia verifica `WHERE id = :id AND version = :version`. Si colisiona por concurrencia entre supervisores de guardia, reintenta o rechaza limpiamente sin bloquear la BD.
- **Idempotencia:** Cabecera HTTP `Idempotency-Key` registrada en tabla `transacciones_idempotencia` con índice UNIQUE y expiración de 24 horas.
- **Desacoplamiento asíncrono:** Toda radicación o cambio de estado escribe en una sola transacción ACID la solicitud y el evento correspondiente en `outbox_eventos`. El worker en segundo plano consume los eventos sin retener ni bloquear el hilo web del usuario asistencial.
- **Almacenamiento de adjuntos:** Los binarios se validan (MIME real, extensión, $\le 5\text{ MB}$) y se guardan directamente en el sistema de almacenamiento desacoplado generando un hash/UUID; en la BD solo se guarda la URI y metadatos.

---

### Alternativa B: Monolito Modular con Cola de Mensajes Externa Dedicada (Redis/RabbitMQ)

Mantiene el núcleo monolítico modular pero reemplaza el patrón *Transactional Outbox* en base de datos por un broker de mensajería externo en memoria o basado en colas (Redis Streams / RabbitMQ).

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        Reverse Proxy / Balanceador L7 (Nginx / Caddy)                 │
└─────────────────────┬────────────────────────────────────────────────────┬─────────────┘
                      │ Peticiones Web / API                               │ Estáticos / Adjuntos
                      ▼                                                    ▼
┌─────────────────────────────────────────────────────────────┐ ┌────────────────────────┐
│         Contenedor App: Monolito Modular S3IH               │ │ Repositorio de Archivos│
│  - Controladores, Dominio Modular, Máquina de Estados       │ │ (MinIO / S3 on-premise)│
└──────────────────┬──────────────────────┬───────────────────┘ └────────────────────────┘
                   │                      │ Publica evento
                   │ Transacciones ACID   ▼
                   │             ┌───────────────────────────────────────────────────────┐
                   │             │ Broker de Mensajería: Redis Streams / RabbitMQ        │
                   │             └────────────────────────┬──────────────────────────────┘
                   ▼                                      │ Consume tareas
┌──────────────────────────────────────────────┐          ▼
│ Base de Datos Relacional (PostgreSQL/MySQL) │ ┌────────────────────────────────────────┐
│ - Tablas de Dominio y Auditoría Inmutable    │ │ Contenedor Worker: Procesador de Colas │
└──────────────────────────────────────────────┘ │ - Notificaciones SMTP / Escalamiento   │
                                                 │ - Cálculo periódico de SLA             │
                                                 └────────────────────────────────────────┘
```

#### Mecanismos Clave
- **Concurrencia:** Bloqueo optimista general con opción de bloqueo pesimista (`SELECT FOR UPDATE`) para solicitudes con prioridad Urgente Asistencial / Vital.
- **Idempotencia:** Almacenamiento de claves de idempotencia en Redis con TTL de 24 horas para respuesta de ultrabaja latencia ($< 5\text{ ms}$).
- **Desacoplamiento asíncrono:** La aplicación emite mensajes directamente al broker de colas tras confirmar la transacción de base de datos.
- **Almacenamiento de adjuntos:** Servicio de objetos MinIO desplegado en contenedor con buckets dedicados por sede hospitalaria.

---

### Alternativa C: Arquitectura Orientada a Microservicios Distribuidos (Evaluación Adversarial)

Descompone el dominio hospitalario en 5 servicios independientes con despliegue y persistencia separada (*database-per-service*), orquestados sobre un clúster Kubernetes.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                               API Gateway Hospitalario (Kong / Traefik)                │
└────────┬───────────────────┬───────────────────┬───────────────────┬───────────────────┘
         │                   │                   │                   │
         ▼                   ▼                   ▼                   ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐ ┌───────────────┐
│ Servicio        │ │ Servicio        │ │ Servicio        │ │ Servicio        │ │ Servicio      │
│ Identidad/Sedes │ │ Solicitudes     │ │ Motor de SLA    │ │ Evidencias      │ │ Auditoría     │
│ (DB Sedes)      │ │ (DB Tickets)    │ │ (DB Métricas)   │ │ (S3 API + DB)   │ │ (DB Logs)     │
└────────┬────────┘ └────────┬────────┘ └────────┬────────┘ └────────┬────────┘ └───────┬───────┘
         │                   │                   │                   │                  │
         └───────────────────┴─────────┬─────────┴───────────────────┴──────────────────┘
                                       │ Event Bus Distribuido
                                       ▼ (Apache Kafka / RabbitMQ Clúster)
                       ┌────────────────────────────────────────────────┐
                       │ Orquestación Compleja: Clúster Kubernetes (K8s)│
                       └────────────────────────────────────────────────┘
```

#### Mecanismos Clave
- **Concurrencia:** Patrón Saga con transacciones compensatorias distribuidas y eventual consistency.
- **Idempotencia:** Implementada independientemente en cada microservicio con almacenes locales.
- **Desacoplamiento:** Totalmente desacoplado a nivel de red y proceso.

---

## 3. Matriz Comparativa Multidimensional

| Criterio de Evaluación | Alternativa A: Monolito Modular + Outbox | Alternativa B: Monolito + Cola Externa (Redis) | Alternativa C: Microservicios Distribuidos |
| :--- | :---: | :---: | :---: |
| **Complejidad de Desarrollo y Arquitectura** | **Baja** (Un solo stack de código, modularidad por namespaces/carpetas, sin RPC distribuido) | **Media** (Requiere cliente de colas, gestión de conexión al broker y manejo de reconexiones) | **Muy Alta** (Llamadas remotas, serialización, transacciones distribuidas, versionado de contratos de API) |
| **Complejidad Operacional y Despliegue** | **Baja** (2 contenedores: Web y Worker; administrable con Docker Compose / Podman en Linux) | **Media** (3 contenedores: Web, Worker, Redis; requiere monitorear persistencia de colas) | **Extrema** (Clúster Kubernetes, ingress controller, service mesh, múltiples bases de datos) |
| **Consistencia Transaccional (ACID)** | **Excelente** (Atomicidad nativa; la solicitud y el evento outbox se confirman en el mismo commit) | **Buena** (Posible inconsistencia si la BD hace commit pero el envío a la cola falla: requiere Outbox auxiliar) | **Débil / Eventual** (Inconsistencias temporales en soporte vital incompatibles con RN-005) |
| **Rendimiento y Latencia ($p95$)** | **Excelente** (Consultas directas en proceso: lecturas $< 200\text{ ms}$, escrituras $< 400\text{ ms}$) | **Excelente** (Muy rápido en memoria; escrituras $< 350\text{ ms}$) | **Deficiente** (Saltos de red inter-servicios aumentan latencia: $p95 > 600\text{ ms}$ en detalle) |
| **Capacidad Base (3.000 tx/h) y Pico (9.000 tx/h)** | **Sobrante** (3.000 tx/h son $\approx 0.83\text{ tx/s}$; cualquier Linux moderno procesa $> 500\text{ tx/s}$) | **Sobrante** (Absorbe ráfagas sin esfuerzo) | **Sobredimensionada innecesariamente** |
| **Viabilidad Escenario 10x (30.000 tx/h $\approx 8.33\text{ tx/s}$)** | **Comprobada** ($8.33\text{ tx/s}$ representa una carga moderada para una sola instancia optimizada) | **Comprobada** (Soporta fácilmente $> 50\text{ tx/s}$) | **Comprobada**, pero con desperdicio masivo de recursos |
| **Disponibilidad 24/7 y Resiliencia** | **Alta (99.9%)** (Degradación elegante: si el worker se reinicia, las solicitudes siguen radicándose) | **Alta (99.9%)** (Dependencia adicional de la salud del broker de colas) | **Media-Baja** (Mayor superficie de falla: si el servicio de identidad o la red interna fallan, se cae el flujo) |
| **Recuperación ante Desastres (RPO/RTO)** | **Excelente** (RPO $\le 15$ min mediante WAL/binlogs; RTO $< 30$ min levantando 2 contenedores) | **Buena** (Requiere sincronizar backups de BD relacional y snapshot RDB/AOF de Redis) | **Muy Compleja** (Sincronización consistente de 5 bases de datos diferentes en desastre) |
| **Costo Operativo y Mantenibilidad TI** | **Óptimo** (Mantenible íntegramente por el equipo de TI del hospital sin cuadrillas externas) | **Bueno** (Demanda conocimiento de administración de Redis en producción) | **Inviable** (Exige equipo especializado en Kubernetes, SREs dedicados y altos costos de cómputo) |
| **Cumplimiento de No-Sobreingeniería (CA-010)** | **Cumple al 100%** | **Cumple parcialmente** | **Violación Crítica** (Sobreingeniería injustificada) |

---

## 4. Evaluación de Drivers Arquitectónicos Específicos

| Driver Arquitectónico | Alternativa A (Monolito + Outbox) | Alternativa B (Monolito + Redis) | Alternativa C (Microservicios) |
| :--- | :--- | :--- | :--- |
| **D1: SLA Asistencial 24/7** | ✅ Worker desacoplado evalúa periódicamente sin competir con transacciones clínicas. | ✅ Worker consume eventos de la cola en tiempo real. | ⚠️ Riesgo de desincronización de eventos entre servicios independientes. |
| **D2: Integridad y Concurrencia** | ✅ Lock optimista con `version` previene colisiones sin deadlocks. Transacciones 100% ACID. | ✅ Lock optimista/pesimista soportado. Requiere vigilar fallo dual BD/Cola. | ❌ Consistencia eventual inaceptable para estados clínicos críticos. |
| **D3: Inmutabilidad y Auditoría** | ✅ Pista de auditoría append-only en la misma transacción relacional (no repudiable). | ✅ Registros inmutables en BD transaccional. | ⚠️ Auditoría distribuida requiere correlación de trazas compleja. |
| **D4: Adjuntos Desacoplados (5 MB)** | ✅ Adaptador a NAS/NFS montado o MinIO; solo URIs en BD transaccional. | ✅ MinIO dedicado por bucket; URIs en BD. | ✅ Servicio de archivos independiente. |
| **D5: Desacoplamiento Asíncrono** | ✅ Falla de SMTP o reportes no detiene radicación; los eventos quedan en `outbox_eventos`. | ✅ Falla de SMTP contenida en la cola con reintentos automáticos. | ✅ Aislamiento por servicio. |
| **D6: Seguridad, RBAC y Privacidad** | ✅ Middleware centralizado valida sesiones de 30 min y bloquea PHI en una sola capa de control. | ✅ Idéntico a A. | ⚠️ Propagación de tokens JWT y contextos de autorización entre múltiples servicios. |
| **D7: Proporcionalidad y Simplicidad** | ✅ **Máxima alineación con CA-010 y Restricción Organizacional 4.** | ⚠️ Añade un componente de infraestructura extra. | ❌ **Rechazo total por sobreingeniería extrema.** |
| **D8: Portabilidad OCI en Linux LTS** | ✅ 2 contenedores ligeros configurados por variables de entorno sin secretos en código. | ✅ 3 contenedores estándar. | ❌ Exige infraestructura compleja de orquestación. |

---

## 5. Análisis del Escenario de Crecimiento 10x (30.000 tx/h)

El requisito **RNF-021** y criterio **CA-006** exigen demostrar cuantitativamente la viabilidad en un escenario de 10x la carga base:
- **Carga nominal base:** $3.000\text{ transacciones de negocio/hora} = 0.833\text{ tx/s}$.
- **Carga pico 3x:** $9.000\text{ transacciones de negocio/hora} = 2.50\text{ tx/s}$.
- **Carga 10x:** $30.000\text{ transacciones de negocio/hora} = 8.333\text{ tx/s}$.

### Comportamiento de la Alternativa A ante 10x
1. **Capacidad de Cómputo Web:** Un servidor de aplicaciones moderno sobre Linux (PHP 8.x / Python / Go / Node.js) maneja típicamente entre **$150$ y $1.200$ peticiones HTTP por segundo** por núcleo de CPU bajo carga transaccional ligera/media. A $8.33\text{ tx/s}$, la utilización de CPU del contenedor web será **inferior al 5%** en una máquina virtual corporativa estándar de 4 vCPUs.
2. **Capacidad de Persistencia:** Motores relacionales estándar (PostgreSQL 16 / MySQL 8 InnoDB) con almacenamiento SSD manejan de forma nativa entre **$2.000$ y $15.000$ transacciones ACID por segundo**. El volumen de $8.33\text{ tx/s}$ equivale a menos del 1% del límite de saturación de I/O de la base de datos.
3. **Escalamiento Horizontal:** Si se requiriera redundancia física o aislamiento por hospital, la Alternativa A puede escalar horizontalmente levantando múltiples instancias del contenedor web detrás del reverse proxy Nginx (stateless con sesiones en base de datos o tokens firmados), manteniendo la base de datos única centralizada con réplica de lectura.
4. **Almacenamiento de Adjuntos (5 años):**
   - $1.500.000\text{ solicitudes} \times 2\text{ adjuntos promedio} \times 1.5\text{ MB} \approx 4.5\text{ Terabytes}$ en 5 años.
   - Un volumen NAS/NFS montado o MinIO absorbe este volumen incrementalmente a razón de $< 1\text{ TB/año}$ sin impactar el rendimiento de las consultas transaccionales de la base de datos relacional.

---

## 6. Recomendación Arquitectónica Formal

Como **Arquitecto de Solución**, la recomendación formal es:

> **Seleccionar la Alternativa A: Monolito Modular con Worker Desacoplado y Transactional Outbox**, con la opción de incorporar un broker en memoria (Alternativa B) únicamente si durante las pruebas de carga el volumen de notificaciones asíncronas justifica la infraestructura adicional.
> 
> **Descartar terminantemente la Alternativa C (Microservicios / Kubernetes)** en cumplimiento estricto del criterio **CA-010** y las restricciones organizacionales de la red hospitalaria, evitando sobrecostos, fragilidad en la consistencia asistencial y sobrecarga inmanejable para el equipo de TI interno.

---

## 7. Traspaso a Especialistas

Se remite este análisis a los siguientes especialistas técnicos en la secuencia requerida por [`AGENTS.md`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/AGENTS.md):

1. **`database-specialist` (`04_database_analysis.md`):**  
   - Evaluar neutralmente PostgreSQL vs. MySQL 8 / InnoDB vs. SQL Server.
   - Diseñar el mecanismo de bloqueo optimista (`version`) para asignación de guardias concurrentes.
   - Formalizar la estructura de la tabla `outbox_eventos` y la tabla de `transacciones_idempotencia`.
2. **`security-reviewer` (`05_security_analysis.md`):**  
   - Validar la integración con el Directorio LDAP/Active Directory corporativo.
   - Auditar el aislamiento estricto de datos de pacientes (cero PHI).
3. **`devops-architect` (`06_devops_analysis.md`):**  
   - Formalizar la configuración Docker/Podman sobre Linux LTS.
   - Justificar técnicamente la exclusión de Kubernetes y documentar el plan de contingencia RPO $\le 60\text{ min}$ / RTO $\le 180\text{ min}$.
