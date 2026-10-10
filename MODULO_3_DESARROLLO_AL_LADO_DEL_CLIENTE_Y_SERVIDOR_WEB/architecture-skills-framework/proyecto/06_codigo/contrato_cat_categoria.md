# Contrato API REST — Catálogo de Categorías Hospitalarias (`cat_categoria`)
## Módulo Piloto de la Red Hospitalaria S3IH

**Checkpoint:** `CP-API-01`  
**Estado:** `DISEÑADO - ESPERANDO REVISIÓN HUMANA` (`HUMAN_STATUS: PENDING`)  
**Fecha:** 2026-10-07  
**Recurso URI Base:** `/api/v1/categorias`  
**Protocolo:** REST / JSON (`Content-Type: application/json; charset=UTF-8`)  
**Dominio:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH - Red de 4 Hospitales)  
**Trazabilidad:** RF-001, RF-020, RNF-010, RNF-020, RN-012, `decisiones_api.md`, `decisiones_desarrollo_incremental.md`

---

## 1. Contexto y Alcance del Módulo Piloto

Dentro del marco didáctico y el alcance congelado (`AGENTS.md`), la entidad `cat_categoria` opera como el módulo piloto oficial para validar la arquitectura base (Bootstrap PHP 8.2+, PDO, Router REST, controladores, servicios y repositorios sin frameworks externos).

En el ecosistema hospitalario **S3IH**, `cat_categoria` gestiona la taxonomía jerárquica de bienes, áreas técnicas y servicios institucionales de soporte hospitalario:
- **Biomédica y Soporte Vital** (Monitores, ventiladores, desfibriladores).
- **Infraestructura y Redes de Gases** (Oxígeno clínico, climatización estéril, plantas eléctricas).
- **Tecnologías de la Información (TI) Hospitalaria** (Red LAN asistencial, terminales HIS, impresoras térmicas de brazaletes).
- **Mobiliario Clínico y Camas Asistenciales** (Camillas de urgencia, camas UCI).

---

## 2. Convenciones Globales y Formato de Carga Útil

### 2.1. Cabeceras HTTP Requeridas
| Cabecera | Tipo | Requerida | Propósito / Descripción |
| :--- | :---: | :---: | :--- |
| `Accept` | String | Sí | Debe ser `application/json`. |
| `Content-Type` | String | Sí (en POST/PUT) | Debe ser `application/json; charset=UTF-8`. |
| `X-Correlation-ID` | UUID / String | Opcional (Cliente) / Obligatorio (Servidor) | Identificador único de correlación transversal para observabilidad clínica y trazabilidad. Si el cliente no lo envía, el servidor lo genera. |

### 2.2. Estructura Estándar de Respuestas (Envelope JSON)

**Respuesta Exitosa (2xx):**
```json
{
  "success": true,
  "data": { ... } | [ ... ],
  "message": "Operación ejecutada con éxito",
  "correlation_id": "c1f7a28e-8a8b-4b15-9c87-9b2f6ef1b234"
}
```

