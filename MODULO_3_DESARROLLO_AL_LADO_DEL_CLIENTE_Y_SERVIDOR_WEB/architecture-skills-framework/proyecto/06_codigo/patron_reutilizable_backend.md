# Patrón Arquitectónico Reutilizable de Backend — S3IH

**Checkpoint:** `CP-BACK-01`  
**Estado:** `CONSOLIDADO Y VERIFICADO`  
**Fecha:** 2026-10-07  
**Dominio:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH)  
**Trazabilidad:** `04_backend_workflow.md`, `decisiones_backend.md`, `decisiones_api.md`, RF-001, RN-012

---

## 1. Justificación y Propósito

El módulo piloto `cat_categoria` validó con éxito la arquitectura desacoplada en 5 capas para PHP 8.2+ puro sin dependencias de frameworks externos. En este checkpoint (`CP-BACK-01`), dicho patrón se consolida y formaliza como la plantilla obligatoria para los módulos subsecuentes de este workflow:
1. **Módulo de Identidad y Sesión (`auth_usuario`):** Login, Logout, Me.
2. **Módulo Transaccional de Bienes/Productos (`cat_producto`):** Insumos, activos hospitalarios y equipamiento clínico.

---

## 2. Estructura Canónica de las 5 Capas

```
HTTP Request / Router
        │
        ▼
1. Controller (App\Controllers\Api\*Controller)
   - Extrae Request: body(), param('id'), query().
   - Delega a Validator y Service.
   - Envuelve en Response::execute(fn() => ..., $statusCode).
        │
        ▼
2. Validator (App\Validators\*Validator)
   - Valida tipos, límites, obligatoriedad y formataje.
   - Aplica política Fail-Fast (RuntimeException con HTTP 400).
        │
        ▼
3. Service (App\Services\*Service)
   - Reglas de negocio de dominio asistencial (unicidad, relaciones, transiciones).
   - Valida existencia previa de claves foráneas.
   - Lanza RuntimeException con código semántico (400, 404, 409).
        │
        ▼
4. Repository (App\Repositories\*Repository)
   - Sentencias preparadas nativas PDO (Database::conn()).
   - Mapeo de filas hacia objetos de dominio mediante Model::fromRow().
   - Inmutabilidad estricta: operaciones de borrado implementan UPDATE estado='inactivo'.
   - Prohibido DELETE FROM (RN-012).
        │
        ▼
5. Model / Entity (App\Models\*)
   - Clases inmutables con propiedades readonly.
   - Método fromRow(array $row): self.
   - Método toArray(): array (serialización limpia sin filtrar datos de auditoría interna).
```

---

## 3. Manejador Centralizado de Excepciones y Respuestas (`Response::execute`)

Para evitar bloques `try-catch` repetitivos y fugas de información interna en cada controlador, se consolida el ejecutor estandarizado en `App\Core\Response`:

```php
public static function execute(callable $action, int $status): never
{
    try {
        $data = $action();
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        self::fail(500, 'Error de base de datos'); // Sanitizado, sin stack trace
    } catch (RuntimeException $e) {
        $code = (int) $e->getCode();
        self::fail($code >= 400 ? $code : 400, $e->getMessage());
    } catch (Throwable $e) {
        error_log("Internal Error: " . $e->getMessage());
        self::fail(500, 'Error interno del servidor');
    }
    self::json($status, ['success' => true, 'data' => $data]);
}
```

### Tabla de Traducción de Excepciones a Respuestas HTTP:
| Tipo de Excepción | Causa Raíz | Código HTTP Resultante | Formato de Respuesta JSON |
| :--- | :--- | :---: | :--- |
| `RuntimeException(..., 400)` | Error de validación de campos / regla anticíclica | `400 Bad Request` | `{"success": false, "error": "<mensaje>"}` |
| `RuntimeException(..., 404)` | Recurso o clave foránea no encontrada | `404 Not Found` | `{"success": false, "error": "<mensaje>"}` |
| `RuntimeException(..., 401)` | Credenciales inválidas o sesión no activa | `401 Unauthorized` | `{"success": false, "error": "<mensaje>"}` |
| `PDOException` | Falla de conexión o constraint de base de datos | `500 Internal Error` | `{"success": false, "error": "Error de base de datos"}` |
| `Throwable` | Error imprevisto de ejecución PHP | `500 Internal Error` | `{"success": false, "error": "Error interno del servidor"}` |

---

## 4. Guía de Replicación para los Siguientes Checkpoints

| Módulo Objetivo | Checkpoints | Clases a Implementar / Replicar según el Patrón |
| :--- | :--- | :--- |
| **Auth / Usuario** | CP-BACK-02, 03, 04 | `Usuario`, `UsuarioRepository`, `UsuarioValidator`, `AuthService`, `AuthController` |
| **Productos Hospitalarios** | CP-BACK-05 a 08 | `Producto`, `ProductoRepository`, `ProductoValidator`, `ProductoService`, `ProductoController` |

---

## 5. Criterio de Aceptación del Checkpoint

El patrón queda consolidado al comprobar que:
1. `Response::execute()` unifica y aísla completamente los controladores de la gestión de errores de bajo nivel.
2. `cat_categoria` sirve como referencia verificada y libre de regresiones.
3. El entorno está listo para crear el usuario demo (`tools/create_demo_user.php`) en `CP-BACK-02`.
