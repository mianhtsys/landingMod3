# Paso 10 — Versionamiento de Esquema y Estrategia de Migraciones (S3IH)

**Workflow:** `02_database_workflow` — Paso 10 (Artefacto 2 de 2)  
**Skills utilizados:** `database-schema-designer`, `databases` (instalados en `.agents/skills/`)  
**Proyecto:** Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH / Red de 4 hospitales)  
**Entradas:**  
- `proyecto/05_base_datos/05_modelo_fisico/modelo_fisico.md`  
- `proyecto/05_base_datos/06_integridad/integridad.md`  
- `proyecto/05_base_datos/07_seguridad/seguridad.md`  
- `proyecto/04_decisiones/decisiones_base_datos.md`  
- `proyecto/01_requisitos/RNF.md` (`RNF-020`, `RNF-060`, `RNF-062`)  

**Objetivo:** Definir la política de evolución controlada del esquema de base de datos hospitalario mediante migraciones versionadas y reproducibles, garantizando cero tiempo de inactividad asistencial (Zero Downtime), trazabilidad de cambios y prevención de derivas de esquema (*schema drift*).

---

## 1. Principios Rectores de Versionamiento de Esquema en Salud

La operación hospitalaria 24/7 en áreas de soporte vital (urgencias, quirófanos, UCI) impone directrices de evolución estrictas:

1. **Prohibición Terminante de DDL Manual en Producción:**  
   Queda estrictamente prohibido ejecutar sentencias `CREATE`, `ALTER` o `DROP` de forma manual o interactiva en los servidores hospitalarios. Todo cambio estructural debe estar codificado en un script de migración versionado en el repositorio.
2. **Fuente Única de Verdad (`Source of Truth`):**  
   Los scripts SQL versionados en `proyecto/05_base_datos/13_sql/migraciones/` constituyen la única fuente de verdad del diseño físico.
3. **Numeración Monotónica e Inmutable:**  
   Cada migración recibe un identificador secuencial ascendente y descriptivo (ej. `V001__esquema_inicial_s3ih.sql`, `V002__indices_optimizacion_sla.sql`). Una vez aplicada una migración en producción, su archivo es inmutable; cualquier corrección se realiza mediante una nueva migración aditiva.
4. **Verificación Automática de Checksum:**  
   Cada migración ejecutada almacena su hash SHA-256 en la tabla de control del motor para impedir alteraciones retroactivas de scripts ejecutados.

---

## 2. Patrón de Despliegue con Cero Tiempo de Inactividad (Expand / Contract)

Para evitar la interrupción de las órdenes médicas durante actualizaciones de software (RNF-020: 99.9% de disponibilidad), los cambios estructurales siguen el patrón **Expand and Contract**:

```
FASE 1: EXPANSIÓN (Release N)
---------------------------------------------------------------------------------
- Se añade la nueva columna o tabla sin romper la compatibilidad hacia atrás.
- Si es una columna nueva requerida, se crea como NULLABLE o con DEFAULT seguro.
- La versión actual de la aplicación (v1) continúa operando con normalidad.

FASE 2: TRANSICIÓN Y DESPLIEGUE (Release N + 1)
---------------------------------------------------------------------------------
- Se despliega la nueva versión de la aplicación (v2) que escribe en la nueva estructura.
- Si se requiere migración de datos existentes, se ejecuta un script de backfill por lotes
  pequeños (chunks de 1.000 filas) para no saturar los bloqueos de fila.

FASE 3: CONTRACCIÓN (Release N + 2)
---------------------------------------------------------------------------------
- Una vez verificado que no existen clientes v1 activos, se retira la columna antigua
  o se aplica la restricción definitiva NOT NULL.
```

---

## 3. Matriz de Reversión: Rollback vs. Forward-Fix

En bases de datos transaccionales hospitalarias, la regla de oro es: **un rollback de esquema no debe causar pérdida de datos transaccionales generados durante la incidencia**.

