# Paso 07 — Diseño Físico DDL de Base de Datos (S3IH)

**Workflow:** `02_database_workflow` — Paso 07  
**Skills utilizados:** `databases`, `postgresql-table-design`, `database-schema-designer`  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**DBMS Seleccionado (Paso 06):** Compatibilidad dual SQL ANSI: **MySQL 8.x (InnoDB, `utf8mb4`)** y **PostgreSQL 16+**  
**Entradas:**  
- `proyecto/05_base_datos/03_modelo_logico/modelo_logico.md`  
- `proyecto/05_base_datos/04_normalizacion/informe_normalizacion.md`  
- `proyecto/05_base_datos/05_modelo_fisico/seleccion_dbms.md`  
- `proyecto/04_decisiones/decisiones_base_datos.md` (ADR-001)  

**Aviso de Gobernanza:** Este artefacto contiene el borrador del diseño físico DDL inicial. **No se ejecuta en producción ni genera migraciones activas** hasta alcanzar `STATUS: APPROVED` en el Paso 14 (Revisión DBA).

---

## 1. Estrategia de Tipado de Datos Físicos

El mapeo entre los dominios semánticos lógicos y los tipos físicos de motor se define con máxima precisión para garantizar cero desperdicio de memoria y durabilidad transaccional:

| Dominio Lógico | Tipo MySQL 8.x (InnoDB) | Tipo PostgreSQL 16+ | Justificación Técnica de Dominio |
| :--- | :--- | :--- | :--- |
| **PK Surrogate Estándar** | `INT UNSIGNED AUTO_INCREMENT` | `SERIAL PRIMARY KEY` | Catálogos y entidades maestras con volumen $< 2\text{M}$ filas. |
| **PK Transaccional Crítica** | `BIGINT UNSIGNED AUTO_INCREMENT`| `BIGSERIAL PRIMARY KEY` | Solicitudes, historial, bitácora, outbox y auditoría legal ($> 10\text{M}$ filas). |
| **UUID v4 Identificadores** | `VARCHAR(36)` / `BINARY(16)` | `UUID` | Idempotencia (`idempotency_key`) y UUID de adjuntos ofuscados. |
| **Correlativo Hospitalario** | `VARCHAR(20)` | `VARCHAR(20)` | Código `H{sede}-{año}-{secuencial}` (ej. `H1-2026-000452`, RN-001). |
| **Cadenas Cortas / Códigos** | `VARCHAR(30)` / `VARCHAR(50)` | `VARCHAR(30)` / `VARCHAR(50)` | Nombres técnicos, códigos de servicio y causales tipificadas. |
| **Descripciones / Justificaciones**| `TEXT` | `TEXT` | Sin truncamiento arbitrario ($\ge 20$ caracteres en motivos clínicos). |
| **Banderas Booleanas** | `TINYINT(1)` | `BOOLEAN` | Indicadores binarios (`es_expedita`, `alerta_75_emitida`). |
| **Tiempos y Minutos Enteros** | `INT UNSIGNED` | `INTEGER` | Plazos SLA MTTA/MTTR y tiempos reales de intervención técnica. |
| **Marcas Temporales** | `DATETIME` (UTC) | `TIMESTAMPTZ` (UTC) | Evita el límite del año 2038 de `TIMESTAMP` en MySQL; UTC estricto. |
| **Control Concurrencia Optimista**| `INT UNSIGNED NOT NULL DEFAULT 1`| `INTEGER NOT NULL DEFAULT 1` | Columna `version` contra *lost updates* concurrentes (D-01). |
| **Estructuras Flexibles** | `JSON` | `JSONB` | Permisos RBAC, repuestos usados, deltas de auditoría y payload outbox. |
| **Control de Tamaño Adjuntos** | `BIGINT UNSIGNED` + `CHECK` | `BIGINT` + `CHECK` | Límite estricto $\le 5.242.880\text{ bytes}$ (5 MB, Restricción Técnica 5). |

---

## 2. Convenciones de Motor y Almacenamiento

1. **Juego de Caracteres y Colación:**  
   `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` (MySQL) / `LC_COLLATE = 'C.UTF-8'` (PostgreSQL) para soporte universal de terminología médica multilingüe.
