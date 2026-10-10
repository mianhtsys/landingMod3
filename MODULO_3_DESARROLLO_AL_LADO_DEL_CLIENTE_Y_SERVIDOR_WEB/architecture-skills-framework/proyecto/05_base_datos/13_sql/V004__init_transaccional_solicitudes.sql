-- ============================================================================
-- S3IH - MIGRACIÓN V004: Módulo Transaccional Central de Solicitudes
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sol_solicitudes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_correlativo VARCHAR(20) NOT NULL,
    sede_id INT UNSIGNED NOT NULL,
    servicio_solicitante_id INT UNSIGNED NOT NULL,
    servicio_resolutor_id INT UNSIGNED NOT NULL,
    tipo_solicitud_id INT UNSIGNED NOT NULL,
    ubicacion_especifica VARCHAR(150) NOT NULL,
    prioridad_asistencial VARCHAR(20) NOT NULL,
    descripcion_falla TEXT NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'Registrada',
    es_expedita TINYINT(1) NOT NULL DEFAULT 0,
    usuario_solicitante_id INT UNSIGNED NOT NULL,
    tecnico_asignado_id INT UNSIGNED NULL,
    cuadrilla_asignada_id INT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    fecha_radicacion_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_asignacion_utc DATETIME NULL,
    fecha_inicio_proceso_utc DATETIME NULL,
    fecha_atencion_utc DATETIME NULL,
    fecha_cierre_utc DATETIME NULL,
    updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_sol_solicitudes_correlativo UNIQUE (codigo_correlativo),
    CONSTRAINT fk_sol_solicitudes_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_srv_sol FOREIGN KEY (servicio_solicitante_id) 
        REFERENCES org_servicios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_srv_res FOREIGN KEY (servicio_resolutor_id) 
        REFERENCES org_servicios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_tipo FOREIGN KEY (tipo_solicitud_id) 
        REFERENCES cat_solicitud_tipos (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_usr_sol FOREIGN KEY (usuario_solicitante_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_tec FOREIGN KEY (tecnico_asignado_id) 
        REFERENCES sec_usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_cuadrilla FOREIGN KEY (cuadrilla_asignada_id) 
        REFERENCES org_cuadrillas_guardia (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sol_solicitudes_prioridad CHECK (prioridad_asistencial IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA')),
    CONSTRAINT chk_sol_solicitudes_estado CHECK (estado IN ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_control_tiempo_sla (
    solicitud_id BIGINT UNSIGNED PRIMARY KEY,
    calendario_codigo VARCHAR(30) NOT NULL,
    fecha_compromiso_mtta_utc DATETIME NOT NULL,
    fecha_compromiso_mttr_utc DATETIME NOT NULL,
    segundos_consumidos INT UNSIGNED NOT NULL DEFAULT 0,
    esta_pausado TINYINT(1) NOT NULL DEFAULT 0,
    fecha_ultima_pausa_utc DATETIME NULL,
    segundos_pausados_acumulados INT UNSIGNED NOT NULL DEFAULT 0,
    fecha_tentativa_reanudacion DATE NULL,
    alerta_75_emitida TINYINT(1) NOT NULL DEFAULT 0,
    escalamiento_100_emitido TINYINT(1) NOT NULL DEFAULT 0,
    updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sol_sla_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_sla_calendario FOREIGN KEY (calendario_codigo) 
        REFERENCES sla_calendarios (codigo_calendario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_historial_estados (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    estado_anterior VARCHAR(30) NOT NULL,
    estado_nuevo VARCHAR(30) NOT NULL,
    causal_codigo VARCHAR(30) NULL,
    motivo_texto TEXT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sol_hist_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_hist_causal FOREIGN KEY (causal_codigo) 
        REFERENCES cat_causales (codigo) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_hist_usuario FOREIGN KEY (usuario_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sol_hist_estado_ant CHECK (estado_anterior IN ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada')),
    CONSTRAINT chk_sol_hist_estado_nvo CHECK (estado_nuevo IN ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_informes_atencion (
    solicitud_id BIGINT UNSIGNED PRIMARY KEY,
    diagnostico_falla TEXT NOT NULL,
    acciones_ejecutadas TEXT NOT NULL,
    tiempo_real_minutos INT UNSIGNED NOT NULL,
    condicion_final_activo VARCHAR(100) NOT NULL,
    repuestos_utilizados_json JSON NOT NULL,
    tecnico_responsable_id INT UNSIGNED NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sol_inf_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_inf_tecnico FOREIGN KEY (tecnico_responsable_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sol_inf_tiempo CHECK (tiempo_real_minutos > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_reaperturas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    causal_codigo VARCHAR(30) NOT NULL,
    motivo_inconformidad TEXT NOT NULL,
    usuario_solicitante_id INT UNSIGNED NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sol_reap_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reap_causal FOREIGN KEY (causal_codigo) 
        REFERENCES cat_causales (codigo) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reap_usuario FOREIGN KEY (usuario_solicitante_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_reasignaciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    tecnico_anterior_id INT UNSIGNED NULL,
    tecnico_nuevo_id INT UNSIGNED NULL,
    cuadrilla_anterior_id INT UNSIGNED NULL,
    cuadrilla_nueva_id INT UNSIGNED NULL,
    motivo TEXT NOT NULL,
    usuario_autorizador_id INT UNSIGNED NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sol_reasig_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reasig_tec_ant FOREIGN KEY (tecnico_anterior_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reasig_tec_nvo FOREIGN KEY (tecnico_nuevo_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reasig_cuad_ant FOREIGN KEY (cuadrilla_anterior_id) 
        REFERENCES org_cuadrillas_guardia (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reasig_cuad_nva FOREIGN KEY (cuadrilla_nueva_id) 
        REFERENCES org_cuadrillas_guardia (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_reasig_autorizador FOREIGN KEY (usuario_autorizador_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_bitacora (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    tipo_entrada VARCHAR(30) NOT NULL,
    contenido TEXT NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sol_bit_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_bit_usuario FOREIGN KEY (usuario_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sol_bit_tipo CHECK (tipo_entrada IN ('NOTA_TECNICA', 'CAMBIO_GUARDIA', 'PIEZA_SOLICITADA', 'COMUNICACION'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sol_adjuntos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    nombre_archivo_original VARCHAR(255) NOT NULL,
    uuid_almacenamiento VARCHAR(36) NOT NULL,
    tipo_mime_real VARCHAR(100) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    tamano_bytes BIGINT UNSIGNED NOT NULL,
    hash_sha256 VARCHAR(64) NOT NULL,
    uri_almacenamiento VARCHAR(500) NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sol_adj_uuid UNIQUE (uuid_almacenamiento),
    CONSTRAINT fk_sol_adj_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_adj_usuario FOREIGN KEY (usuario_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sol_adj_ext CHECK (extension IN ('pdf', 'jpg', 'jpeg', 'png')),
    CONSTRAINT chk_sol_adj_tamano CHECK (tamano_bytes > 0 AND tamano_bytes <= 5242880)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
