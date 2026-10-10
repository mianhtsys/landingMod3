-- ============================================================================
-- S3IH - MIGRACIÓN V005: Operaciones, Idempotencia y Auditoría Legal
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ops_idempotencia (
    idempotency_key VARCHAR(36) PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    recurso_tipo VARCHAR(50) NOT NULL,
    request_hash_sha256 VARCHAR(64) NOT NULL,
    response_status_http INT UNSIGNED NOT NULL,
    response_body_json JSON NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at_utc DATETIME NOT NULL,
    CONSTRAINT fk_ops_idem_usuario FOREIGN KEY (usuario_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ops_outbox_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo_evento VARCHAR(50) NOT NULL,
    agregado_tipo VARCHAR(50) NOT NULL,
    agregado_id BIGINT UNSIGNED NOT NULL,
    payload_json JSON NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    reintentos INT UNSIGNED NOT NULL DEFAULT 0,
    error_mensaje TEXT NULL,
    scheduled_at_utc DATETIME NOT NULL,
    processed_at_utc DATETIME NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_ops_outbox_tipo CHECK (tipo_evento IN ('ALERTA_SLA_75', 'ESCALAMIENTO_SLA_100', 'DESPACHO_EMAIL_SMTP', 'AUTO_CIERRE_48H')),
    CONSTRAINT chk_ops_outbox_estado CHECK (estado IN ('PENDIENTE', 'PROCESANDO', 'ENVIADO', 'FALLIDO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    accion VARCHAR(50) NOT NULL,
    tabla_afectada VARCHAR(50) NOT NULL,
    registro_id BIGINT UNSIGNED NOT NULL,
    datos_anteriores_json JSON NULL,
    datos_nuevos_json JSON NULL,
    hash_encadenado_sha256 VARCHAR(64) NOT NULL,
    correlation_id VARCHAR(36) NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
