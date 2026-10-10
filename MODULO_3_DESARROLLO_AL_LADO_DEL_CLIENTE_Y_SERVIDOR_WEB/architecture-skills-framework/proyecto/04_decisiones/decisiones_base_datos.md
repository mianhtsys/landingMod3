# Decisiones de Base de Datos — Sistema Hospitalario S3IH

**Estado:** APROBADO  
**Fecha:** 2026-10-07  
**Dominio:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Fuente:** ADR-001, `04_database_analysis.md`, `08_recomendacion_final.md`  

---

## 1. Motor de Persistencia Relacional y ACID

1. **Tipo de Persistencia:** Base de datos relacional transaccional (RDBMS) bajo motor **PostgreSQL 16+** (con total compatibilidad y portabilidad probada hacia **MySQL 8.x / InnoDB**).
2. **Justificación:** Las reglas de negocio hospitalarias **RN-001** (correlativo único compuesto inmutable), **RN-005** (máquina de estados determinista) y **RN-003** (integridad referencial mandatoria hacia sedes y técnicos) exigen constraints relacionales rígidos (`FOREIGN KEY`, `CHECK`, `UNIQUE`) y aislamiento ACID inmediato.
3. **NoSQL:** Descartado formalmente por no garantizar consistencia inmediata en áreas de soporte vital.

---

## 2. Control de Concurrencia en Guardias 24/7 (D-01)

1. **Mecanismo:** Bloqueo optimista (*Optimistic Locking*) con columna numérica entera `version INT NOT NULL DEFAULT 1` en la tabla `solicitudes`.
2. **Protocolo:** Toda sentencia de actualización de asignación o cambio de estado ejecuta:
   ```sql
   UPDATE solicitudes 
   SET estado = :nuevo_estado, tecnico_asignado_id = :tecnico_id, version = version + 1, updated_at = CURRENT_TIMESTAMP
   WHERE id = :id AND version = :version_leida;
   ```
3. **Manejo de Colisiones:** Si `filas_afectadas == 0`, se detecta colisión concurrente, revirtiéndose la transacción e informando inmediatamente al usuario sin generar deadlocks en el motor.

---

## 3. Inmutabilidad y Prohibición de Borrado Físico (RN-012, RN-013)

1. **Revocación de Permisos de Borrado:**
   ```sql
   REVOKE DELETE ON ALL TABLES IN SCHEMA public FROM s3ih_app_user;
   GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO s3ih_app_user;
   ```
2. **Esquema Append-Only:**
   - La tabla `solicitudes_historial_estados` registra inalterablemente cada transición con: `solicitud_id`, `estado_anterior`, `estado_nuevo`, `causal_motivo`, `usuario_id`, `ip_origen`, `created_at` (UTC).
   - En las tablas de auditoría se revoca incluso el permiso `UPDATE`:
     ```sql
     REVOKE UPDATE, DELETE ON solicitudes_historial_estados, auditoria_eventos FROM s3ih_app_user;
     ```
3. **Retención Legal:** Retención histórica activa de al menos 5 años ($\ge 1.500.000\text{ solicitudes}$).

---

## 4. Idempotencia y Deduplicación (RN-011, D-03)

1. Tabla `transacciones_idempotencia`:
   - Clave primaria: `idempotency_key UUID`.
   - Campos: `usuario_id`, `recurso_tipo`, `request_hash VARCHAR(64)` (SHA-256), `response_status INT`, `response_body JSON/JSONB`, `created_at`, `expires_at` (TTL de 24 horas).
2. Índice único para garantizar que peticiones duplicadas provenientes de conexiones Wi-Fi hospitalarias inestables retornen la respuesta original sin duplicar registros en base de datos.

---

## 5. Almacenamiento Desacoplado de Evidencias Técnicas (D-04)

1. **Prohibición de BLOBs:** Terminantemente prohibido almacenar archivos binarios dentro de tablas de la base de datos relacional (Restricción Técnica 5).
2. **Metadatos en BD:** Tabla `solicitudes_adjuntos` almacenando:
   - `id`, `solicitud_id`, `nombre_archivo_original`, `nombre_archivo_almacenado` (UUID v4), `tipo_mime`, `extension`, `tamano_bytes` ($\le 5.242.880\text{ bytes}$ con constraint `CHECK`), `hash_sha256`, `uri_almacenamiento`, `usuario_id`, `created_at`.
3. **Binarios:** Resguardados en volumen de almacenamiento montado (`/var/s3ih/adjuntos/`) o bucket MinIO/S3.

---

## 6. Desacoplamiento Asíncrono de SLA: Transactional Outbox (D-06)

1. Tabla `outbox_eventos`:
   - Registra eventos transaccionales en el mismo commit de la solicitud: alertas de SLA al 75%, escalamiento jerárquico y despacho de correos SMTP.
2. Polling eficiente por el Worker:
   ```sql
   SELECT id, tipo_evento, payload 
   FROM outbox_eventos 
   WHERE estado = 'PENDIENTE' AND scheduled_at <= CURRENT_TIMESTAMP
   ORDER BY scheduled_at ASC LIMIT 50 
   FOR UPDATE SKIP LOCKED;
   ```

---

## 7. Estrategia de Índices para Consultas ($p95 \le 400\text{ ms}$)

- `idx_solicitudes_bandeja`: `(sede_id, estado, prioridad_asistencial, created_at DESC)`.
- `idx_solicitudes_tecnico`: `(tecnico_asignado_id, estado) WHERE estado IN ('Asignada', 'En Proceso', 'En Espera')`.
- `uq_solicitudes_correlativo`: `(codigo_correlativo) UNIQUE`.
- `idx_solicitudes_sla_activo`: `(sla_fecha_limite ASC) WHERE estado IN ('Registrada', 'Asignada', 'En Proceso')`.

---

## 8. Respaldo y Tolerancia a Fallos (RPO / RTO)

- **RPO:** $< 15\text{ minutos}$ mediante respaldo completo diario a las 02:00 UTC + archivado continuo de WAL/binlogs cada 15 minutos en volumen remoto.
- **RTO:** $< 30\text{ minutos}$ mediante conmutación automatizada a réplica en caliente (*Hot Standby*) en Hospital 2.
- **Sincronización:** Rsync periódico sincronizado entre base de datos y volumen de evidencias adjuntas.
