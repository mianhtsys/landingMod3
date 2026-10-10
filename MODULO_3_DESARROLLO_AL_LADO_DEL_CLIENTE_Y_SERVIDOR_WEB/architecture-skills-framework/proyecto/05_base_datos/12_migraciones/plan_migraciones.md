# Paso 13 — Plan de Migraciones Forward y Rollback (S3IH)

**Workflow:** `02_database_workflow` — Paso 13  
**Skills utilizados:** `database-schema-designer`, `databases` (instalados en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`  
- `proyecto/05_base_datos/06_integridad/integridad.md`  
- `proyecto/05_base_datos/07_seguridad/seguridad.md`  
- `proyecto/05_base_datos/09_versionamiento/versionamiento.md`  
- `proyecto/05_base_datos/10_indices_rendimiento/indices_rendimiento.md`  
- `proyecto/05_base_datos/11_transacciones_concurrencia/transacciones_concurrencia.md`  

**Aviso de Gobernanza:** Este plan define la estrategia secuencial, scripts forward, reversiones down y protocolos de contingencia **antes de ejecutar**. No se ejecutan migraciones ni se altera el entorno físico antes de alcanzar `STATUS: APPROVED` en el Paso 14 (Revisión DBA).

---

## 1. Principios y Reglas de Ejecución

1. **Dependencia Jerárquica de Claves Foráneas (FK):**  
   El orden de ejecución forward respeta estrictamente la jerarquía de integridad referencial (las tablas maestras independientes se crean antes que sus tablas dependientes). Las reversiones (*Down*) se ejecutan en orden inverso exacto.
2. **Inmutabilidad y Checksum:**  
   Cada script de migración se versiona como un archivo SQL independiente con formato de nombre normalizado `V{nnn}__{descripcion}.sql`. Su hash SHA-256 se valida contra la tabla `sys_schema_migrations`.
3. **Mínimo Privilegio en Migraciones:**  
   Las migraciones se ejecutan exclusivamente con la cuenta de despliegue `s3ih_migrator_user`. La cuenta de aplicación regular (`s3ih_app_user`) carece de permisos DDL (`CREATE`, `ALTER`, `DROP`).
4. **Protección de Datos Hospitalarios (Data-Loss Gated):**  
   Terminantemente prohibido aplicar rollbacks destructivos (`DROP TABLE / DROP COLUMN`) en entornos con datos clínicos o transaccionales reales. Si una migración aditiva requiere ajuste tras entrar en producción, se resuelve mediante **Forward-Fix** (script correctivo aditivo posterior).

---

## 2. Secuencia Maestra de Migraciones Forward (V001 a V008)

El despliegue inicial del esquema hospitalario se organiza en 8 migraciones modulares:

| Migración | Nombre del Script | Tablas / Objetos Afectados | Propósito Arquitectónico | Rollback Estrategia (Down) |
| :--- | :--- | :--- | :--- | :--- |
| **V001** | `V001__init_organizacion.sql` | `org_sedes`, `org_servicios`, `org_cuadrillas_guardia` | Estructura física y técnica de las 4 sedes de la red hospitalaria (RF-001, RF-002, RF-004). | `DROP TABLE` en orden inverso: cuadrillas $\rightarrow$ servicios $\rightarrow$ sedes. |
| **V002** | `V002__init_seguridad_rbac.sql` | `sec_roles`, `sec_usuarios`, `org_cuadrilla_miembros` | Identidad institucional, roles RBAC y asignación de personal a cuadrillas de turno (RF-003, RNF-030). | `DROP TABLE`: cuadrilla_miembros $\rightarrow$ usuarios $\rightarrow$ roles. |
| **V003** | `V003__init_catalogos_sla.sql` | `cat_solicitud_tipos`, `cat_causales`, `sla_calendarios`, `sla_dias_inhabiles`, `sla_politicas` | Clasificación tipificada de fallas, causales obligatorias y políticas duales de SLA (RF-010 a RF-014). | `DROP TABLE`: sla_politicas $\rightarrow$ dias_inhabiles $\rightarrow$ calendarios $\rightarrow$ causales $\rightarrow$ solicitud_tipos. |
| **V004** | `V004__init_transaccional_solicitudes.sql` | `sol_solicitudes`, `sol_control_tiempo_sla`, `sol_historial_estados`, `sol_informes_atencion`, `sol_reaperturas`, `sol_reasignaciones`, `sol_bitacora`, `sol_adjuntos` | Núcleo transaccional de órdenes de trabajo, bloqueo optimista `version`, máquina finita y evidencias (RF-020 a RF-060, D-01, D-04). | `DROP TABLE`: hijas transaccionales $\rightarrow$ solicitudes. *(Solo en entornos vacíos)*. |
| **V005** | `V005__init_operaciones_auditoria.sql` | `ops_idempotencia`, `ops_outbox_eventos`, `audit_eventos` | Patrón Transactional Outbox, idempotencia HTTP y pista legal inmutable con encadenamiento SHA-256 (D-03, D-06, RF-072). | `DROP TABLE`: audit_eventos $\rightarrow$ outbox $\rightarrow$ idempotencia. |
| **V006** | `V006__indices_optimizacion_sla.sql` | Índices secundarios en `sol_solicitudes`, `sol_control_tiempo_sla`, `ops_outbox_eventos`, `audit_eventos` | Índices compuestos cubrientes para bandeja asistencial ($p95 < 200\text{ ms}$), polling outbox y barrido de SLA (Paso 11). | `DROP INDEX` individual por nombre sin alterar tablas. Reversible y seguro. |
| **V007** | `V007__seguridad_roles_grants.sql` | Roles de motor `s3ih_app`, `s3ih_worker`, `s3ih_backup` | Segregación formal de privilegios, revocación de `DELETE` (`REVOKE DELETE`) y restricción append-only (Paso 09). | `REVOKE` de permisos y `DROP ROLE` de las cuentas creadas. |
| **V008** | `V008__semillas_catalogos_iniciales.sql` | Datos semilla en `org_sedes`, `sec_roles`, `cat_causales`, `sla_calendarios` | Inicialización de los 4 hospitales (`H1` a `H4`), perfiles RBAC canónicos, causales de suspensión y calendario 24/7. | `DELETE FROM` filtrado específicamente por los códigos de semilla insertados. |

---

## 3. Especificación Detallada de Rollback y Forward-Fix por Escenario

### 3.1 Escenario A: Entornos de Desarrollo / Staging (Bases de Datos Vacías)
- Si una migración falla durante las pruebas automatizadas de CI/CD:
  1. Se ejecuta el script down correspondiente de forma limpia.
  2. Se corrige el archivo forward original.
  3. Se recalcula el checksum y se reejecuta la suite completa.

### 3.2 Escenario B: Entorno de Producción Hospitalario (Con Datos Asistenciales Activos)
- En producción, **los rollbacks destructivos (`DROP`) están terminantemente prohibidos**:
  1. **Migración Aditiva Fallida:** Si un script de índice (`V006`) falla por timeout de bloqueo, el rollback seguro es `DROP INDEX` (aditivo/no destructivo).
  2. **Error de Tipado o Restricción:** Si una columna requiere ajuste tras desplegarse, **no se revierte la versión previa**; se emite una nueva migración `V009__forward_fix_...` que aplique la corrección manteniendo los datos generados durante la guardia.
  3. **Falla Crítica de Integridad:** En caso de corrupción severa, se activa el protocolo de contingencia mediante restauración a un punto en el tiempo (PITR) con el archivo de respaldo diario y el streaming de WAL/binlog (RPO $< 15$ min, RTO $< 30$ min).

---

## 4. Gestión de Bloqueos en Migraciones de Producción (Online DDL)

Para garantizar la disponibilidad continua 24/7 (RNF-020: 99.9% de uptime) en centros asistenciales:

1. **Creación de Índices sin Bloqueo:**
   - En MySQL 8.x (InnoDB):
     ```sql
     CREATE INDEX idx_sol_solicitudes_bandeja 
         ON sol_solicitudes (sede_id, estado, prioridad_asistencial, fecha_radicacion_utc DESC)
         ALGORITHM = INPLACE, LOCK = NONE;
     ```
   - En PostgreSQL 16+:
     ```sql
     CREATE INDEX CONCURRENTLY idx_sol_solicitudes_bandeja 
         ON sol_solicitudes (sede_id, estado, prioridad_asistencial, fecha_radicacion_utc DESC);
     ```
2. **Ventanas de Mantenimiento:**
   - La adición de tablas y catálogos no requiere ventana de mantenimiento (operación instantánea en metadatos).
   - Cualquier alteración masiva o reestructuración de tablas de más de $500.000$ filas debe programarse durante la ventana de menor afluencia asistencial (madrugada entre 03:00 y 04:30 hora local).

---

## 5. Protocolo de Contingencia ante Migración Interrumpida

Si el proceso de despliegue sufre una caída de red o aborta a mitad de una migración:

```
[ DETECCIÓN DE FALLO EN MIGRACIÓN ]
               │
               ▼
   1. DETENER EL PIPELINE CI/CD (Prohibido reintentar a ciegas)
               │
               ▼
   2. CONSULTAR TABLA sys_schema_migrations (Estado 'FALLIDO' registrado)
               │
               ▼
   3. INSPECCIÓN DE DERIVA (Comparar information_schema vs V*.sql)
               │
               ▼
   4. EVALUAR ESTADO DE LA TRANSACCIÓN:
      ├── En PostgreSQL: Transacción DDL revertida automáticamente (Rollback limpio)
      └── En MySQL: Sentencias DDL no transaccionales; identificar objetos creados
               │
               ▼
   5. APLICAR SCRIPT DE LIMPIEZA ESPECÍFICO MANUAL O FORWARD-FIX POR DBA
               │
               ▼
   6. RE-EJECUTAR SUITE DE VERIFICACIÓN (Paso 18) TRAS AUTORIZACIÓN FORMAL
```

---

## 6. Checklist de Verificación Pre-Migración

Antes de autorizar la aplicación de migraciones en cualquier entorno:

- [ ] Respaldo completo verificado y disponible (`mysqldump` / `pg_dump` con suma SHA-256).
- [ ] Espacio libre en disco verificado en el servidor host ($\ge 20\text{ GB}$ libres para logs binarios y crecimiento).
- [ ] Checksum SHA-256 de todos los scripts `V*.sql` validado contra el commit de Git.
- [ ] Variables de entorno `.env` cargadas correctamente (sin credenciales hardcodeadas).
- [ ] Scripts down y planes de contingencia documentados y revisados por el DBA.

---

**Estado del Paso:** Paso 13 COMPLETADO con éxito.  
**Siguiente Paso:** Paso 14 — Revisión DBA y Emisión de Dictamen (`proyecto/05_base_datos/15_reportes/revision_dba.md`), utilizando `database-schema-designer` y `databases`.
