-- ============================================================================
-- S3IH - MIGRACIÓN V006: Índices Secundarios de Optimización y Rendimiento
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Bandeja Asistencial Principal Multisede (RF-025, RNF-010: p95 < 200 ms)
CREATE INDEX IF NOT EXISTS idx_sol_solicitudes_bandeja 
    ON sol_solicitudes (sede_id, estado, prioridad_asistencial, fecha_radicacion_utc DESC);

-- 2. Bandeja de Órdenes del Técnico Asignado (RF-030)
CREATE INDEX IF NOT EXISTS idx_sol_solicitudes_tecnico_act 
    ON sol_solicitudes (tecnico_asignado_id, estado);

-- 3. Monitoreo de Cuadrillas y Turnos (RF-004)
CREATE INDEX IF NOT EXISTS idx_sol_solicitudes_cuadrilla 
    ON sol_solicitudes (cuadrilla_asignada_id, estado);

-- 4. Bandeja Personal del Solicitante (RF-020)
CREATE INDEX IF NOT EXISTS idx_sol_solicitudes_solicitante 
    ON sol_solicitudes (usuario_solicitante_id, id DESC);

-- 5. Barrido Periódico de Alertas y Vencimientos de SLA (RF-051, RF-052)
CREATE INDEX IF NOT EXISTS idx_sol_sla_monitoreo 
    ON sol_control_tiempo_sla (esta_pausado, fecha_compromiso_mttr_utc);

-- 6. Polling del Worker Transactional Outbox (D-06, RF-052)
CREATE INDEX IF NOT EXISTS idx_ops_outbox_polling 
    ON ops_outbox_eventos (estado, scheduled_at_utc);

-- 7. Historial y Bitácora de la Solicitud
CREATE INDEX IF NOT EXISTS idx_sol_hist_solicitud_tiempo 
    ON sol_historial_estados (solicitud_id, created_at_utc DESC);

CREATE INDEX IF NOT EXISTS idx_sol_bit_solicitud_tiempo 
    ON sol_bitacora (solicitud_id, created_at_utc DESC);

-- 8. Relación de Adjuntos por Solicitud
CREATE INDEX IF NOT EXISTS idx_sol_adj_solicitud 
    ON sol_adjuntos (solicitud_id);

-- 9. Auditoría Forense y Trazabilidad Distribuida (RF-072, RNF-052)
CREATE INDEX IF NOT EXISTS idx_audit_registro_tiempo 
    ON audit_eventos (tabla_afectada, registro_id, created_at_utc DESC);

CREATE INDEX IF NOT EXISTS idx_audit_usuario_tiempo 
    ON audit_eventos (usuario_id, created_at_utc DESC);

CREATE INDEX IF NOT EXISTS idx_audit_correlation 
    ON audit_eventos (correlation_id);

-- 10. Purga de Idempotencia por TTL
CREATE INDEX IF NOT EXISTS idx_ops_idempotencia_expira 
    ON ops_idempotencia (expires_at_utc);
