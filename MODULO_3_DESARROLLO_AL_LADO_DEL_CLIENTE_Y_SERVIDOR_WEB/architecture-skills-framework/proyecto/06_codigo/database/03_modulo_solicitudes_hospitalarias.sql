-- ============================================================================
-- S3IH - SISTEMA DE SEGUIMIENTO DE SOLICITUDES INTERNAS HOSPITALARIAS
-- Migración Física DDL y Semillas Maestras para el Módulo de Solicitudes Hospitalarias
-- Compatible con MySQL 8.x / InnoDB (utf8mb4_unicode_ci)
-- ============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- 1. Tabla: org_sedes (Red de hasta 4 hospitales)
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

-- 2. Tabla: org_servicios (Servicios asistenciales y resolutores por sede)
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

-- 3. Tabla: cat_solicitud_tipos (Catálogo tipificado de fallas/solicitudes)
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

-- 4. Tablas de Calendarios y Políticas SLA
CREATE TABLE IF NOT EXISTS sla_calendarios (
    codigo_calendario VARCHAR(30) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    dias_laborales_semana VARCHAR(20) NOT NULL,
    CONSTRAINT chk_sla_calendarios_codigo CHECK (codigo_calendario IN ('CONTINUO_24_7', 'HABIL_ADMINISTRATIVO'))
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

-- 5. Tabla Central: sol_solicitudes
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
        REFERENCES auth_usuario (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_solicitudes_tec FOREIGN KEY (tecnico_asignado_id) 
        REFERENCES auth_usuario (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_sol_solicitudes_prioridad CHECK (prioridad_asistencial IN ('VITAL', 'ALTA', 'MEDIA', 'BAJA')),
    CONSTRAINT chk_sol_solicitudes_estado CHECK (estado IN ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla: sol_historial_estados (Bitácora inmutable append-only)
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
    CONSTRAINT fk_sol_hist_usuario FOREIGN KEY (usuario_id) 
        REFERENCES auth_usuario (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sol_hist_estado_ant CHECK (estado_anterior IN ('Inicio', 'Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada')),
    CONSTRAINT chk_sol_hist_estado_nvo CHECK (estado_nuevo IN ('Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabla: ops_outbox_eventos (Patrón Transactional Outbox)
CREATE TABLE IF NOT EXISTS ops_outbox_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitud_id BIGINT UNSIGNED NOT NULL,
    tipo_evento VARCHAR(50) NOT NULL,
    payload_json JSON NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    reintentos INT UNSIGNED NOT NULL DEFAULT 0,
    error_mensaje TEXT NULL,
    created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    procesado_at_utc DATETIME NULL,
    CONSTRAINT fk_ops_outbox_solicitud FOREIGN KEY (solicitud_id) 
        REFERENCES sol_solicitudes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_ops_outbox_estado CHECK (estado IN ('PENDIENTE', 'PROCESADO', 'FALLIDO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabla: sol_correlativo_secuencia (Control atómico de correlativos H{sede}-{año}-{secuencial})
CREATE TABLE IF NOT EXISTS sol_correlativo_secuencia (
    sede_id INT UNSIGNED NOT NULL,
    anio INT UNSIGNED NOT NULL,
    ultimo_secuencial INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (sede_id, anio),
    CONSTRAINT fk_sol_correlativo_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ============================================================================
-- SEMILLAS MAESTRAS HOSPITALARIAS (Red de 4 Hospitales)
-- ============================================================================

START TRANSACTION;

-- Sedes
INSERT INTO org_sedes (id, codigo_institucional, nombre, direccion, telefono, es_central, estado) VALUES
(1, 'H1', 'Hospital Central Universitario', 'Av. Salud y Vida 1200, La Paz', '+591 2 2441111', 1, 'activo'),
(2, 'H2', 'Hospital Pediátrico del Norte', 'Av. Juan Pablo II 450, El Alto', '+591 2 2842222', 0, 'activo'),
(3, 'H3', 'Hospital Materno Infantil Sur', 'Calle 14 Calacoto 789, Zona Sur', '+591 2 2773333', 0, 'activo'),
(4, 'H4', 'Hospital General del Este', 'Av. Las Américas 100, Miraflores', '+591 2 2224444', 0, 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), estado = VALUES(estado);

-- Servicios Clínicos Asistenciales (solicitantes) y Unidades Resolutoras para H1
INSERT INTO org_servicios (id, sede_id, codigo, nombre, tipo_servicio, ubicacion_piso_area, estado) VALUES
(1, 1, 'UCI-ADUL', 'Unidad de Cuidados Intensivos Adultos', 'ASISTENCIAL', 'Piso 3, Bloque B', 'activo'),
(2, 1, 'QUIROF', 'Quirófano Central y Cirugía Mayor', 'ASISTENCIAL', 'Piso 2, Bloque Quirúrgico', 'activo'),
(3, 1, 'URGENC', 'Servicio de Emergencias y Trauma', 'ASISTENCIAL', 'Planta Baja, Acceso Ambulancias', 'activo'),
(4, 1, 'BIOMED', 'Mantenimiento e Ingeniería Biomédica', 'RESOLUTOR', 'Subsuelo 1, Taller Técnico', 'activo'),
(5, 1, 'FARMIN', 'Farmacia Interna y Suministros Hospitalarios', 'RESOLUTOR', 'Piso 1, Almacén Central', 'activo'),
(6, 1, 'INFRAS', 'Infraestructura y Servicios Generales', 'RESOLUTOR', 'Patio Técnico Posterior', 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), estado = VALUES(estado);

-- Tipos de Solicitud Tipificados
INSERT INTO cat_solicitud_tipos (id, servicio_resolutor_id, codigo_tipo, nombre, descripcion, prioridad_sugerida, estado) VALUES
(1, 4, 'BIO-VENT-FALLA', 'Falla Crítica de Ventilador Mecánico UCI', 'Falla eléctrica o neumática en respirador de soporte vital activo', 'VITAL', 'activo'),
(2, 4, 'BIO-DESF-CALIB', 'Calibración Urgente de Desfibrilador Quirófano', 'Revisión y calibración de palas de desfibrilación', 'ALTA', 'activo'),
(3, 5, 'FAR-ANEST-URG', 'Desabastecimiento Crítico de Agentes Anestésicos', 'Requerimiento urgente de anestésicos inhalatorios o fentanilo', 'VITAL', 'activo'),
(4, 6, 'INF-OXIG-FUGA', 'Alerta de Presión en Red de Gases Medicinales', 'Baja presión detectada en ductos de oxígeno medicinal', 'VITAL', 'activo'),
(5, 6, 'INF-CLIM-QUI', 'Falla en Filtros HEPA de Climatización Quirófano', 'Alteración de flujo laminar y temperatura en quirófano activo', 'ALTA', 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), prioridad_sugerida = VALUES(prioridad_sugerida);

-- Calendarios SLA
INSERT INTO sla_calendarios (codigo_calendario, nombre, hora_inicio, hora_fin, dias_laborales_semana) VALUES
('CONTINUO_24_7', 'Atención Continua de Guardia Crítica 24/7', '00:00:00', '23:59:59', 'LUN-DOM'),
('HABIL_ADMINISTRATIVO', 'Horario Hábil Administrativo y Soporte Ordinario', '08:00:00', '17:00:00', 'LUN-VIE')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- Políticas SLA para Tipos de Solicitud (MTTA = Tiempo de Asignación, MTTR = Tiempo de Resolución)
INSERT INTO sla_politicas (id, tipo_solicitud_id, prioridad_asistencial, tiempo_max_respuesta_mtta_min, tiempo_max_resolucion_mttr_min, calendario_codigo) VALUES
(1, 1, 'VITAL', 15, 60, 'CONTINUO_24_7'),
(2, 2, 'ALTA', 30, 180, 'CONTINUO_24_7'),
(3, 3, 'VITAL', 10, 30, 'CONTINUO_24_7'),
(4, 4, 'VITAL', 15, 45, 'CONTINUO_24_7'),
(5, 5, 'ALTA', 30, 120, 'CONTINUO_24_7')
ON DUPLICATE KEY UPDATE tiempo_max_respuesta_mtta_min = VALUES(tiempo_max_respuesta_mtta_min), tiempo_max_resolucion_mttr_min = VALUES(tiempo_max_resolucion_mttr_min);

COMMIT;