**Respuesta de Error (4xx / 5xx):**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR | NOT_FOUND | CONFLICT | DATABASE_ERROR | INTERNAL_ERROR",
    "message": "Descripción legible para el usuario o integrador",
    "details": [
      {
        "field": "nombre",
        "issue": "El campo 'nombre' es obligatorio y debe tener entre 3 y 100 caracteres."
      }
    ]
  },
  "correlation_id": "c1f7a28e-8a8b-4b15-9c87-9b2f6ef1b234"
}
```

---

## 3. Especificación de Endpoints

### 3.1. GET `/api/v1/categorias`
Recupera el listado de categorías hospitalarias registradas.

- **Parámetros Query (Opcionales):**
  - `estado`: Filtrar por `'activo'` o `'inactivo'`. Si se omite, retorna la totalidad de registros.
- **Códigos de Respuesta:**
  - `200 OK`: Colección retornada con éxito (array en `data`, puede ser vacío `[]`).
  - `500 Internal Server Error`: Falla de conectividad o error interno sanitizado.
- **Ejemplo Respuesta (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "nombre": "Equipamiento Biomédico y Soporte Vital",
      "descripcion": "Dispositivos médicos y electromedicina de monitorización y terapia",
      "categoria_padre_id": null,
      "estado": "activo",
      "created_at": "2026-10-07 18:00:00",
      "updated_at": "2026-10-07 18:00:00"
    },
    {
      "id": 2,
      "nombre": "Ventiladores Mecánicos y Oxigenoterapia",
      "descripcion": "Subcategoría para equipos de respiración asistida",
      "categoria_padre_id": 1,
      "estado": "activo",
      "created_at": "2026-10-07 18:05:00",
      "updated_at": "2026-10-07 18:05:00"
    }
  ],
  "message": "Categorías consultadas exitosamente",
  "correlation_id": "7fa844be-c941-4c12-a7d5-89680327f12e"
}
```

---

### 3.2. GET `/api/v1/categorias/{id}`
Obtiene el detalle de una categoría específica por su identificador primario.

- **Parámetros Path:**
  - `id`: Entero positivo (`BIGINT UNSIGNED`).
- **Validaciones:**
  - Si `id` no es un número entero positivo $\rightarrow$ `400 Bad Request`.
- **Códigos de Respuesta:**
  - `200 OK`: Objeto encontrado.
  - `400 Bad Request`: ID con formato inválido.
  - `404 Not Found`: No existe ninguna categoría con ese ID.
  - `500 Internal Server Error`: Error de persistencia.

---

### 3.3. POST `/api/v1/categorias`
Crea una nueva categoría en el catálogo hospitalario.

- **Cuerpo de la Petición (Request Body JSON):**
```json
{
  "nombre": "Infraestructura y Redes de Oxígeno",
  "descripcion": "Sistemas centrales y tomas de pared de gases medicinales",
  "categoria_padre_id": null
}
```
- **Reglas de Validación Estricta:**
  - `nombre`: Obligatorio, tipo string, longitud mínima de 3 caracteres, máxima de 100 caracteres. Sanitizado contra XSS.
  - `descripcion`: Opcional, tipo string o null, máximo 255 caracteres.
  - `categoria_padre_id`: Opcional, entero positivo o `null`. Si se suministra, el registro referenciado debe existir en la base de datos y tener `estado = 'activo'`.
  - **Inyección de Campos:** Los campos `id`, `estado`, `created_at`, `updated_at`, `user_create`, etc., son ignorados si se envían en el body. El estado inicial es siempre `'activo'`.
- **Códigos de Respuesta:**
  - `201 Created`: Categoría creada exitosamente. Cabecera `Location: /api/v1/categorias/{id}`.
  - `400 Bad Request`: Error de validación (nombre faltante, tipos incorrectos, etc.).
  - `404 Not Found`: La `categoria_padre_id` referenciada no existe.
  - `500 Internal Server Error`: Falla interna en base de datos.

---

### 3.4. PUT `/api/v1/categorias/{id}`
Actualización completa de una categoría hospitalaria existente.

- **Parámetros Path:**
  - `id`: Entero positivo (`BIGINT UNSIGNED`).
- **Cuerpo de la Petición (Request Body JSON):**
```json
{
  "nombre": "Equipos Biomédicos y Diagnóstico Clínico",
  "descripcion": "Actualización de descripción de equipamiento hospitalario",
  "categoria_padre_id": null
}
```
- **Reglas de Validación:**
  - Mismas validaciones de contenido que en POST.
  - **Validación Anticíclica:** `categoria_padre_id` no puede ser igual al propio `id` de la categoría (evita referencias circulares directas).
  - El campo `estado` **NO** se muta vía PUT (las transiciones de ciclo de vida se gestionan vía inactivación lógica).
