# Desarrollo Incremental y Validación Humana — Sistema Hospitalario S3IH

**Estado:** APROBADO  
**Fecha:** 2026-10-07  
**Dominio:** Flujo de Ejecución Incremental para S3IH  

---

## 1. Regla Obligatoria de Checkpoint

Para garantizar control de calidad, trazabilidad y gobierno técnico:
1. Ejecutar **un solo checkpoint** por interacción.
2. Probar y verificar técnicamente el artefacto o componente generado.
3. Actualizar el archivo de estado correspondiente en `.agents/state/`.
4. Marcar obligatoriamente `HUMAN_STATUS: PENDING`.
5. Detener la ejecución de inmediato y esperar confirmación explícita del usuario (`continúa` o feedback).

---

## 2. Definición de Estados de Checkpoint

- **`TECHNICAL_STATUS`:**
  - `PENDING`: En proceso de ejecución.
  - `PASSED`: Pruebas y validaciones técnicas aprobadas sin errores.
  - `FAILED`: Falla en pruebas unitarias o de integración.
  - `BLOCKED`: Bloqueado por dependencia previa no satisfecha.
- **`HUMAN_STATUS`:**
  - `PENDING`: Esperando revisión y autorización humana.
  - `APPROVED`: Aprobado explícitamente por el usuario para continuar al siguiente paso.
  - `CHANGES_REQUIRED`: Correcciones solicitadas antes de avanzar.

---

## 3. Secuencia de Fases y Alcance

1. **Fase 3 — Base de Datos (`.agents/workflows/02_database_workflow.md`):**
   - Pasos 1 al 20: Modelo conceptual, lógico, físico, concurrencia, índices, migraciones y dictamen formal del DBA (`STATUS: APPROVED`).
2. **Fase 4 — API Piloto (`.agents/workflows/03_api_pilot_workflow.md`):**
   - Endpoints de autenticación y catálogo tipificado de servicios resolutores.
3. **Fase 5 — Backend Transaccional (`.agents/workflows/04_backend_workflow.md`):**
   - Servicios de radicación con código correlativo (`H1-2026-XXXXXX`), máquina de estados determinista (RN-005), control de concurrencia optimista (`version`) y worker de SLA Outbox.
4. **Fase 6 — Frontend Responsivo (`.agents/workflows/05_frontend_workflow.md`):**
   - Vistas web accesibles (`/login`, `/dashboard`, `/solicitudes`, `/solicitudes/radicar`, `/solicitudes/{id}`).
5. **Fase 7 — Integración y Verificación Integral (`.agents/workflows/06_integration_workflow.md`):**
   - Prueba punta a punta de ciclo de vida completo y pista de auditoría inmutable.
