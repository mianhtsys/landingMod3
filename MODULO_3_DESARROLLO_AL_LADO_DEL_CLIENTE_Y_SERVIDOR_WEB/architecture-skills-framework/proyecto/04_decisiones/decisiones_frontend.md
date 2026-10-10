# Decisiones de Frontend e Interfaz Web — Sistema Hospitalario S3IH

**Estado:** APROBADO  
**Fecha:** 2026-10-07 (Actualizado y armonizado: 2026-10-08)  
**Dominio:** Interfaz de Usuario para S3IH (Fase Didáctica y Visión Macro)  

---

## 1. Stack Tecnológico de Presentación

- **Renderizado Base:** PHP Views con Layouts compartidos (`View::render`), asegurando renderizado veloz del cascarón (SSR).
- **Estructura y Estilos:** HTML5 semántico + CSS3 estructurado en capas modulares (`variables.css`, `layout.css`, `components.css`, `forms.css`, `responsive.css`).
- **Interactividad Dinámica:** JavaScript nativo modular (ES6+) con módulos dedicados (`login.js`, `dashboard.js`, `categorias.js`, `productos.js`), consumiendo la API REST mediante la API nativa `fetch()`.
- **Restricciones Arquitectónicas:** Sin frameworks SPA (sin React, Vue ni Angular), sin empaquetadores externos (Vite/Webpack) ni dependencias CSS (sin Bootstrap ni Tailwind).

---

## 2. Separación Estricta de Responsabilidades (Cero SQL en Vistas)

- Queda terminantemente prohibido ejecutar sentencias SQL o instanciar objetos PDO dentro de las vistas (`app/Views/`).
- La capa de presentación se alimenta exclusivamente de los datos provistos por los controladores o invocando asíncronamente los endpoints de la API REST JSON.
- Todo dato dinámico inyectado en plantillas HTML se escapa contextualmente mediante `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` para mitigar ataques XSS.

---

## 3. Mapa de Pantallas Implementadas (`proyecto/06_codigo`)

### A. `/login` — Acceso Institucional Unificado
- Formulario de autenticación con campos obligatorios (`email`, `password`).
- Redirección automática si el usuario ya posee una sesión válida en el servidor.
- Manejo de feedback accesible (`role="alert"` para errores y `role="status"` para confirmaciones).

### B. `/dashboard` — Centro de Guardia Hospitalaria
- Shell institucional compuesto por header, barra lateral (`sidebar`) de navegación y pie de página.
- Visualización de la identidad del usuario activo en sesión (ej. *"Dra. Elena Ramos - Guardia UCI H1"*).
- Botón de cierre de sesión seguro (`#logout-btn`) que revoca la sesión en el servidor y redirige a login.

### C. `/categorias` — Gestión de Categorías de Suministros
- Tabla responsiva de insumos hospitalarios tipificados, mostrando ID, nombre, categoría padre y badge de estado (`activo`/`inactivo`).
- Formulario reactivo para alta y edición inline/modal.
- Selector de jerarquía (`#cat-padre`) que filtra dinámicamente categorías activas e impide la auto-asignación (regla anti-cíclica).
- Botón de inactivación lógica con confirmación (`estado = 'inactivo'`).

### D. `/productos` — Catálogo de Medicamentos y Equipamiento Médico
- Listado tabulado de suministros con visualización de SKU institucional, nombre, categoría vinculada, unidad de medida y estado.
- Formulario de registro y edición con validación de campos obligatorios.
- Selector dinámico de categorías alimentado vía API.
- Inactivación lógica segura cumpliendo RN-012 (Zero-Physical-Delete).

---

## 4. Pantallas del Módulo Macro Hospitalario (Fase Posterior S3IH)

En la expansión del sistema completo se incorporarán:
- `/solicitudes`: Bandeja priorizada con semáforos de SLA y filtrado por guardia/urgencia vital.
- `/solicitudes/radicar`: Formulario de radicación con modo expedito para quirófanos y emergencias de soporte vital.
- `/solicitudes/{id}`: Detalle de orden de trabajo con línea de tiempo inmutable y bitácora técnica.

---

## 5. Accesibilidad y Diseño Responsivo

- Cumplimiento de pautas **WCAG 2.1 Nivel AA**: contrastes de color óptimos ($\ge 4.5:1$), navegación accesible por teclado, enlaces de salto (`skip-link`), atributos ARIA (`aria-current="page"`, `aria-labelledby`, `role="alert"`).
- Diseño adaptativo fluido verificado en resoluciones móviles ($360\text{ px}$, $480\text{ px}$), tablet ($768\text{ px}$) y escritorio ($1280\text{ px}$, $1440\text{ px}$) sin scroll horizontal no deseado.
