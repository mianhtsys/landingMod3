-- ============================================================================
-- S3IH - MIGRACIÓN V007: Seguridad, Cuentas Segregadas y Revocación de DELETE
-- ============================================================================

-- 1. Creación de Roles Operativos Segregados (RNF-031)
CREATE ROLE IF NOT EXISTS 'rol_s3ih_app';
CREATE ROLE IF NOT EXISTS 'rol_s3ih_worker';
CREATE ROLE IF NOT EXISTS 'rol_s3ih_backup';

-- 2. Privilegios para la Aplicación Web (rol_s3ih_app)
-- Permisos de lectura en catálogos y organización
GRANT SELECT ON org_sedes TO 'rol_s3ih_app';
GRANT SELECT ON org_servicios TO 'rol_s3ih_app';
GRANT SELECT, INSERT, UPDATE ON org_cuadrillas_guardia TO 'rol_s3ih_app';
GRANT SELECT, INSERT, UPDATE ON org_cuadrilla_miembros TO 'rol_s3ih_app';
GRANT SELECT ON sec_roles TO 'rol_s3ih_app';
GRANT SELECT, INSERT, UPDATE ON sec_usuarios TO 'rol_s3ih_app';
GRANT SELECT ON cat_solicitud_tipos TO 'rol_s3ih_app';
GRANT SELECT ON cat_causales TO 'rol_s3ih_app';
GRANT SELECT ON sla_calendarios TO 'rol_s3ih_app';
GRANT SELECT ON sla_dias_inhabiles TO 'rol_s3ih_app';
GRANT SELECT ON sla_politicas TO 'rol_s3ih_app';

-- Permisos operativos en transaccional
GRANT SELECT, INSERT, UPDATE ON sol_solicitudes TO 'rol_s3ih_app';
GRANT SELECT, INSERT, UPDATE ON sol_control_tiempo_sla TO 'rol_s3ih_app';
GRANT SELECT, INSERT, UPDATE ON sol_informes_atencion TO 'rol_s3ih_app';
GRANT SELECT, INSERT ON sol_adjuntos TO 'rol_s3ih_app';

-- Pistas append-only: SOLO INSERT Y SELECT (prohibido UPDATE y DELETE)
GRANT SELECT, INSERT ON sol_historial_estados TO 'rol_s3ih_app';
GRANT SELECT, INSERT ON sol_reaperturas TO 'rol_s3ih_app';
GRANT SELECT, INSERT ON sol_reasignaciones TO 'rol_s3ih_app';
GRANT SELECT, INSERT ON sol_bitacora TO 'rol_s3ih_app';
GRANT SELECT, INSERT ON ops_idempotencia TO 'rol_s3ih_app';
GRANT SELECT, INSERT ON audit_eventos TO 'rol_s3ih_app';

-- Inserción de eventos en outbox
GRANT SELECT, INSERT ON ops_outbox_eventos TO 'rol_s3ih_app';

-- REVOCACIÓN GLOBAL DE BORRADO FÍSICO A LA APLICACIÓN (RN-012, RN-013)
REVOKE DELETE ON sol_solicitudes FROM 'rol_s3ih_app';
REVOKE DELETE ON sol_control_tiempo_sla FROM 'rol_s3ih_app';
REVOKE DELETE ON sol_historial_estados FROM 'rol_s3ih_app';
REVOKE DELETE ON sol_informes_atencion FROM 'rol_s3ih_app';
REVOKE DELETE ON sol_adjuntos FROM 'rol_s3ih_app';
REVOKE DELETE ON audit_eventos FROM 'rol_s3ih_app';

-- 3. Privilegios para el Worker Asíncrono (rol_s3ih_worker)
GRANT SELECT, UPDATE ON ops_outbox_eventos TO 'rol_s3ih_worker';
GRANT SELECT, UPDATE ON sol_control_tiempo_sla TO 'rol_s3ih_worker';
GRANT SELECT, UPDATE ON sol_solicitudes TO 'rol_s3ih_worker';
GRANT SELECT, INSERT ON sol_historial_estados TO 'rol_s3ih_worker';
GRANT SELECT, INSERT ON audit_eventos TO 'rol_s3ih_worker';
GRANT SELECT ON sec_usuarios TO 'rol_s3ih_worker';

-- 4. Privilegios para Respaldo y Mantenimiento (rol_s3ih_backup)
GRANT SELECT, RELOAD, PROCESS, REPLICATION CLIENT, LOCK TABLES ON *.* TO 'rol_s3ih_backup';
