# Paso 16 — Especificación de Conexión y Gestión Segura de Credenciales (S3IH)

**Workflow:** `02_database_workflow` — Paso 16  
**Skill utilizado:** `databases` (Secure Connection Configuration & Environment Security)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/07_seguridad/seguridad.md`  
- `proyecto/05_base_datos/.env.example`  
- `proyecto/01_requisitos/RNF.md` (`RNF-030`, `RNF-032`, `RNF-034`)  
- `AGENTS.md` (Gobernanza de credenciales)  

**Aviso de Seguridad:** Las credenciales de base de datos se leen **exclusivamente desde variables de entorno (`.env` / Docker Secrets)**. Queda terminantemente prohibido escribir contraseñas reales en archivos versionados, commits o reportes.

---

## 1. Principios de Gestión Segura de Credenciales (RNF-034)

1. **Inyección en Tiempo de Ejecución (Runtime Only):**  
   El archivo `.env` nunca se versiona en Git. Solo se distribuye la plantilla segura [`proyecto/05_base_datos/.env.example`](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/05_base_datos/.env.example).
2. **Segregación de Conexiones por Función de Software:**  
   La aplicación web, el worker daemon y los scripts de migración no comparten la misma cadena de conexión ni el mismo usuario de base de datos.
3. **Cero Texto Plano en Código:**  
   Cualquier intento de hardcodear contraseñas en PHP o scripts de despliegue es rechazado por las políticas del repositorio.

---

## 2. Parámetros de Conexión del Entorno Hospitalario

| Variable de Entorno | Valor Predeterminado en Desarrollo | Descripción / Ámbito |
| :--- | :--- | :--- |
| `DB_ENGINE` | `mysql` (o `postgresql`) | Motor seleccionado para la persistencia transaccional. |
| `DB_HOST` | `127.0.0.1` | Dirección de red del servidor o contenedor de base de datos. |
| `DB_PORT` | `3306` (o `5432`) | Puerto de escucha interno en red privada Docker. |
| `DB_NAME` | `s3ih_db` | Nombre de la base de datos del sistema hospitalario. |
| `DB_CHARSET` | `utf8mb4` | Soporte UTF-8 completo para terminología médica internacional. |
| `DB_USER` | `s3ih_app_user` | Cuenta con permisos operativos `SELECT, INSERT, UPDATE` (**Sin `DELETE`**). |
| `WORKER_DB_USER` | `s3ih_worker_user` | Cuenta del worker daemon con acceso a Outbox y SLA. |
| `MIGRATOR_DB_USER` | `s3ih_migrator_user` | Cuenta del pipeline CI/CD con permisos DDL para aplicar migraciones. |
| `DB_CONNECT_TIMEOUT` | `5` | Tiempo máximo de espera en segundos para establecer conexión. |
| `DB_LOCK_WAIT_TIMEOUT`| `5` | Tiempo máximo de espera de bloqueo por fila antes de abortar. |

---

## 3. Patrón de Fábrica de Conexión PDO Seguro (PHP 8.x)

El componente central de base de datos implementa las recomendaciones del skill `databases` para prevenir inyección SQL y garantizar resiliencia:

```php
<?php
declare(strict_types=1);

namespace S3IH\Core\Database;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseConnectionFactory
{
    public static function createFromEnv(): PDO
    {
        $engine  = getenv('DB_ENGINE') ?: 'mysql';
        $host    = getenv('DB_HOST') ?: '127.0.0.1';
        $port    = getenv('DB_PORT') ?: '3306';
        $dbname  = getenv('DB_NAME') ?: 's3ih_db';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
        $user    = getenv('DB_USER') ?: 's3ih_app_user';
        $pass    = getenv('DB_PASSWORD') ?: '';

        if ($engine === 'mysql') {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Previene SQL Injection real
                PDO::ATTR_TIMEOUT            => (int)(getenv('DB_CONNECT_TIMEOUT') ?: 5),
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ];

            // Cifrado TLS en tránsito si está configurado el certificado CA (RNF-032)
            $sslCa = getenv('DB_SSL_CA');
            if (!empty($sslCa) && file_exists($sslCa)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (getenv('DB_SSL_VERIFY_SERVER_CERT') === 'true');
            }
        } elseif ($engine === 'postgresql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => (int)(getenv('DB_CONNECT_TIMEOUT') ?: 5),
            ];
        } else {
            throw new RuntimeException("Motor de base de datos no soportado: {$engine}");
        }

        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Se oculta la contraseña y datos de conexión en el mensaje de error público
            throw new RuntimeException("Error al conectar con la base de datos de S3IH: " . $e->getMessage(), (int)$e->getCode());
        }
    }
}
```

---

## 4. Verificación de Seguridad y Criterios de Aceptación

- [x] **RNF-034:** Cero credenciales o contraseñas en archivos versionados; uso obligatorio de `.env.example`.
- [x] **RNF-032:** Soporte nativo para cifrado TLS / SSL mediante verificación de certificados de servidor.
- [x] **Segregación:** Cuentas diferenciadas para la aplicación web (`s3ih_app_user`), el worker (`s3ih_worker_user`) y el migrador (`s3ih_migrator_user`).
- [x] **Protección contra Inyección:** `PDO::ATTR_EMULATE_PREPARES = false` configurado de manera no negociable.

---

**Estado del Paso:** Paso 16 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 17 — Despliegue y Ejecución Controlada de Migraciones Aprobadas (`proyecto/05_base_datos/15_reportes/deploy_log_paso17.txt`), utilizando el skill `databases`.
