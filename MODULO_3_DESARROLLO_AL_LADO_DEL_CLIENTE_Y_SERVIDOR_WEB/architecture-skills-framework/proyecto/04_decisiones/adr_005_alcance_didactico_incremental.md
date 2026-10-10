# ADR-005: Adopción de Alcance Didáctico Reducido para la Implementación en PHP Puro (Módulo 3)

**Estado:** APROBADO  
**Fecha:** 2026-09-29 (Formalizado y Armonizado: 2026-10-08)  
**Decisores:** Equipo de Arquitectura y Liderazgo Técnico  
**Trazabilidad:** [AGENTS.md](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/AGENTS.md), [decisiones_alcance.md](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/04_decisiones/decisiones_alcance.md), [03_api_pilot_workflow.md](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/.agents/workflows/03_api_pilot_workflow.md)  

---

## 1. Contexto y Problemática

En las Fases 1 a 3 del proyecto (*Requisitos, Arquitectura y Base de Datos*), se realizó el diseño integral del **Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH)** para una red de hasta 4 hospitales, abarcando un modelo de datos de 22 tablas relacionales, control de concurrencia optimista, reloj de vencimiento de SLA, tubería de adjuntos y un worker asíncrono con patrón Transactional Outbox.

Para la fase de desarrollo e implementación física de la aplicación (*Módulo 3: Desarrollo al Lado del Cliente y Servidor Web*), la restricción tecnológica y pedagógica exige:
- Construcción en **PHP puro (sin frameworks como Laravel o Symfony)**.
- Arquitectura **MVC desacoplada** con enrutamiento manual y PDO nativo.
- Frontend en **HTML5, CSS moderno y JavaScript Vanilla (sin React, Vue o librerías externas)**.
- Ejecución incremental gobernada por checkpoints con suites de pruebas automatizadas y validación humana obligatoria.

Implementar las 22 tablas y toda la máquina de estados en una única iteración de desarrollo representaría un riesgo severo de sobrecarga cognitiva, dispersión de esfuerzos y código incompleto.

---

## 2. Decisión Tomada

Se decide formalmente **congelar el alcance de desarrollo del Módulo 3** en un subsistema piloto acotado, cohesivo y totalmente representativo del estándar arquitectónico:

1. **Módulos Seleccionados para Codificación (`proyecto/06_codigo/`):**
   - **`auth_usuario`:** Autenticación institucional con Argon2id, gestión de sesiones PHP (`s3ih_session`), control de expiración (RNF-030) y endpoints `/login`, `/logout`, `/me`.
   - **`cat_categoria` (Módulo Piloto):** Catálogo jerárquico de insumos y materiales hospitalarios con validación anti-cíclica auto-padre y API REST completa.
   - **`cat_producto` (Segundo Módulo Transaccional):** Catálogo de suministros médicos, medicamentos y equipamiento biomédico vinculado a categorías activas con control de SKU único.
2. **Políticas de Datos Preservadas:**
   - Inactivación lógica estricta (**RN-012 Zero-Physical-Delete**): `UPDATE ... SET estado = 'inactivo'`. Prohibido el uso de `DELETE FROM`.
   - Cero consultas SQL en capas de presentación Web (separación estricta MVC).
3. **Pantallas Web Implementadas:**
   - `/login`, `/dashboard`, `/categorias`, `/productos`.
4. **Landing Comercial Pública Complementaria (`proyecto/07_landing/`):**
   - Implementación de la vitrina pública para tráfico de anuncios ("Altiplano Marketplace") con 7 categorías, 70 productos y precios en Bs para el mercado boliviano, completamente desacoplada del sistema administrativo.

---

## 3. Estado de los Componentes Postergados

Los componentes macro del S3IH no codificados en esta fase permanecen formalmente diseñados y documentados en sus respectivos artefactos:
- Las tablas hospitalarias de órdenes (`sol_solicitudes`, `sol_historial_estados`, `sol_adjuntos`, `org_sedes`, `org_servicios`, `sla_politicas`, `outbox_eventos`) forman parte del diseño físico en [proyecto/05_base_datos/](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/05_base_datos/) y se desarrollarán en la siguiente fase de expansión del sistema.

---

## 4. Consecuencias y Beneficios

### Positivas
- **Trazabilidad y Coherencia:** El código fuente ([proyecto/06_codigo/](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/06_codigo/)) y la landing ([proyecto/07_landing/](file:///d:/mod3proyectoclone/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO/MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB/architecture-skills-framework/proyecto/07_landing/)) están al 100% terminados, probados y verificables sin cabos sueltos.
- **Calidad Probada:** 132 pruebas automatizadas e2e aprobadas (`132/132 tests PASSED`), abarcando seguridad, inyección SQL, XSS, CSRF, control de sesiones y CRUD completo.
- **Rigor Académico:** Se demuestra dominio pleno de MVC puro, PDO, servicios de dominio, validadores de esquemas y frontend accesible WCAG 2.1 AA.

### Negativas / Compensadas
- Las funcionalidades operativas complejas de tickets de soporte vital y outbox no están operativas en código ejecutable en esta entrega, lo cual queda explícitamente documentado como alcance de la fase posterior.
