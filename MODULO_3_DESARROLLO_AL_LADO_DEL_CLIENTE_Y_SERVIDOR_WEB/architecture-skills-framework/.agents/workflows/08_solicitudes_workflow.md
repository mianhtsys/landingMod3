# Workflow 08 — Módulo Central de Solicitudes Hospitalarias (S3IH)

Flujo de desarrollo incremental para la expansión transaccional del Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH) en una red de 4 hospitales.

Regla obligatoria: Ejecutar EXACTAMENTE UN checkpoint por interacción. Probar físicamente, actualizar el archivo `.agents/state/solicitudes-workflow.json`, marcar `HUMAN_STATUS: PENDING`, detenerse y esperar confirmación explícita (`continúa`).

---

## CP-SOL-01 — Migración Física y Semillas Hospitalarias
- Crear las tablas hospitalarias en `s3ih_db`: `org_sedes`, `org_servicios`, `cat_solicitud_tipos`, `sla_calendarios`, `sla_politicas`, `sol_solicitudes`, `sol_historial_estados`, `ops_outbox_eventos`.
- Cargar semillas maestras: 4 sedes de la red hospitalaria (`H1`, `H2`, `H3`, `H4`), servicios clínicos y resolutores, catálogo de solicitudes tipificadas y políticas de SLA (MTTA/MTTR).
- Probar con suite automatizada `tests/test_cp_sol_01.php`.
- STOP.

## CP-SOL-02 — Modelo de Dominio y Generador de Correlativos Inmutables
- Implementar entidades, repositorios y servicio generador de código correlativo `H{sede}-{año}-{secuencial}` (ej. `H1-2026-000001`, RN-001) garantizando atomicidad y unicidad ante concurrencia.
- Probar con suite automatizada `tests/test_cp_sol_02.php`.
- STOP.

## CP-SOL-03 — API de Radicación Dual: Estándar y Expedita de Soporte Vital
- Implementar `POST /api/v1/solicitudes` (ordinaria) y `POST /api/v1/solicitudes/expedita` (soporte vital en quirófanos/UCI).
- Validación de datos Zero-PHI, idempotencia por cabecera `Idempotency-Key` (UUID) e inserción en Outbox.
- Probar con suite automatizada `tests/test_cp_sol_03.php`.
- STOP.

## CP-SOL-04 — Máquina de Estados Finita y Concurrencia Optimista
- Implementar transiciones deterministas de estado (`Registrada` → `Asignada` → `En Proceso` → `En Espera` → `Atendida` → `Cerrada` / `Rechazada`, RN-005).
- Control de concurrencia optimista mediante columna `version` contra *lost updates* concurrentes (D-01).
- Registro inmutable en `sol_historial_estados`.
- Probar con suite automatizada `tests/test_cp_sol_04.php`.
- STOP.

## CP-SOL-05 — Interfaz Web Asistencial: Bandeja y Radicación
- Implementar vistas `/solicitudes` (bandeja de trabajo con semáforos de SLA y prioridad vital) y `/solicitudes/radicar` (formulario estándar y modo expedito de soporte vital).
- Consumo por API Fetch, cero SQL en capas de vista.
- Probar con suite automatizada `tests/test_cp_sol_05.php`.
- STOP.

## CP-SOL-06 — Interfaz Web Técnica: Detalle de Orden y Transiciones
- Implementar vista `/solicitudes/{id}` con línea de tiempo inmutable (*Audit Trail*), visualización de estado de SLA y botonera contextual de transiciones de guardia.
- Probar con suite automatizada `tests/test_cp_sol_06.php`.
- STOP.

## CP-SOL-07 — Motor de Reloj de SLA y Worker Asíncrono Outbox
- Implementar script CLI `bin/s3ih-worker.php` con consumo de `ops_outbox_eventos` (`FOR UPDATE SKIP LOCKED`), alerta preventiva al 75% y escalamiento jerárquico al 100%.
- Probar con suite automatizada `tests/test_cp_sol_07.php`.
- STOP.

## CP-SOL-08 — Integración E2E y Cierre del Módulo Hospitalario
- Suite consolidada punta a punta de ciclo de vida completo de solicitudes hospitalarias.
- `TECHNICAL_STATUS: PASSED`, `HUMAN_STATUS: PENDING`.
- STOP.