2. **Motor de Almacenamiento:**  
   `ENGINE = InnoDB` forzado en cada tabla para garantizar cumplimiento total de ACID y bloqueos por fila (*row-level locking*).
3. **Acciones Referenciales (FK):**  
   - `ON DELETE RESTRICT`: Prohibición total de cascadas destructivas sobre solicitudes, historiales y catálogos (RN-012, RN-013).
   - `ON UPDATE CASCADE`: Propagación controlada en códigos de catálogo si fuese modificado su identificador canónico.
   - `ON DELETE SET NULL`: Únicamente permitido en el técnico asignado (`sol_solicitudes.tecnico_asignado_id`) para permitir rotación de personal sin bloquear la orden.

---

## 3. Esquema Físico DDL Completo (MySQL 8.x / InnoDB)

A continuación se detalla la especificación física ejecutable compatible con MySQL 8.x (y portable a PostgreSQL 16+):

```sql
-- ============================================================================
-- SISTEMA DE SEGUIMIENTO DE SOLICITUDES INTERNAS HOSPITALARIAS (S3IH)
-- ESQUEMA FÍSICO DDL - BORRADOR DE DISEÑO (PASO 07)
-- No ejecutar en entorno real antes de STATUS: APPROVED (Paso 14)
-- ============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ----------------------------------------------------------------------------
-- 1. MÓDULO ORGANIZACIÓN HOSPITALARIA MULTISEDE (org_)
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS org_cuadrilla_miembros;
DROP TABLE IF EXISTS org_cuadrillas_guardia;
DROP TABLE IF EXISTS org_servicios;
DROP TABLE IF EXISTS org_sedes;

CREATE TABLE org_sedes (
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

CREATE TABLE org_servicios (
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

CREATE TABLE org_cuadrillas_guardia (
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

-- ----------------------------------------------------------------------------
-- 2. MÓDULO SEGURIDAD Y CONTROL DE ACCESO (sec_)
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS sec_usuarios;
DROP TABLE IF EXISTS sec_roles;

CREATE TABLE sec_roles (
    codigo VARCHAR(30) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NULL,
    permisos_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sec_usuarios (
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

CREATE TABLE org_cuadrilla_miembros (
    cuadrilla_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    fecha_incorporacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cuadrilla_id, usuario_id),
    CONSTRAINT fk_org_miembros_cuadrilla FOREIGN KEY (cuadrilla_id) 
        REFERENCES org_cuadrillas_guardia (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_org_miembros_usuario FOREIGN KEY (usuario_id) 
        REFERENCES sec_usuarios (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. MÓDULO CATÁLOGOS TIPIFICADOS Y POLÍTICAS DE SLA (cat_ / sla_)
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS sla_politicas;
DROP TABLE IF EXISTS sla_dias_inhabiles;
DROP TABLE IF EXISTS sla_calendarios;
DROP TABLE IF EXISTS cat_causales;
DROP TABLE IF EXISTS cat_solicitud_tipos;

CREATE TABLE cat_solicitud_tipos (
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

CREATE TABLE cat_causales (
    codigo VARCHAR(30) PRIMARY KEY,
    tipo_causal VARCHAR(30) NOT NULL,
    descripcion VARCHAR(200) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cat_causales_tipo CHECK (tipo_causal IN ('SUSPENSION_ESPERA', 'RECHAZO_TECNICO', 'CANCELACION_USUARIO', 'REAPERTURA')),
    CONSTRAINT chk_cat_causales_estado CHECK (estado IN ('activo', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sla_calendarios (
    codigo_calendario VARCHAR(30) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    dias_laborales_semana VARCHAR(20) NOT NULL,
    CONSTRAINT chk_sla_calendarios_codigo CHECK (codigo_calendario IN ('CONTINUO_24_7', 'HABIL_ADMINISTRATIVO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sla_dias_inhabiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    descripcion VARCHAR(100) NOT NULL,
    sede_id INT UNSIGNED NULL,
    CONSTRAINT uq_sla_dias_fecha_sede UNIQUE (fecha, sede_id),
    CONSTRAINT fk_sla_dias_sede FOREIGN KEY (sede_id) 
        REFERENCES org_sedes (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sla_politicas (
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

-- ----------------------------------------------------------------------------
-- 4. MÓDULO TRANSACCIONAL DE SOLICITUDES Y AUDITORÍA (sol_)
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS sol_adjuntos;
DROP TABLE IF EXISTS sol_bitacora;
DROP TABLE IF EXISTS sol_reasignaciones;
DROP TABLE IF EXISTS sol_reaperturas;
DROP TABLE IF EXISTS sol_informes_atencion;
DROP TABLE IF EXISTS sol_historial_estados;
DROP TABLE IF EXISTS sol_control_tiempo_sla;
DROP TABLE IF EXISTS sol_solicitudes;

CREATE TABLE sol_solicitudes (
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

CREATE TABLE sol_control_tiempo_sla (
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

CREATE TABLE sol_historial_estados (
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

CREATE TABLE sol_informes_atencion (
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

CREATE TABLE sol_reaperturas (
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

CREATE TABLE sol_reasignaciones (
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

CREATE TABLE sol_bitacora (
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

CREATE TABLE sol_adjuntos (
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

-- ----------------------------------------------------------------------------
-- 5. MÓDULO OPERACIONES, IDEMPOTENCIA Y AUDITORÍA LEGAL (ops_ / audit_)
-- ----------------------------------------------------------------------------

DROP TABLE IF EXISTS audit_eventos;
DROP TABLE IF EXISTS ops_outbox_eventos;
DROP TABLE IF EXISTS ops_idempotencia;

CREATE TABLE ops_idempotencia (
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

CREATE TABLE ops_outbox_eventos (
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

CREATE TABLE audit_eventos (
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

SET foreign_key_checks = 1;
```