- **Códigos de Respuesta:**
  - `200 OK`: Registro actualizado.
  - `400 Bad Request`: ID inválido o validación fallida (ej: autoreferencia).
  - `404 Not Found`: La categoría especificada o el padre referenciado no existen.
  - `500 Internal Server Error`: Falla en base de datos.

---

### 3.5. DELETE `/api/v1/categorias/{id}`
**Inactivación Lógica Hospitalaria (Soft-Delete)**.

- **Regla Fundamental de Gobernanza Hospitalaria (RN-012, Restricción Técnica 5):**
  - **BAJO NINGUNA CIRCUNSTANCIA** se ejecuta una sentencia `DELETE FROM cat_categoria`.
  - La operación ejecuta un `UPDATE cat_categoria SET estado = 'inactivo', updated_at = NOW() WHERE id = :id`.
- **Parámetros Path:**
  - `id`: Entero positivo (`BIGINT UNSIGNED`).
- **Idempotencia:**
  - Si la categoría existe y ya se encuentra `'inactivo'`, la operación retorna `200 OK` informando que ya se encontraba inactiva.
- **Códigos de Respuesta:**
  - `200 OK`: Inactivación completada exitosamente.
  - `400 Bad Request`: ID inválido.
  - `404 Not Found`: Categoría inexistente.
  - `500 Internal Server Error`: Falla en base de datos.

---

## 4. Matriz de Casos de Prueba para los Siguientes Checkpoints

| Checkpoint | Endpoint / Prueba | Casos a Validar | Resultado Esperado |
| :---: | :--- | :--- | :---: |
| **CP-API-02** | `GET /api/v1/health` | Conectividad DB y servidor PHP puro | `200 OK`, JSON con status DB `UP` |
| **CP-API-03** | `GET /api/v1/categorias` | 1. Con registros existentes<br>2. Colección vacía (`data: []`)<br>3. Simulación falla DB | `200 OK`<br>`200 OK`<br>`500 Internal Error` |
| **CP-API-04** | `GET /api/v1/categorias/{id}` | 1. ID existente<br>2. ID inexistente (`id=999999`)<br>3. ID alfanumérico (`id=abc`) | `200 OK`<br>`404 Not Found`<br>`400 Bad Request` |
| **CP-API-05** | `POST /api/v1/categorias` | 1. Payload válido<br>2. Nombre ausente / vacío<br>3. Nombre > 100 caracteres<br>4. Padre inexistente | `201 Created`<br>`400 Bad Request`<br>`400 Bad Request`<br>`404 Not Found` |
| **CP-API-06** | `PUT /api/v1/categorias/{id}` | 1. Payload válido<br>2. Error de validación<br>3. Autociclo (`categoria_padre_id = id`)<br>4. ID inexistente | `200 OK`<br>`400 Bad Request`<br>`400 Bad Request`<br>`404 Not Found` |
| **CP-API-07** | `DELETE /api/v1/categorias/{id}` | 1. Inactivación exitosa (`estado='inactivo'`)<br>2. ID inexistente<br>3. Re-inactivación (idempotencia) | `200 OK`<br>`404 Not Found`<br>`200 OK` |
| **CP-API-08** | OpenAPI & Test Suite | 1. Documento OpenAPI 3.1 completo<br>2. Ejecución automatizada de suite CRUD | `PASS` en 100% de tests |

---

## 5. Declaración de Cumplimiento de Reglas Universales

- [x] **No Programar:** En cumplimiento estricto de la directriz `CP-API-01: Diseñar contrato de categorías. No programar. STOP`, no se ha generado ni modificado código de la aplicación en este turno.
- [x] **Inactivación Lógica Obligatoria:** Contrato formalmente diseñado para `estado='inactivo'`, sin eliminación física.
- [x] **Trazabilidad de Requisitos:** Alineado con los requerimientos hospitalarios de taxonomía y estándares REST de `decisiones_api.md`.
