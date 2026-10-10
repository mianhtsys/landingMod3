# Paso 09 — Seguridad y Control de Acceso de Base de Datos (S3IH)

**Workflow:** `02_database_workflow` — Paso 09  
**Skill utilizado:** `databases` (Production Hardening & Access Control)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/{modelo_fisico.md,seleccion_dbms.md}`  
- `proyecto/05_base_datos/06_integridad/integridad.md`  
- `proyecto/04_decisiones/decisiones_base_datos.md`  
- `proyecto/01_requisitos/RNF.md`  
- `proyecto/00_contexto/restricciones.md`  

**Objetivo:** Establecer la arquitectura y política de seguridad integral en la capa de datos: autenticación de servicios, principio de mínimo privilegio (PoLP), revocación explícita de sentencias destructivas (`REVOKE DELETE`), cifrado en tránsito y reposo, aislamiento de red y cumplimiento de la política Zero-PHI. **Sin exponer credenciales reales ni generar migraciones activas.**

---

## 1. Trazabilidad Requisitos de Seguridad $\rightarrow$ Controles de Base de Datos

| Requisito | Descripción del Requisito de Seguridad | Control Técnico en la Capa de Base de Datos |
| :--- | :--- | :--- |
| **RNF-030** | Autenticación robusta y control de sesiones (30 min en puestos compartidos de quirófano) | Cuentas dedicadas por servicio; soporte de autenticación moderna (`caching_sha2_password` / `scram-sha-256`); sin conexiones directas del usuario final a la BD. |
| **RNF-031** | Principio de Mínimo Privilegio (PoLP) y Segregación de Funciones (SoD) | Roles segregados en motor (`s3ih_app`, `s3ih_worker`, `s3ih_backup`, `s3ih_dba`); prohibición de privilegios globales `ALL PRIVILEGES` o `SUPER`. |
| **RNF-032** | Cifrado mandatorio en tránsito | TLS 1.3 / 1.2 forzado en el motor: `require_secure_transport = ON` (MySQL) / `ssl = on` (PostgreSQL); rechazo de conexiones TCP en texto claro. |
| **RNF-033** | Cifrado en reposo y protección de secretos | Cifrado a nivel de volumen de datos (LUKS / dm-crypt) o Tablespace Encryption (InnoDB/TDE). Credenciales protegidas en `.env` / Docker Secrets. |
| **RNF-034** | Prohibición absoluta de credenciales en repositorios | Ninguna contraseña, hash o cadena de conexión se incluye en scripts SQL versionados ni en commits de Git (Gobernanza `AGENTS.md`). |
| **RNF-035** | **Política Zero-PHI (Cero Información Médica Protegida)** | El esquema físico prohíbe atributos de pacientes; auditoría activa para detectar y sancionar inserciones indebidas de historiales clínicos. |
| **RN-012 / RN-013** | Inmutabilidad hospitalaria y retención legal de 5 años | Revocación terminante del privilegio `DELETE` a la aplicación: `REVOKE DELETE ON ... FROM s3ih_app_user`. Pistas append-only sin `UPDATE`. |
| **Restricción 5 / D-04** | Almacenamiento seguro desacoplado de evidencias | BD solo gestiona metadatos y hashes SHA-256; archivos fuera del WebRoot en `/var/s3ih/adjuntos/` con permisos POSIX `0640`. |

---

## 2. Segregación de Cuentas y Roles de Base de Datos

En estricta aplicación del principio de mínimo privilegio, el sistema no utiliza una única cuenta compartida ni se conecta jamás como usuario administrador (`root` o `postgres`):

```
+-----------------------------------------------------------------------------------+
|                            SEGREGACIÓN DE CUENTAS S3IH                           |
+-----------------------------------------------------------------------------------+
|  [ s3ih_app ]     --> Conexión API / Monolito (SELECT, INSERT, UPDATE - NO DELETE)|
|  [ s3ih_worker ]  --> Worker asíncrono SLA / Outbox / SMTP (SELECT, UPDATE)       |
|  [ s3ih_backup ]  --> Tareas de respaldo diario y streaming PITR (SELECT, RELOAD) |
|  [ s3ih_migrator ]--> Pipeline CI/CD de migraciones de esquema (DDL controlado)   |
|  [ s3ih_dba ]     --> Administración humana de soporte (Acceso Bastion + MFA)     |
+-----------------------------------------------------------------------------------+
```

### Especificación de Cuentas Operativas

| Cuenta de Base de Datos | Host de Conexión Autorizado | Rol / Privilegios Asignados | Función Operativa |
| :--- | :--- | :--- | :--- |
| **`s3ih_app_user`** | `app.s3ih.internal` (Docker network) | `SELECT, INSERT, UPDATE` condicional en tablas de negocio. **Sin `DELETE`**. | Backend PHP y API REST para tramitación de órdenes hospitalarias. |
| **`s3ih_worker_user`** | `worker.s3ih.internal` (Docker network) | `SELECT, UPDATE` en `ops_outbox_eventos` y `sol_control_tiempo_sla`. | Proceso daemon desacoplado para evaluación de SLA y SMTP. |
| **`s3ih_backup_user`** | `localhost` / `backup.s3ih.internal` | `SELECT, RELOAD, PROCESS, REPLICATION CLIENT, LOCK TABLES`. | Volcado lógico diario `mysqldump` / `pg_dump` y streaming de WAL/binlog. |
| **`s3ih_migrator_user`** | `deploy.s3ih.internal` | DDL sobre esquema `s3ih_db` (`CREATE, ALTER, INDEX`). Solo en despliegues. | Ejecución controlada de migraciones versionadas. |
| **`s3ih_dba_admin`** | Bastión administrativo exclusivo | Acceso administrativo completo; auditoría individualizada con clave SSH y MFA. | Operación y mantenimiento correctivo por DBA. |

---

## 3. Matriz de Autorización Granular (GRANT / REVOKE)

### 3.1 Política Terminante de No Borrado (`REVOKE DELETE`)
Para cumplir las regulaciones de trazabilidad y las reglas **RN-012** y **RN-013**, la cuenta de la aplicación tiene revocados todos los privilegios destructivos:

```sql
-- Revocación global de borrado para la aplicación
REVOKE DELETE ON *.* FROM 's3ih_app_user'@'%';
REVOKE DROP, ALTER, TRUNCATE ON *.* FROM 's3ih_app_user'@'%';
```

### 3.2 Matriz Granular por Entidad para `s3ih_app_user`

| Objeto / Tabla | SELECT | INSERT | UPDATE | DELETE | Justificación de Negocio / Restricción |
| :--- | :---: | :---: | :---: | :---: | :--- |
| `org_sedes`, `org_servicios` | Sí | No | No | **NO** | Catálogos maestros administrados solo por DBA. |
| `org_cuadrillas_guardia`, `org_cuadrilla_miembros` | Sí | Sí | Sí | **NO** | Gestión de turnos y rotación técnica 24/7. |
| `sec_roles` | Sí | No | No | **NO** | Catálogo RBAC inmutable en runtime. |
| `sec_usuarios` | Sí | Sí | Sí | **NO** | Altas, modificaciones y registro de `ultimo_acceso_at`. |
| `cat_solicitud_tipos`, `cat_causales` | Sí | No | No | **NO** | Catálogos tipificados de fallas y causales. |
| `sla_calendarios`, `sla_dias_inhabiles`, `sla_politicas` | Sí | No | No | **NO** | Parámetros de servicio administrados por jefatura. |
| `sol_solicitudes` | Sí | Sí | Sí* | **NO** | Transacciones operativas. *Campos de creación inmutables. |
| `sol_control_tiempo_sla` | Sí | Sí | Sí | **NO** | Registro del reloj y pausas de SLA. |
| `sol_historial_estados` | Sí | Sí | **NO** | **NO** | **Append-only estricto:** Prohibido modificar transiciones pasadas. |
| `sol_informes_atencion` | Sí | Sí | Sí | **NO** | Registro del cierre técnico del requerimiento. |
| `sol_reaperturas`, `sol_reasignaciones` | Sí | Sí | **NO** | **NO** | **Append-only estricto:** Pistas de eventos inmutables. |
| `sol_bitacora` | Sí | Sí | **NO** | **NO** | **Append-only estricto:** Notas técnicas cronológicas. |
| `sol_adjuntos` | Sí | Sí | **NO** | **NO** | Metadatos y hashes de archivos subidos; no se alteran. |
| `ops_idempotencia` | Sí | Sí | **NO** | **NO** | Registros de token inalterables; expiran por TTL. |
| `ops_outbox_eventos` | Sí | Sí | Sí | **NO** | Creación del evento y actualización de estado por worker. |
| `audit_eventos` | Sí | Sí | **NO** | **NO** | **Pista legal inmutable:** Prohibido `UPDATE` y `DELETE`. |

---

## 4. Cifrado y Protección Criptográfica

### 4.1 Cifrado en Tránsito (Network Security)
1. **Configuración del Servidor:**
   - En MySQL 8.x: `require_secure_transport = ON`.
   - En PostgreSQL 16+: `ssl = on`, `ssl_min_protocol_version = 'TLSv1.2'`.
   - Certificados X.509 emitidos por la Autoridad Certificadora (CA) interna de la red de salud.
2. **Configuración del Cliente PHP PDO:**
   ```php
   $pdoOptions = [
       PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/hospital_internal_ca.pem',
       PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
       PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES => false, // Prevención nativa de SQL Injection
   ];
   ```

### 4.2 Cifrado en Reposo (Storage Security)
1. **Volumen de Base de Datos:**
   - Encriptación completa de partición mediante **LUKS (dm-crypt)** con algoritmo `AES-XTS-PLAIN64` y clave de 512 bits en el servidor host Linux.
2. **Archivos de Evidencias Técnicas (`/var/s3ih/adjuntos/`):**
   - Volumen dedicado montado con cifrado a nivel de bloque para garantizar confidencialidad física ante sustracción de discos.

### 4.3 Criptografía de Auditoría Encadenada (No Repudio)
- En la tabla `audit_eventos`, cada registro incorpora la columna `hash_encadenado_sha256`:
  $$\text{Hash}_i = \text{SHA256}(\text{Hash}_{i-1} \parallel \text{id} \parallel \text{usuario\_id} \parallel \text{accion} \parallel \text{registro\_id} \parallel \text{datos\_nuevos\_json} \parallel \text{created\_at\_utc})$$
- Cualquier intento indebido de alterar un registro histórico rompe matemáticamente la cadena de custodia, garantizando no repudio judicial en auditorías hospitalarias.

---

## 5. Prevención de Inyección SQL y Seguridad en Consultas

En cumplimiento de las buenas prácticas del skill `databases`:

1. **Consultas Estrictamente Parametrizadas:**
   - Queda terminantemente prohibida la concatenación de variables en cadenas SQL:
     ```php
     // CORRECTO: Parámetros vinculados (evita SQL Injection)
     $stmt = $pdo->prepare('SELECT * FROM sol_solicitudes WHERE codigo_correlativo = :codigo');
     $stmt->execute([':codigo' => $codigo]);
     ```
2. **Desactivación de Emulación de Preparadas:**
   - `PDO::ATTR_EMULATE_PREPARES = false`: Obliga al motor a utilizar el protocolo binario real de sentencias preparadas, imposibilitando inyecciones por manipulación de juegos de caracteres.

---

## 6. Seguridad en el Almacenamiento de Evidencias Técnicas (Restricción 5, D-04)

Los archivos adjuntos constituyen un vector crítico de ataque (cargas maliciosas, web shells). Se implementa una **defensa en 6 capas**:

1. **Ubicación Externa:** Los archivos físicos se almacenan en `/var/s3ih/adjuntos/`, ruta estrictamente fuera del directorio raíz web (`DocumentRoot`), haciendo imposible su ejecución HTTP directa.
2. **Ofuscación de Nombres:** El nombre físico es un UUID v4 (`uuid_almacenamiento.ext`), desvinculando la ruta física del nombre original subido por el usuario.
3. **Validación de Magic Numbers:** La aplicación no confía en la cabecera `Content-Type` ni en la extensión provista por el navegador; inspecciona los primeros bytes del archivo (magic numbers) mediante `finfo_file()`.
4. **Lista Blanca Estricta:** Solo se aceptan extensiones seguras: `pdf`, `jpg`, `jpeg`, `png`. Todo archivo ejecutable (`.exe`, `.php`, `.sh`, `.bat`) se rechaza de inmediato.
5. **Límite Físico y de BD:** Límite máximo de $5.242.880\text{ bytes}$ (5 MB) controlado en `php.ini`, Nginx (`client_max_body_size 5M`) y constraint `CHECK` en `sol_adjuntos`.
6. **Permisos POSIX:** Permisos `0640` (lectura/escritura para el servicio, sin permiso de ejecución `chmod -x`).

---

## 7. Aislamiento de Red y Endurecimiento del Servidor de Base de Datos

Siguiendo el checklist de producción del skill `databases`:

```
+---------------------------------------------------------------------------------+
|                       TOPOLOGÍA DE RED Y FIREWALL S3IH                          |
+---------------------------------------------------------------------------------+
|                                                                                 |
|   RED EXTERNA / INTERNET:   [ DENEGADO ACCESO A PUERTOS 3306 / 5432 ]           |
|                                     │                                           |
|                                     ▼                                           |
|   RED DMZ / HOSPITALES:      Nginx Reverse Proxy (HTTPS :443)                   |
|                                     │                                           |
|                                     ▼ (Red Docker Interna 'backend-net')        |
|   RED PRIVADA APLICACIÓN:    PHP Monolito & Worker                              |
|                                     │                                           |
|                                     ▼ (Red Docker Interna 'db-net')             |
|   RED PRIVADA BASE DATOS:    MySQL / PostgreSQL (:3306/:5432 en 127.0.0.1)      |
|                                                                                 |
+---------------------------------------------------------------------------------+
```

1. **Sin Exposición Pública:** Los puertos de base de datos (`3306` / `5432`) no se mapean al host en entornos productivos (`ports: - "127.0.0.1:3306:3306"` o comunicación interna de Docker Network).
2. **Eliminación de Cuentas por Defecto:**
   - Eliminación de bases de datos de prueba (`test`).
   - Revocación de usuarios anónimos (`DELETE FROM mysql.user WHERE User='';`).
   - Inhabilitación de login remoto directo para la cuenta `root`.
3. **Protección contra Fuerza Bruta:** Bloqueo de IP tras 5 intentos fallidos consecutivos de autenticación y timeout estricto de conexión inactiva.

---

## 8. Cumplimiento de la Política Zero-PHI (RNF-035)

- **Auditoría Preventiva de Campos de Texto:**  
  La columna `descripcion_falla` en `sol_solicitudes` y `diagnostico_falla` en `sol_informes_atencion` incorporan validación por expresiones regulares en la capa de servicio para detectar y rechazar patrones compatibles con identificaciones personales o números de historia clínica (*Zero-PHI Guard*).
- **Compromiso en Capa de Datos:**  
  El diseño físico no almacena datos de pacientes, manteniendo el sistema estrictamente enfocado en la infraestructura locativa y médica hospitalaria.

---

## 9. Conclusión y Resumen de Estado

| Control de Seguridad | Estado | Mecanismo de Implementación |
| :--- | :---: | :--- |
| **Mínimo Privilegio (PoLP)** | **APROBADO** | Cuentas de motor segregadas (`app`, `worker`, `backup`, `migrator`). |
| **Inmutabilidad y No Borrado** | **APROBADO** | `REVOKE DELETE` global a la aplicación y tablas append-only. |
| **Cifrado en Tránsito** | **APROBADO** | TLS 1.3 / 1.2 obligatorio en conexiones cliente-servidor. |
| **Cifrado en Reposo** | **APROBADO** | LUKS / TDE y almacenamiento desacoplado protegido en 6 capas. |
| **No Repudio y Pista Legal** | **APROBADO** | Pista de auditoría con encadenamiento criptográfico SHA-256 por 5 años. |
| **Aislamiento de Red** | **APROBADO** | Red privada interna Docker sin exposición a Internet. |
| **Zero-PHI** | **APROBADO** | Ausencia total de datos clínicos protegidos en el esquema. |

---

**Estado del Paso:** Paso 09 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 10 — Auditoría, Histórico y Versionamiento (`proyecto/05_base_datos/08_auditoria/auditoria.md` y `09_versionamiento/versionamiento.md`), utilizando `database-schema-designer` y `databases`.