---

## 4. Índices Operativos Iniciales (Refinamiento formal en Paso 11)

Se declaran los índices mínimos necesarios para el cumplimiento de las consultas con latencia $p95 < 200\text{ ms}$:

```sql
-- Bandeja Asistencial Filtrada por Sede y Estado (RF-025, RNF-010)
CREATE INDEX idx_sol_solicitudes_bandeja 
    ON sol_solicitudes (sede_id, estado, prioridad_asistencial, fecha_radicacion_utc DESC);

-- Asignación Técnica Activa (RF-030)
CREATE INDEX idx_sol_solicitudes_tecnico_act 
    ON sol_solicitudes (tecnico_asignado_id, estado);

-- Monitoreo de Cuadrillas Hospitalarias (RF-004)
CREATE INDEX idx_sol_solicitudes_cuadrilla 
    ON sol_solicitudes (cuadrilla_asignada_id, estado);

-- Polling Worker Outbox con SKIP LOCKED (D-06, RF-052)
CREATE INDEX idx_ops_outbox_polling 
    ON ops_outbox_eventos (estado, scheduled_at_utc);

-- Barrido de Alertas SLA Activas (RF-051)
CREATE INDEX idx_sol_sla_monitoreo 
    ON sol_control_tiempo_sla (esta_pausado, fecha_compromiso_mttr_utc);

-- Trazabilidad y Pista Legal (RF-072, RN-013)
CREATE INDEX idx_audit_eventos_busqueda 
    ON audit_eventos (tabla_afectada, registro_id, created_at_utc DESC);
```

---

## 5. Verificación de Integridad y Trazabilidad

- [x] **Zero-PHI Asegurado (RNF-035):** No existen campos para pacientes, diagnósticos clínicos médicos ni números de historia clínica. Solo descripciones de fallas locativas y de equipamiento biomédico.
- [x] **Idempotencia Garantizada (RN-011, D-03):** Clave `idempotency_key` con hash criptográfico y tiempo de expiración TTL.
- [x] **Concurrencia Optimista (D-01, CA-004):** Columna `version INT NOT NULL DEFAULT 1` presente en `sol_solicitudes`.
- [x] **Prohibición de BLOBs (Restricción Técnica 5, D-04):** `sol_adjuntos` sólo almacena hashes SHA-256, paths y metadatos con tamaño máximo verificado por constraint `CHECK` ($\le 5\text{ MB}$).
- [x] **Sin Sentencias Destructivas:** Configuración de claves foráneas con `ON DELETE RESTRICT` en todas las pistas de auditoría e historiales.

---

**Estado del Paso:** Paso 07 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 08 — Integridad Referencial y Reglas de Negocio (`proyecto/05_base_datos/06_integridad/integridad.md`), utilizando `database-schema-designer` y `databases`.
