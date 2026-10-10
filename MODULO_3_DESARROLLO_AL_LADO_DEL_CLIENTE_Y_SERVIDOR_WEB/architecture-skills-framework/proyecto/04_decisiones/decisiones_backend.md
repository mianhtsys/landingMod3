# Decisiones de Backend — Sistema Hospitalario S3IH

**Estado:** APROBADO  
**Fecha:** 2026-10-07 (Actualizado y armonizado: 2026-10-08)  
**Dominio:** Arquitectura Backend para S3IH (Fase Didáctica y Visión Macro)  

---

## 1. Stack Tecnológico y Runtime

- **Lenguaje:** PHP 8.3 puro con tipado estricto obligatorio (`declare(strict_types=1);`).
- **Arquitectura:** MVC Modular en capas sin frameworks pesados externos (sin Laravel, Symfony, Slim ni ORMs de terceros).
- **Acceso a Datos:** Conexión singleton mediante PDO (`App\Core\Database::conn()`) con sentencias preparadas nativas y transacciones ACID.
- **Configuración:** Carga segura de variables de entorno mediante helper `envRequired()` desde archivo `.env` local (con plantilla higienizada `.env.example`).

---

## 2. Flujo de Procesamiento en Capas (`proyecto/06_codigo/app/`)

```
HTTP Request (API o Web)
     │
     ▼
[Router] (Enrutador determinista por método y regex de ruta)
     │
     ▼
[Controllers]
  ├─ Controllers/Web (Renderizado de vistas HTML con View::render)
  └─ Controllers/Api (Respuesta JSON estricta mediante Response::execute)
     │
     ▼
[Validators]
  ├─ CategoriaValidator (Validación de IDs, payload y jerarquía)
  └─ ProductoValidator (Validación de SKU, nombre, unidad y categoria_id)
     │
     ▼
[Services de Dominio]
  ├─ AuthService (Autenticación Argon2id, regeneración de sesión, me/logout)
  ├─ CategoriaService (Reglas de árbol, detección anti-cíclica auto-padre)
  └─ ProductoService (Integridad referencial, control de colisión de SKU)
     │
     ▼
[Repositories]
  ├─ UsuarioRepository (Búsqueda por email institucional)
  ├─ CategoriaRepository (Operaciones CRUD con inactivación lógica)
  └─ ProductoRepository (Operaciones CRUD, existsSku con excludeId)
     │
     ▼
[Base de Datos Relacional]
  MySQL 8.x / InnoDB (Tablas: auth_usuario, cat_categoria, cat_producto)
```

---

## 3. Manejo de Errores y Sanitización (`App\Core\Response`)

El despachador central `Response::execute(callable $action, int $status)` encapsula el manejo de errores para evitar fugas de información:
- **`PDOException`:** Capturada prioritariamente; emite log interno en servidor y retorna error genérico `500 Error de base de datos` (cero filtración de credenciales, nombres de tablas o sentencias SQL al cliente).
- **`RuntimeException`:** Mapeada a códigos HTTP semánticos (`400 Bad Request`, `401 Unauthorized`, `404 Not Found`, `409 Conflict`).
- **`Throwable`:** Cualquier otra falla inesperada responde con `500 Error interno del servidor`.

---

## 4. Seguridad de Sesión y Hardening (RNF-030)

- **Cookie Institucional:** `s3ih_session` con `HttpOnly: true`, `SameSite: Lax` y `path: /`.
- **Tiempo de Expiración:** 1800 segundos (30 minutos según RNF-030 para puestos clínicos compartidos).
- **Anti-Fijación:** Ejecución de `session_regenerate_id(true)` inmediatamente tras la autenticación de credenciales.
- **Modo Estricto:** `session.use_strict_mode = 1` para rechazar IDs de sesión no originados por el servidor.

---

## 5. Trazabilidad con el Módulo Macro Hospitalario

Los componentes de alta concurrencia diseñados en la arquitectura global (Worker Asíncrono de Outbox `s3ih-worker` y máquina de estados finita de solicitudes `sol_solicitudes`) se integrarán sobre esta misma base de servicios y repositorios en la siguiente fase de desarrollo.
