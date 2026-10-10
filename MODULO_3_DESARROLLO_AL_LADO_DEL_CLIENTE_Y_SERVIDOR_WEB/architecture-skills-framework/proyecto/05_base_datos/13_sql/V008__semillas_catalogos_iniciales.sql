-- ============================================================================
-- S3IH - MIGRACIÓN V008: Semillas de Configuración y Catálogos Iniciales
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Sedes Hospitalarias de la Red (RF-001)
INSERT INTO org_sedes (id, codigo_institucional, nombre, direccion, telefono, es_central, estado) VALUES
(1, 'H1', 'Hospital Universitario Central', 'Av. Salud 1000, Sector Centro', '+56 2 2222 1001', 1, 'activo'),
(2, 'H2', 'Hospital General Norte', 'Calle Esperanza 450, Sector Norte', '+56 2 2222 2002', 0, 'activo'),
(3, 'H3', 'Hospital Pediátrico Sur', 'Av. Infancia 890, Sector Sur', '+56 2 2222 3003', 0, 'activo'),
(4, 'H4', 'Hospital Materno Infantil', 'Calle Vida 120, Sector Oriente', '+56 2 2222 4004', 0, 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 2. Servicios Base por Sede (RF-002)
-- Sede H1: Asistenciales y Resolutores
INSERT INTO org_servicios (id, sede_id, codigo, nombre, tipo_servicio, ubicacion_piso_area, estado) VALUES
(1, 1, 'QUIROF_CENTRAL', 'Pabellón Quirúrgico Central', 'ASISTENCIAL', 'Piso 3, Ala Quirúrgica', 'activo'),
(2, 1, 'URGENCIAS_H1', 'Unidad de Emergencias Adulto', 'ASISTENCIAL', 'Piso 1, Acceso Urgencias', 'activo'),
(3, 1, 'UCI_MEDICA', 'Unidad de Cuidados Intensivos', 'ASISTENCIAL', 'Piso 2, Torre Médica', 'activo'),
(4, 1, 'MANT_BIOMED', 'Mantenimiento Biomédico', 'RESOLUTOR', 'Subsuelo 1, Taller Central', 'activo'),
(5, 1, 'INFRA_LOCATIVA', 'Infraestructura y Servicios Generales', 'RESOLUTOR', 'Subsuelo 2, Servicios', 'activo'),
(6, 1, 'TI_SISTEMAS', 'Tecnología y Sistemas de Información', 'RESOLUTOR', 'Piso 4, Administración', 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 3. Roles Institucionales RBAC (RF-003, RNF-031)
INSERT INTO sec_roles (codigo, nombre, descripcion, permisos_json) VALUES
('ADMIN_SISTEMA', 'Administrador del Sistema', 'Control total de configuración, catálogos y gestión de usuarios', '{"solicitudes":["crear","leer","asignar","reasignar","cerrar"],"catalogos":["administrar"],"auditoria":["consultar"]}'),
('SOLICITANTE_ASISTENCIAL', 'Solicitante Asistencial', 'Personal clínico de piso (médicos, enfermeros, coordinadores de sala)', '{"solicitudes":["crear","leer_propias","reabrir"]}'),
('TECNICO_RESOLUTOR', 'Técnico Resolutor de Guardia', 'Personal técnico de guardia encargado de ejecución y registro de atención', '{"solicitudes":["leer_asignadas","atender","bitacora_escribir"]}'),
('SUPERVISOR_GUARDIA', 'Supervisor de Cuadrilla', 'Jefatura de turno encargada de asignación, reasignación y monitoreo de guardia', '{"solicitudes":["crear","leer_sede","asignar","reasignar","pausar"]}'),
('JEFE_SERVICIO', 'Jefe de Servicio Resolutor', 'Jefatura técnica responsable de cumplimiento de SLAs e informes de gestión', '{"solicitudes":["leer_todas","reportes","escalamientos"]}'),
('AUDITOR_CALIDAD', 'Auditor de Calidad Hospitalaria', 'Auditoría clínica, forense y fiscalización de cumplimiento de SLAs', '{"solicitudes":["leer_todas"],"auditoria":["consultar_completa"]}')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 4. Calendarios de Servicio Duales (RF-013)
INSERT INTO sla_calendarios (codigo_calendario, nombre, hora_inicio, hora_fin, dias_laborales_semana) VALUES
('CONTINUO_24_7', 'Atención Continua Ininterrumpida 24/7', '00:00:00', '23:59:59', '1,2,3,4,5,6,7'),
('HABIL_ADMINISTRATIVO', 'Jornada Hábil Administrativa', '08:00:00', '17:00:00', '1,2,3,4,5')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 5. Catálogo de Causales Estandarizadas (RF-014)
INSERT INTO cat_causales (codigo, tipo_causal, descripcion, estado) VALUES
('ESPERA_REPUESTO_BODEGA', 'SUSPENSION_ESPERA', 'Falta de insumo o pieza técnica en bodega central', 'activo'),
('ESPERA_PROVEEDOR_EXT', 'SUSPENSION_ESPERA', 'Requerimiento de garantía o visita técnica del fabricante externo', 'activo'),
('PACIENTE_EN_PROCEDIMIENTO', 'SUSPENSION_ESPERA', 'Área clínica restringida temporalmente por procedimiento médico vital', 'activo'),
('RECHAZO_DUPLICADA', 'RECHAZO_TECNICO', 'Requerimiento registrado previamente por otro funcionario de piso', 'activo'),
('RECHAZO_AREA_INCORRECTA', 'RECHAZO_TECNICO', 'Falla asignada a servicio resolutor incompetente para la especialidad', 'activo'),
('CANCELADA_RESUELTO_PISO', 'CANCELACION_USUARIO', 'Problema operativo solucionado directamente por el personal asistencial', 'activo'),
('REAPERTURA_FALLA_REINCIDENTE', 'REAPERTURA', 'Equipo presentó la misma falla técnica antes de 48 horas post-atención', 'activo'),
('REAPERTURA_INCONFORMIDAD', 'REAPERTURA', 'Reparación locativa incompleta o con defectos funcionales evidentes', 'activo')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- 6. Tipos de Solicitud Piloto y Clasificación (RF-010)
INSERT INTO cat_solicitud_tipos (id, servicio_resolutor_id, codigo_tipo, nombre, descripcion, prioridad_sugerida, estado) VALUES
(1, 4, 'BIO_FALLA_VENTILADOR', 'Falla en Ventilador Mecánico o Monitor Vital', 'Alarma crítica, fallo de alimentación o sensor descalibrado', 'VITAL', 'activo'),
(2, 4, 'BIO_CALIBRACION_DESFIB', 'Falla o Descalibración de Desfibrilador', 'Revisión técnica de equipo de carro de paro', 'VITAL', 'activo'),
(3, 5, 'LOC_CORTE_ENERGIA_PAB', 'Fallo de Suministro Eléctrico en Quirófano', 'Interrupción de tomas eléctricas reguladas o iluminación de campo quirúrgico', 'VITAL', 'activo'),
(4, 5, 'LOC_FUGA_AGUA_URG', 'Fuga de Agua o Fluido en Área Clínica', 'Filtración en lavamanos quirúrgico o tubería de piso', 'ALTA', 'activo'),
(5, 6, 'TI_CAIDA_TERMINAL_QUI', 'Caída de Terminal o Monitor de Signos en Pabellón', 'Equipo congelado o sin conexión a red interna', 'ALTA', 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 7. Políticas de SLA Iniciales (RF-011, RF-012)
INSERT INTO sla_politicas (tipo_solicitud_id, prioridad_asistencial, tiempo_max_respuesta_mtta_min, tiempo_max_resolucion_mttr_min, calendario_codigo) VALUES
(1, 'VITAL', 15, 60, 'CONTINUO_24_7'),
(2, 'VITAL', 15, 60, 'CONTINUO_24_7'),
(3, 'VITAL', 15, 45, 'CONTINUO_24_7'),
(4, 'ALTA', 30, 120, 'CONTINUO_24_7'),
(5, 'ALTA', 30, 180, 'CONTINUO_24_7')
ON DUPLICATE KEY UPDATE tiempo_max_respuesta_mtta_min = VALUES(tiempo_max_respuesta_mtta_min);
