-- ============================================================================
-- S3IH - MIGRACIÓN V002: Módulo Seguridad, Identidad y RBAC
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sec_roles (
    codigo VARCHAR(30) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NULL,
    permisos_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sec_usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username_ldap VARCHAR(50) NOT NULL,
    email_corporativo VARCHAR(100) NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    sede_id INT UNSIGNED NOT NULL,
    servicio_id INT UNSIGNED NOT NULL,
    rol_codigo VARCHAR(30) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    ultimo_acceso_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sec_usuarios_ldap UNIQUE (username_ldap),
    CONSTRAINT uq_sec_usuarios_email UNIQUE (email_corporativo),
    CONSTRAINT fk_sec_usuarios_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sec_usuarios_servicio FOREIGN KEY (servicio_id) 
        REFERENCES org_servicios (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sec_usuarios_rol FOREIGN KEY (rol_codigo) 
        REFERENCES sec_roles (codigo) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sec_usuarios_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_cuadrilla_miembros (
    cuadrilla_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    fecha_incorporacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cuadrilla_id, usuario_id),
    CONSTRAINT fk_org_miembros_cuadrilla FOREIGN KEY (cuadrilla_id) 
        REFERENCES org_cuadrillas_guardia (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_org_miembros_usuario FOREIGN KEY (usuario_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