| Tipo de Cambio Estructural | Estrategia de Reversión Recomendada | Protocolo Operativo |
| :--- | :---: | :--- |
| **Adición de Tablas o Vistas** | **Rollback (Down Migration)** | Seguro: `DROP TABLE` de la entidad nueva sin afectar las tablas clínicas operativas existentes. |
| **Adición de Columnas o Índices** | **Rollback (Down Migration)** | Seguro: `ALTER TABLE ... DROP COLUMN` o `DROP INDEX` siempre que la columna no contenga datos críticos nuevos. |
| **Modificación de Tipo de Dato** | **Forward-Fix Obligatorio** | Un rollback podría truncar datos clínicos ingresados durante el periodo de falla. Se emite un script correctivo. |
| **Migración de Datos (Backfill)**| **Forward-Fix Obligatorio** | Los datos no se revierten mediante scripts destructivos; se ejecuta un parche de rectificación controlado. |
| **Eliminación de Columnas (`DROP`)**| **Plan de Contingencia / Restore** | Terminantemente prohibido el borrado sin respaldo previo `mysqldump` / `pg_dump` y autorización de jefatura médica. |

---

## 4. Tabla de Metadatos de Control de Migraciones

El motor de base de datos incorpora la tabla de control `sys_schema_migrations` administrada por el pipeline de despliegue:

```sql
CREATE TABLE sys_schema_migrations (
    version VARCHAR(50) PRIMARY KEY,
    descripcion VARCHAR(255) NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'SQL',
    script_nombre VARCHAR(255) NOT NULL,
    checksum_sha256 VARCHAR(64) NOT NULL,
    ejecutado_por VARCHAR(100) NOT NULL,
    ejecutado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tiempo_ejecucion_ms INT UNSIGNED NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'EXITOSO',
    CONSTRAINT chk_migrations_estado CHECK (estado IN ('EXITOSO', 'FALLIDO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Detección de Deriva de Esquema (*Schema Drift Detection*)

La deriva de esquema ocurre cuando la estructura física en un hospital se desvía inadvertidamente de los modelos versionados.

### Protocolo de Verificación Automatizada (Pre-Deploy Drift Check)
1. Antes de aplicar cualquier despliegue, la herramienta de migración inspecciona el catálogo `information_schema` (`TABLES`, `COLUMNS`, `TABLE_CONSTRAINTS`, `STATISTICS`).
2. Se genera un volcado del catálogo real y se contrasta mediante suma de verificación SHA-256 contra la definición esperada del modelo físico.
3. Si se detectan columnas extras, índices faltantes o constraints alterados, el despliegue se detiene inmediatamente con estado `BLOCKED` y se genera una alerta prioritaria para el DBA.

---

## 6. Criterios de Selección para Herramienta de Migración (Decisión DB-P11)

La herramienta definitiva de ejecución de migraciones en PHP / CI-CD debe satisfacer los siguientes requisitos no negociables:

- [x] Ejecución nativa mediante sentencias SQL estándar sin dependencias de Java (para mantener el stack ligero en contenedor Linux LTS).
- [x] Compatibilidad plena con MySQL 8.x (InnoDB) y PostgreSQL 16+.
- [x] Soporte para transacciones DDL (en motores compatibles) o control estricto de pasos atómicos.
- [x] Cálculo y validación automática de checksums SHA-256 por cada script ejecutado.
- [x] Trazabilidad auditada en `sys_schema_migrations`.
- [x] Integración sencilla con scripts CLI en PHP 8.x puro o utilidades estándar de CI/CD (ej. Phinx / Flyway CLI / Runner nativo de migraciones).

---

## 7. Conclusión y Resumen de Estado

| Requisito de Versionamiento | Estado | Cumplimiento Técnico |
| :--- | :---: | :--- |
| **Trazabilidad DDL** | **APROBADO** | Todo cambio estructural encapsulado en scripts `V{nnn}__*.sql`. |
| **Zero Downtime** | **APROBADO** | Procedimiento formal Expand/Contract en releases de 3 fases. |
| **Seguridad de Datos** | **APROBADO** | Prevalencia de Forward-fix sobre rollbacks destructivos en datos de salud. |
| **Detección de Drift** | **APROBADO** | Verificación pre-deploy obligatoria contra `information_schema`. |
| **Alineación con Gobernanza** | **APROBADO** | Prohibición de despliegue activo hasta `STATUS: APPROVED` en Paso 14. |

---

**Estado del Paso:** Paso 10 COMPLETADO con éxito (ambos artefactos generados).  
**Siguiente Paso:** Paso 11 — Índices y Estrategia de Rendimiento (`proyecto/05_base_datos/10_indices_rendimiento/indices_rendimiento.md`), utilizando `database-schema-designer` y `databases`.
