# Decisiones de Alcance — Sistema Hospitalario S3IH

**Estado:** APROBADO  
**Fecha:** 2026-10-07 (Actualizado y armonizado: 2026-10-08)  
**Dominio:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de hasta 4 hospitales)  

---

## 1. Visión Macro y Alcance Funcional del Sistema (S3IH Completo)

En su concepción arquitectónica global (Fases 1 a 3: `00_contexto` a `05_base_datos`), S3IH abarca la gestión integral de requerimientos entre servicios asistenciales (Urgencias, Quirófanos, UCI) y unidades resolutoras técnicas (Biomédica, Mantenimiento, Farmacia Interna):
1. **Autenticación y Sesión:** Directorio Activo/LDAP y sesiones seguras en servidor con timeout estricto de 30 minutos (RNF-030).
2. **Catálogos Maestros:** Sedes hospitalarias (hasta 4 hospitales), servicios asistenciales/resolutores y tipificación de solicitudes (`cat_sede`, `cat_servicio`, `cat_solicitud_tipo`).
3. **Módulo Central de Solicitudes:** Radicación ordinaria y expedita (`H1-2026-XXXXXX`), control de concurrencia optimista (`version`), máquina de estados determinista (RN-005) y adjuntos técnicos ($\le 5\text{ MB}$).
4. **Motor de SLA y Worker Asíncrono:** Patrón Transactional Outbox para alertas preventivas al 75% y escalamientos automáticos.
5. **Auditoría e Indicadores:** Pista inmutable append-only y métricas MTTA/MTTR.

---

## 2. Alcance Didáctico Reducido de Implementación (Fase de Desarrollo Módulo 3)

Conforme a la directriz docente congelada en `AGENTS.md` (2026-09-29) y el Architecture Decision Record **ADR-005**, la implementación física en código fuente ([proyecto/06_codigo/](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/06_codigo/)) se acota a un **subsistema piloto representativo** que demuestra el ciclo completo de arquitectura limpia en PHP 8.3 puro y Vanilla JS:

### Componentes en Código (`proyecto/06_codigo/`)
1. **Módulo de Autenticación (`auth_usuario`):**
   - Rutas API: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.
   - Seguridad: Password hashing con `Argon2id`, cookie segura `s3ih_session` (`HttpOnly`, `SameSite=Lax`, expiración 1800s).
2. **Módulo Piloto de Categorías (`cat_categoria`):**
   - API REST completa (CRUD con borrado lógico).
   - Soporte de estructura jerárquica con `categoria_padre_id` y validación anti-cíclica (una categoría no puede ser su propio padre).
3. **Segundo Módulo Transaccional (`cat_producto`):**
   - Catálogo de insumos hospitalarios, medicamentos y equipamiento biomédico vinculado a categorías activas.
   - Reglas de negocio: integridad referencial obligatoria (`categoria_id`), unicidad de código institucional (`sku`), tipificación de unidad de medida.
4. **Pantallas Web Implementadas:**
   - `/login`: Formulario de acceso institucional con validaciones accesibles.
   - `/dashboard`: Panel de bienvenida a guardias clínicas, branding hospitalario S3IH y control de salida.
   - `/categorias`: Gestión interactiva de categorías jerárquicas con modal/formulario y renderizado por API.
   - `/productos`: Catálogo hospitalario de suministros con filtros, asignación de categorías e inactivación lógica.
5. **Landing Comercial Pública ([proyecto/07_landing/](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/07_landing/)):**
   - Vitrina pública complementaria para Bolivia ("Altiplano Marketplace") sin login ni pagos, con 7 categorías y 70 productos con precios en Bs.

---

## 3. Exclusiones Explícitas (Fuera de Alcance de la Fase Actual)

- **No es Historia Clínica Electrónica (EHR/HIS):** Prohibición absoluta de registrar datos clínicos de pacientes (Zero-PHI).
- **No citas médicas externas, triaje ambulatorio ni facturación a aseguradoras.**
- **Módulos hospitalarios postergados a fases posteriores:**
  - Radicación de órdenes de trabajo completas (`sol_solicitudes`).
  - Worker en segundo plano de SLAs Outbox (`s3ih-worker`).
  - Tablas de cuadrillas y turnos de guardia (`org_cuadrillas_guardia`).

---

## 4. Política de Eliminación e Inmutabilidad (RN-012 Zero-Physical-Delete)

- Queda terminantemente prohibido el borrado físico (`DELETE FROM`) en todas las entidades del sistema.
- Categorías y productos aplican exclusivamente **inactivación lógica**: `UPDATE ... SET estado = 'inactivo'`.
- La idempotencia está garantizada: llamados repetidos de desactivación responden `200 OK` manteniendo el registro físicamente intacto en la base de datos MySQL.

---

## 5. Regla de Gobernanza y Desarrollo Incremental

- Un checkpoint por interacción.
- Pruebas y verificación automatizada obligatoria por etapa.
- Marcado de `HUMAN_STATUS: PENDING` al finalizar cada paso.
- Detención estricta hasta recibir validación explícita del usuario (`continúa`).
