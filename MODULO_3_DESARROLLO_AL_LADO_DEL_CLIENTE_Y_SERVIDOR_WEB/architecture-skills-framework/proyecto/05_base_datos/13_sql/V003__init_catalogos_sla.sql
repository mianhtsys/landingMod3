-- ============================================================================
-- S3IH - MIGRACIÓN V003: Catálogos Tipificados y Políticas de SLA
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cat_solicitud_tipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    servicio_resolutor_id INT UNSIGNED NOT NULL,
    codigo_tipo VARCHAR(20) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion TEXT NULL,
    prioridad_sugerida VARCHAR(20) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_cat_tipos_servicio_codigo UNIQUE (servicio_resolutor_id, codigo_tipo),
    CONSTRAINT fk_cat_tipos_servicio FOREIGN KEY (servicio_resolutor_id) 
        REFERENCES org_servicios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_cat_tipos_prioridad CHECK (prioridad_sugerida IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA')),
    CONSTRAINT chk_cat_tipos_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cat_causales (
    codigo VARCHAR(30) PRIMARY KEY,
    tipo_causal VARCHAR(30) NOT NULL,
    descripcion VARCHAR(200) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cat_causales_tipo CHECK (tipo_causal IN ('SUSPENSION_ESPERA', 'RECHAZO_TECNICO', 'CANCELACION_USUARIO', 'REAPERTURA')),
    CONSTRAINT chk_cat_causales_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sla_calendarios (
    codigo_calendario VARCHAR(30) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    dias_laborales_semana VARCHAR(20) NOT NULL,
    CONSTRAINT chk_sla_calendarios_codigo CHECK (codigo_calendario IN ('CONTINUO_24_7', 'HABIL_ADMINISTRATIVO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sla_dias_inhabiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    descripcion VARCHAR(100) NOT NULL,
    sede_id INT UNSIGNED NULL,
    CONSTRAINT uq_sla_dias_fecha_sede UNIQUE (fecha, sede_id),
    CONSTRAINT fk_sla_dias_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sla_politicas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo_solicitud_id INT UNSIGNED NOT NULL,
    prioridad_asistencial VARCHAR(20) NOT NULL,
    tiempo_max_respuesta_mtta_min INT UNSIGNED NOT NULL,
    tiempo_max_resolucion_mttr_min INT UNSIGNED NOT NULL,
    calendario_codigo VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sla_politicas_tipo_prioridad UNIQUE (tipo_solicitud_id, prioridad_asistencial),
    CONSTRAINT fk_sla_politicas_tipo FOREIGN KEY (tipo_solicitud_id) 
        REFERENCES cat_solicitud_tipos (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sla_politicas_calendario FOREIGN KEY (calendario_codigo) 
        REFERENCES sla_calendarios (codigo_calendario) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sla_politicas_prioridad CHECK (prioridad_asistencial IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA')),
    CONSTRAINT chk_sla_politicas_mtta CHECK (tiempo_max_respuesta_mtta_min > 0),
    CONSTRAINT chk_sla_politicas_mttr CHECK (tiempo_max_resolucion_mttr_min > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
