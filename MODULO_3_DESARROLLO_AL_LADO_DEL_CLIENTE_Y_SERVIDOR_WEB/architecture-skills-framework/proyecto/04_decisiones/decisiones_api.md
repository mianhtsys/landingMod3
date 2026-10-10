# Decisiones de API REST — Sistema Hospitalario S3IH

**Estado:** APROBADO  
**Fecha:** 2026-10-07 (Actualizado y armonizado: 2026-10-08)  
**Dominio:** API REST para S3IH (Fase Didáctica y Visión Macro)  

---

## 1. Convenciones Generales de la API

- **Stack:** PHP 8.3 puro, PDO nativo, sin frameworks externos.
- **Protocolo:** REST sobre HTTP/HTTPS.
- **Formato de Carga Útil:** JSON estricto (`Content-Type: application/json; charset=UTF-8`).
- **Prefijo Global de Versión:** `/api/v1/`.
- **Manejo de Errores Estandarizado:** Formato homogéneo `{"success": false, "error": "mensaje"}`.
- **Contrato Formal:** Especificación OpenAPI 3.1 servida en `/api/v1/openapi.yaml`.

---

## 2. Endpoints Implementados y Activos (Alcance Didáctico `proyecto/06_codigo`)

### A. Diagnóstico y Especificación
- `GET /api/v1/health`: Healthcheck del servicio y verificación de conectividad activa a MySQL (`Database::ping()`).
- `GET /api/v1/openapi.yaml`: Contrato interactivo OpenAPI 3.1 del API de S3IH.

### B. Autenticación y Sesión (`auth_usuario`)
- `POST /api/v1/auth/login`: Autenticación con email institucional y verificación con hash `Argon2id`; regenera ID de sesión (`session_regenerate_id(true)`) y emite cookie `s3ih_session`.
- `POST /api/v1/auth/logout`: Destrucción completa de la sesión en servidor, expiración forzada de cookie e invalidación inmediata (operación idempotente).
- `GET /api/v1/auth/me`: Retorna la identidad del personal clínico autenticado (`id`, `nombre`, `email`) excluyendo estrictamente `hash_password`.

### C. Módulo Piloto de Categorías (`cat_categoria`)
- `GET /api/v1/categorias`: Listado completo de categorías de suministros hospitalarios con jerarquías y estados.
- `POST /api/v1/categorias`: Creación de categoría (raíz o subcategoría con `categoria_padre_id`), responde `201 Created`.
- `GET /api/v1/categorias/{id}`: Detalle individual de la categoría.
- `PUT /api/v1/categorias/{id}`: Actualización de nombre y padre con control anti-cíclico (rechazo `400` ante auto-padre).
- `DELETE /api/v1/categorias/{id}`: Inactivación lógica (RN-012, `estado = 'inactivo'`); responde `200 OK` e idempotente.

### D. Módulo de Insumos y Equipos Hospitalarios (`cat_producto`)
- `GET /api/v1/productos`: Listado general de productos y equipos médicos (requiere sesión activa).
- `POST /api/v1/productos`: Registro de producto vinculado a `categoria_id` existente (404 si no existe) y control de SKU único (409 Conflict ante duplicados).
- `GET /api/v1/productos/{id}`: Consulta por ID de producto.
- `PUT /api/v1/productos/{id}`: Edición de nombre, unidad o reasignación de categoría con verificación de no colisión de SKU.
- `DELETE /api/v1/productos/{id}`: Inactivación lógica (RN-012, `estado = 'inactivo'`); responde `200 OK` e idempotente.

---

## 3. Endpoints del Diseño Macro Hospitalario (Fase Posterior S3IH)

Los siguientes endpoints forman parte del diseño arquitectónico global para la gestión completa de solicitudes y SLA outbox:
- `GET /api/v1/sedes`: Catálogo de sedes hospitalarias.
- `GET /api/v1/servicios`: Servicios clínicos y resolutores por sede.
- `GET /api/v1/solicitudes`: Bandeja con filtrado por urgencia vital.
- `POST /api/v1/solicitudes`: Radicación ordinaria con correlativo `H1-2026-XXXXXX` y token de idempotencia.
- `POST /api/v1/solicitudes/{id}/transicion`: Transición determinista de estados (Registrada $\rightarrow$ Asignada $\rightarrow$ En Proceso $\rightarrow$ Atendida).
- `GET /api/v1/indicadores/sla`: Métricas MTTA/MTTR de cumplimiento de tiempos de guardia.

---

## 4. Códigos de Estado y Manejo de Errores

- `200 OK`: Operación exitosa (lectura, actualización o inactivación lógica).
- `201 Created`: Recurso creado exitosamente.
- `400 Bad Request`: Error de validación de campos, IDs malformados o regla de negocio violada (ej. auto-padre).
- `401 Unauthorized`: Acceso no autenticado a rutas protegidas.
- `404 Not Found`: Recurso o clave foránea inexistente.
- `409 Conflict`: Conflicto de unicidad (ej. SKU institucional duplicado).
- `500 Internal Server Error`: Errores PDO o excepciones internas sanitizadas sin fugar credenciales ni queries al cliente.
