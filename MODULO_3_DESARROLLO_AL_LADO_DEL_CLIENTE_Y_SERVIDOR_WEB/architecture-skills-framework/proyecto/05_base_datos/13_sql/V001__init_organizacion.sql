-- ============================================================================
-- S3IH - MIGRACIÓN V001: Módulo Organización Hospitalaria Multisede
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS org_sedes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_institucional VARCHAR(2) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    direccion VARCHAR(255) NULL,
    telefono VARCHAR(50) NULL,
    es_central TINYINT(1) NOT NULL DEFAULT 0,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_org_sedes_codigo UNIQUE (codigo_institucional),
    CONSTRAINT chk_org_sedes_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_servicios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sede_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    tipo_servicio VARCHAR(20) NOT NULL,
    ubicacion_piso_area VARCHAR(150) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_org_servicios_sede_codigo UNIQUE (sede_id, codigo),
    CONSTRAINT fk_org_servicios_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_org_servicios_tipo CHECK (tipo_servicio IN ('ASISTENCIAL', 'RESOLUTOR')),
    CONSTRAINT chk_org_servicios_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_cuadrillas_guardia (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sede_id INT UNSIGNED NOT NULL,
    servicio_resolutor_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    turno_codigo VARCHAR(20) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_org_cuadrillas_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_org_cuadrillas_servicio FOREIGN KEY (servicio_resolutor_id) 
        REFERENCES org_servicios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_org_cuadrillas_turno CHECK (turno_codigo IN ('MANANA', 'TARDE', 'NOCHE', 'GUARDIA_24H')),
    CONSTRAINT chk_org_cuadrillas_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
