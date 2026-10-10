import os
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import nsdecls, qn

def set_cell_background(cell, fill_hex):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{fill_hex}"/>')
    tcPr.append(shd)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = parse_xml(f'<w:tcMar {nsdecls("w")}><w:top w:w="{top}" w:type="dxa"/><w:bottom w:w="{bottom}" w:type="dxa"/><w:left w:w="{left}" w:type="dxa"/><w:right w:w="{right}" w:type="dxa"/></w:tcMar>')
    tcPr.append(tcMar)

def add_header_styled(doc, text, level=1):
    h = doc.add_heading(text, level=level)
    h.paragraph_format.keep_with_next = True
    h.paragraph_format.space_before = Pt(14)
    h.paragraph_format.space_after = Pt(6)
    for run in h.runs:
        if level == 1:
            run.font.size = Pt(17)
            run.font.bold = True
            run.font.color.rgb = RGBColor(16, 44, 87) # Navy blue
        elif level == 2:
            run.font.size = Pt(13)
            run.font.bold = True
            run.font.color.rgb = RGBColor(28, 80, 148)
        elif level == 3:
            run.font.size = Pt(11)
            run.font.bold = True
            run.font.color.rgb = RGBColor(40, 116, 166)
    return h

def add_callout(doc, text, title="NOTA"):
    tbl = doc.add_table(rows=1, cols=1)
    tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
    cell = tbl.cell(0, 0)
    set_cell_background(cell, "F0F4F8")
    set_cell_margins(cell, top=140, bottom=140, left=200, right=200)
    
    # Left border highlight
    tcPr = cell._tc.get_or_add_tcPr()
    borders = parse_xml(f'<w:tcBorders {nsdecls("w")}><w:left w:val="single" w:sz="24" w:space="0" w:color="102C57"/><w:top w:val="none"/><w:right w:val="none"/><w:bottom w:val="none"/></w:tcBorders>')
    tcPr.append(borders)
    
    p = cell.paragraphs[0]
    p.paragraph_format.space_before = Pt(2)
    p.paragraph_format.space_after = Pt(2)
    r_title = p.add_run(f"[{title}] ")
    r_title.bold = True
    r_title.font.color.rgb = RGBColor(16, 44, 87)
    r_text = p.add_run(text)
    r_text.font.size = Pt(9.5)
    r_text.font.color.rgb = RGBColor(40, 40, 40)
    doc.add_paragraph().paragraph_format.space_after = Pt(4)

def add_code_block(doc, code_text):
    tbl = doc.add_table(rows=1, cols=1)
    tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
    cell = tbl.cell(0, 0)
    set_cell_background(cell, "2B303A")
    set_cell_margins(cell, top=120, bottom=120, left=180, right=180)
    
    p = cell.paragraphs[0]
    p.paragraph_format.space_before = Pt(0)
    p.paragraph_format.space_after = Pt(0)
    r = p.add_run(code_text.strip())
    r.font.name = "Consolas"
    r.font.size = Pt(8.5)
    r.font.color.rgb = RGBColor(240, 240, 240)
    
    p_after = doc.add_paragraph()
    p_after.paragraph_format.space_after = Pt(4)

def format_table_headers(table, headers, col_widths=None):
    hdr_row = table.rows[0]
    for i, title in enumerate(headers):
        cell = hdr_row.cells[i]
        set_cell_background(cell, "102C57")
        set_cell_margins(cell, top=120, bottom=120, left=140, right=140)
        p = cell.paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        r = p.add_run(title)
        r.bold = True
        r.font.size = Pt(9.5)
        r.font.color.rgb = RGBColor(255, 255, 255)
        if col_widths and i < len(col_widths):
            cell.width = Inches(col_widths[i])

def format_table_data(table, data, col_widths=None):
    for r_idx, row_data in enumerate(data):
        row = table.rows[r_idx + 1]
        bg_color = "F9FBFD" if r_idx % 2 == 1 else "FFFFFF"
        for c_idx, val in enumerate(row_data):
            cell = row.cells[c_idx]
            set_cell_background(cell, bg_color)
            set_cell_margins(cell, top=90, bottom=90, left=120, right=120)
            p = cell.paragraphs[0]
            p.paragraph_format.space_before = Pt(1)
            p.paragraph_format.space_after = Pt(1)
            r = p.add_run(str(val))
            r.font.size = Pt(9)
            if col_widths and c_idx < len(col_widths):
                cell.width = Inches(col_widths[c_idx])

def build_word_document(output_path):
    doc = Document()
    
    # Page setup (Letter, margins 1 inch)
    for section in doc.sections:
        section.top_margin = Inches(1.0)
        section.bottom_margin = Inches(1.0)
        section.left_margin = Inches(1.0)
        section.right_margin = Inches(1.0)
        section.different_first_page_header_footer = True
        
        # Header & Footer for normal pages
        header = section.header
        hp = header.paragraphs[0]
        hp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        hr = hp.add_run("Módulo 3: Desarrollo al Lado del Cliente y Servidor Web | Maestría en Ingeniería de Software")
        hr.font.size = Pt(8)
        hr.font.color.rgb = RGBColor(120, 120, 120)
        
        footer = section.footer
        fp = footer.paragraphs[0]
        fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        fr = fp.add_run("Documento Académico Integral — S3IH & Altiplano Marketplace")
        fr.font.size = Pt(8)
        fr.font.color.rgb = RGBColor(140, 140, 140)

    # =========================================================================
    # PORTADA
    # =========================================================================
    p_inst = doc.add_paragraph()
    p_inst.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_inst.paragraph_format.space_before = Pt(30)
    p_inst.paragraph_format.space_after = Pt(4)
    r = p_inst.add_run("UNIVERSIDAD / PROGRAMA DE POSTGRADO\nMAESTRÍA EN INGENIERÍA DE SOFTWARE Y SISTEMAS INFORMÁTICOS")
    r.bold = True
    r.font.size = Pt(12)
    r.font.color.rgb = RGBColor(80, 80, 80)
    
    p_line = doc.add_paragraph()
    p_line.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r_l = p_line.add_run("____________________________________________________________")
    r_l.font.color.rgb = RGBColor(16, 44, 87)
    
    p_mod = doc.add_paragraph()
    p_mod.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_mod.paragraph_format.space_before = Pt(20)
    r_mod = p_mod.add_run("MÓDULO 3: DESARROLLO AL LADO DEL CLIENTE Y SERVIDOR WEB")
    r_mod.bold = True
    r_mod.font.size = Pt(13)
    r_mod.font.color.rgb = RGBColor(28, 80, 148)

    p_title = doc.add_paragraph()
    p_title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_title.paragraph_format.space_before = Pt(25)
    p_title.paragraph_format.space_after = Pt(25)
    r_title = p_title.add_run("PORTAFOLIO DE ENTREGAS Y MEMORIA TÉCNICA DEL MÓDULO:\nCASOS DE ESTUDIO, PRÁCTICAS Y TRABAJO FINAL")
    r_title.bold = True
    r_title.font.size = Pt(18)
    r_title.font.color.rgb = RGBColor(16, 44, 87)

    p_sub = doc.add_paragraph()
    p_sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_sub.paragraph_format.space_after = Pt(80)
    r_sub = p_sub.add_run("1. Caso de Estudio 1: Validación de RF/RNF Mediante Agentes\n"
                          "2. Caso de Estudio 2: Validación de Arquitectura de Software\n"
                          "3. Práctica Nº 1: Diseño e Implementación de Base de Datos\n"
                          "4. Práctica Nº 2: Desarrollo Backend y Desarrollo de API REST\n"
                          "5. Trabajo Final: Desarrollo de Landing Page Funcional (Altiplano Marketplace)")
    r_sub.font.size = Pt(11)
    r_sub.font.color.rgb = RGBColor(60, 60, 60)

    p_meta = doc.add_paragraph()
    p_meta.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r_meta = p_meta.add_run("Estudiante: Maestrando en Ingeniería de Software\n"
                           "Docente / Asesor: Módulo 3\n"
                           "Fecha: Octubre 2026")
    r_meta.font.size = Pt(10.5)
    r_meta.bold = True

    doc.add_page_break()

    # =========================================================================
    # SECCIÓN 1: CASO DE ESTUDIO 1
    # =========================================================================
    add_header_styled(doc, "CASO DE ESTUDIO 1: VALIDACIÓN DE REQUERIMIENTOS FUNCIONALES Y NO FUNCIONALES MEDIANTE AGENTES", level=1)
    
    p = doc.add_paragraph()
    p.add_run("El objetivo de esta actividad consiste en redactar los Requerimientos Funcionales (RF) y No Funcionales (RNF) del "
              "Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH), someterlos al análisis crítico de un agente especializado "
              "(@requirements-analyst) y determinar de forma justificada si cada observación es válida o descartable de acuerdo al contexto real.")
    
    add_header_styled(doc, "1.1. Especificación Inicial de Requerimientos (Propuesta del Estudiante)", level=2)
    
    # Tabla RF
    t_rf = doc.add_table(rows=6, cols=3)
    t_rf.alignment = WD_TABLE_ALIGNMENT.CENTER
    headers_rf = ["Código", "Requerimiento Funcional (RF)", "Descripción Clínica y Operativa"]
    format_table_headers(t_rf, headers_rf, [1.0, 2.2, 3.3])
    data_rf = [
        ["RF-01", "Gestión Multisede y Áreas", "Administrar las 4 sedes de la red hospitalaria y registrar sus servicios asistenciales y técnicos resolutores."],
        ["RF-02", "Control de Acceso Basado en Roles (RBAC)", "Autenticación segura para 5 perfiles: Administrador, Personal Clínico, Técnico de Guardia, Supervisor y Jefatura."],
        ["RF-03", "Radicación y Clasificación de Solicitudes", "Registro asistencial de incidentes clasificando prioridad (Crítica, Alta, Media, Baja) y bandera de Soporte Vital."],
        ["RF-04", "Máquina de Estados Finita (FSM)", "Gobernar el ciclo de vida: RADICADA -> EN_EVALUACION -> ASIGNADA -> EN_ATENCION -> RESUELTA -> CERRADA."],
        ["RF-05", "Motor de SLAs y Alertas Asíncronas", "Monitoreo continuo de tiempos MTTA/MTTR, disparando alertas automáticas al 75% y escalamiento al 100% de vencimiento."]
    ]
    format_table_data(t_rf, data_rf, [1.0, 2.2, 3.3])
    
    p_sp1 = doc.add_paragraph()
    p_sp1.paragraph_format.space_before = Pt(8)

    # Tabla RNF
    t_rnf = doc.add_table(rows=5, cols=3)
    t_rnf.alignment = WD_TABLE_ALIGNMENT.CENTER
    headers_rnf = ["Código", "Tipo de RNF", "Especificación Técnica"]
    format_table_headers(t_rnf, headers_rnf, [1.0, 1.8, 3.7])
    data_rnf = [
        ["RNF-01", "Disponibilidad y Misión Crítica", "Disponibilidad del 99.9% 24/7 en servicios de soporte vital (pabellones quirúrgicos y UCI)."],
        ["RNF-02", "Rendimiento y Tiempo de Respuesta", "Tiempos de respuesta HTTP API p95 < 200 ms y radicación en menos de 1 segundo bajo redes Wi-Fi inestables."],
        ["RNF-03", "Seguridad y Privacidad Zero-PHI", "Prohibición estricta de incluir datos de identificación de pacientes en descripciones técnicas de fallas."],
        ["RNF-04", "Trazabilidad y No Repudio", "Registro append-only inmutable de cada cambio de estado, usuario responsable y estampa de tiempo (cero borrado físico)."]
    ]
    format_table_data(t_rnf, data_rnf, [1.0, 1.8, 3.7])

    add_header_styled(doc, "1.2. Tabla de Validación y Decisión del Estudiante sobre las Observaciones del Agente", level=2)
    
    t_obs = doc.add_table(rows=5, cols=5)
    t_obs.alignment = WD_TABLE_ALIGNMENT.CENTER
    headers_obs = ["Req.", "Observación del Agente", "Validación", "Decisión", "Justificación Técnica del Estudiante"]
    format_table_headers(t_obs, headers_obs, [0.8, 2.0, 0.9, 0.9, 1.9])
    data_obs = [
        ["RF-03", "Recomienda radicación automática vía integración IoT en equipamiento biomédico.", "Descartada", "NO", "Complejidad e inversión inviables para el alcance actual; la prioridad es la captura asistencial manual rápida."],
        ["RF-04", "Sugiere permitir que los técnicos reabran solicitudes cerradas sin supervisión.", "Inválida", "NO", "Vulnera el control de calidad hospitalario; la reapertura debe ser potestad exclusiva de la jefatura o solicitante."],
        ["RNF-02", "Propone soporte de idempotencia (Idempotency-Key) para mitigar reintentos en Wi-Fi.", "Válida", "SÍ", "Excelente recomendación. Evita solicitudes duplicadas de soporte vital ante microcortes de red asistencial."],
        ["RNF-04", "Recomienda revocar el privilegio DELETE en la base de datos para garantizar inmutabilidad.", "Válida", "SÍ", "Crucial para auditorías legales en salud. Se adopta REVOKE DELETE a nivel de base de datos relacional."]
    ]
    format_table_data(t_obs, data_obs, [0.8, 2.0, 0.9, 0.9, 1.9])

    add_header_styled(doc, "1.3. Conclusión del Caso de Estudio 1", level=2)
    p_c1 = doc.add_paragraph()
    p_c1.add_run("El proceso de validación asistida por el agente demostró ser una herramienta de alto valor para robustecer los requisitos no funcionales "
                "(incorporando idempotencia e inmutabilidad estricta), al mismo tiempo que ratificó la necesidad del criterio del ingeniero de software "
                "para descartar sobre-ingeniería innecesaria (como telemetría IoT prematura), preservando el foco asistencial de misión crítica.")

    doc.add_page_break()

    # =========================================================================
    # SECCIÓN 2: CASO DE ESTUDIO 2
    # =========================================================================
    add_header_styled(doc, "CASO DE ESTUDIO 2: VALIDACIÓN DE ARQUITECTURA DE SOFTWARE MEDIANTE AGENTES", level=1)
    
    p2 = doc.add_paragraph()
    p2.add_run("En esta fase se formularon y compararon alternativas de arquitectura de software para el S3IH, sometiendo la decisión al análisis "
               "de los agentes (@architect, @solution-leader) y formalizando la selección en el Registro de Decisiones de Arquitectura (ADR-001).")

    add_header_styled(doc, "2.1. Matriz Comparativa de Alternativas Arquitectónicas", level=2)
    
    t_arq = doc.add_table(rows=4, cols=6)
    t_arq.alignment = WD_TABLE_ALIGNMENT.CENTER
    headers_arq = ["Criterio Técnico", "Alternativa A: Monolito Modular PHP Puro (Seleccionada)", "Alternativa B: Microservicios en Kubernetes", "Alternativa C: Serverless en Cloud Pública"]
    # Adjust cols
    format_table_headers(t_arq, ["Criterio", "Alternativa A (Monolito Modular)", "Alternativa B (Microservicios K8s)", "Alternativa C (Serverless Cloud)"], [1.2, 1.8, 1.8, 1.7])
    data_arq = [
        ["Complejidad Operativa", "Baja: Despliegue en VM/Servidor local con Apache/PHP.", "Muy Alta: Clúster K8s, ingress, service mesh, orquestación.", "Alta: Vendor lock-in, múltiples funciones y gateways."],
        ["Consistencia Transaccional", "Alta: Transacciones ACID nativas en base de datos InnoDB.", "Baja: Consistencia eventual (Sagas, compensaciones complejas).", "Media: Coordinación compleja entre servicios lambda."],
        ["Mantenimiento en Salud", "Excelente: Mantenible por el equipo TI interno del hospital.", "Inviable: Demanda equipo especializado DevOps 24/7.", "Riesgoso: Latencias de arranque en frío (cold starts) en soporte vital."]
    ]
    format_table_data(t_arq, data_arq, [1.2, 1.8, 1.8, 1.7])

    add_header_styled(doc, "2.2. Registro de Decisión de Arquitectura: ADR-001", level=2)
    add_callout(doc, "ADR-001: Adopción de Monolito Modular en PHP 8.2+ Puro con MySQL 8.x (InnoDB)\n"
                     "Decisión: Se descartan los microservicios y Kubernetes debido a que la red hospitalaria opera en infraestructura local "
                     "on-premise y requiere consistencia inmediata ACID para las fallas de soporte vital en quirófanos. "
                     "El monolito modular garantiza bajo consumo de recursos, cero fricción de mantenimiento y máxima confiabilidad.",
                     title="DECISIÓN ARQUITECTÓNICA APROBADA")

    add_header_styled(doc, "2.3. Archivos del Repositorio para Capturas de Evidencia (Antes de Codificar)", level=2)
    p_files = doc.add_paragraph()
    p_files.add_run("Para evidenciar que la arquitectura se validó rigurosamente antes de iniciar la programación, se extrajeron evidencias de:\n"
                    "• proyecto/03_resultados/01_requirements_analysis.md: Análisis de requisitos del agente.\n"
                    "• proyecto/03_resultados/02_decision_scope.md: Alcance y límites del sistema.\n"
                    "• proyecto/03_resultados/03_architecture_options.md: Comparativa de opciones de diseño.\n"
                    "• proyecto/03_resultados/04_database_analysis.md: Evaluación de estrategias de persistencia.\n"
                    "• proyecto/03_resultados/08_recomendacion_final.md: Aprobación formal y ADR-001 consolidado.")

    add_header_styled(doc, "2.4. Conclusión del Caso de Estudio 2", level=2)
    p_c2 = doc.add_paragraph()
    p_c2.add_run("La evaluación arquitectónica guiada por agentes previno la adopción de arquitecturas sobre-dimensionadas y costosas (microservicios), "
                "garantizando que la solución técnica seleccionada responda con máxima fidelidad a la capacidad operativa real de la infraestructura hospitalaria.")

    doc.add_page_break()

    # =========================================================================
    # SECCIÓN 3: PRÁCTICA Nº 1 - DISEÑO DE BASE DE DATOS
    # =========================================================================
    add_header_styled(doc, "PRÁCTICA Nº 1: DISEÑO E IMPLEMENTACIÓN DE BASE DE DATOS", level=1)
    
    p3 = doc.add_paragraph()
    p3.add_run("Se implementó una base de datos relacional robusta en MySQL 8.x InnoDB (`s3ih_db`) a partir de la trazabilidad estricta de requerimientos. "
               "El agente especializado (@database-specialist) generó las migraciones versionadas V001 a V008.")

    add_header_styled(doc, "3.1. Migraciones y Estructura Relacional Generada", level=2)
    
    t_db = doc.add_table(rows=9, cols=3)
    t_db.alignment = WD_TABLE_ALIGNMENT.CENTER
    format_table_headers(t_db, ["Migración", "Entidad / Tabla", "Función en el Sistema Hospitalario"], [1.2, 1.8, 3.5])
    data_db = [
        ["V001", "org_sedes / org_servicios", "Estructura multisede de las 4 clínicas y mapeo de servicios clínicos y técnicos."],
        ["V002", "sec_usuarios / sec_roles", "Gestión de identidad, credenciales con Argon2id y permisos RBAC en JSON."],
        ["V003", "cat_categorias / cat_sla_politicas", "Catálogos de especialidades de falla y definición de tiempos MTTA/MTTR."],
        ["V004", "sol_solicitudes", "Tabla central con código inmutable, soporte vital y columna de versión para concurrencia."],
        ["V005", "sol_historial_estados", "Pista de auditoría (Audit Trail) inmutable append-only sin borrado físico."],
        ["V006", "ops_outbox_eventos", "Transactional Outbox para publicación desacoplada de eventos hacia el motor de SLAs."],
        ["V007", "ops_idempotencia", "Control de claves de idempotencia UUID para tolerar desconexiones de red asistencial."],
        ["V008", "Semillas y Catálogos", "Población inicial de sedes, servicios clínicos, usuarios y políticas de SLA."]
    ]
    format_table_data(t_db, data_db, [1.2, 1.8, 3.5])

    add_header_styled(doc, "3.2. Fragmento DDL Clave Generado por el Agente (DDL V004 - Concurrencia e Inmutabilidad)", level=2)
    sql_sample = (
        "CREATE TABLE sol_solicitudes (\n"
        "    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
        "    codigo_correlativo VARCHAR(32) NOT NULL UNIQUE,\n"
        "    sede_id INT UNSIGNED NOT NULL,\n"
        "    servicio_origen_id INT UNSIGNED NOT NULL,\n"
        "    servicio_destino_id INT UNSIGNED NOT NULL,\n"
        "    categoria_id INT UNSIGNED NOT NULL,\n"
        "    solicitante_id BIGINT UNSIGNED NOT NULL,\n"
        "    nivel_prioridad ENUM('BAJA','MEDIA','ALTA','CRITICA') NOT NULL,\n"
        "    es_soporte_vital TINYINT(1) NOT NULL DEFAULT 0,\n"
        "    descripcion_falla TEXT NOT NULL,\n"
        "    estado_actual VARCHAR(32) NOT NULL DEFAULT 'RADICADA',\n"
        "    version INT UNSIGNED NOT NULL DEFAULT 1,\n"
        "    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n"
        "    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
        "    INDEX idx_sol_estado_prioridad (estado_actual, nivel_prioridad),\n"
        "    INDEX idx_sol_sede_fecha (sede_id, creado_en),\n"
        "    CONSTRAINT fk_sol_sede FOREIGN KEY (sede_id) REFERENCES org_sedes(id)\n"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n"
        "-- Regla de Inmutabilidad (Zero-Physical-Delete):\n"
        "REVOKE DELETE ON s3ih_db.sol_solicitudes FROM 'app_user'@'%';\n"
        "REVOKE DELETE ON s3ih_db.sol_historial_estados FROM 'app_user'@'%';"
    )
    add_code_block(doc, sql_sample)

    add_header_styled(doc, "3.3. Conclusión de la Práctica Nº 1", level=2)
    p_c3 = doc.add_paragraph()
    p_c3.add_run("La implementación de la base de datos para el S3IH demostró cómo la intervención guiada de un agente especializado permite traducir "
                "rigurosamente los requerimientos funcionales clínicos y los atributos de calidad (ACID, concurrencia optimista, inmutabilidad y resiliencia) "
                "en un esquema relacional normalizado y de alto rendimiento. Mediante la generación y ejecución secuencial de las migraciones SQL (V001 a V008), "
                "se consolidaron mecanismos críticos como la trazabilidad estricta y pistas de auditoría append-only sin borrado físico (REVOKE DELETE), "
                "el desacoplamiento transaccional mediante Transactional Outbox para el motor de SLA, y la protección de datos sensibles con cumplimiento Zero-PHI; "
                "garantizando una persistencia robusta, segura y auditable capaz de soportar la operación ininterrumpida de soporte vital en las cuatro sedes hospitalarias.")

    doc.add_page_break()

    # =========================================================================
    # SECCIÓN 4: PRÁCTICA Nº 2 - DESARROLLO BACKEND Y DESARROLLO DE API
    # =========================================================================
    add_header_styled(doc, "PRÁCTICA Nº 2: DESARROLLO BACKEND Y DESARROLLO DE API REST", level=1)
    
    p4 = doc.add_paragraph()
    p4.add_run("A partir del requerimiento RF-004 (Radicación de Solicitudes) y RF-005 (Transiciones de Estado FSM), se implementó la arquitectura "
               "backend completa y los endpoints de la API REST utilizando PHP puro con el patrón MVC orientado al dominio y persistencia PDO.")

    add_header_styled(doc, "4.1. Endpoints de la API Implementados", level=2)
    
    t_api = doc.add_table(rows=5, cols=5)
    t_api.alignment = WD_TABLE_ALIGNMENT.CENTER
    format_table_headers(t_api, ["Método", "Endpoint", "Acción Funcional", "Operación SQL", "Código HTTP"], [0.8, 1.8, 2.0, 1.1, 0.8])
    data_api = [
        ["POST", "/api/solicitudes", "Radicación de requerimiento con validación Zero-PHI.", "INSERT (transaccional)", "201 Created"],
        ["GET", "/api/solicitudes", "Listado paginado de solicitudes por sede y estado.", "SELECT (con JOINs)", "200 OK"],
        ["GET", "/api/solicitudes/{id}", "Detalle técnico y línea de tiempo (Audit Trail).", "SELECT (id + historial)", "200 OK"],
        ["PUT", "/api/solicitudes/{id}/estado", "Transición FSM con concurrencia optimista (version).", "UPDATE + INSERT", "200 OK / 409"]
    ]
    format_table_data(t_api, data_api, [0.8, 1.8, 2.0, 1.1, 0.8])

    add_header_styled(doc, "4.2. Implementación de Acceso a Datos Transaccional (SolicitudRepository.php)", level=2)
    php_code = (
        "public function insertarSolicitudConHistorial(array $solicitud, array $historial, array $outbox): int {\n"
        "    $this->db->beginTransaction();\n"
        "    try {\n"
        "        // 1. Operación INSERT en sol_solicitudes\n"
        "        $stmt = $this->db->prepare(\"INSERT INTO sol_solicitudes (\n"
        "            codigo_correlativo, sede_id, servicio_origen_id, servicio_destino_id,\n"
        "            categoria_id, solicitante_id, nivel_prioridad, es_soporte_vital,\n"
        "            descripcion_falla, estado_actual, version\n"
        "        ) VALUES (:codigo, :sede, :so, :sd, :cat, :usr, :prio, :vital, :desc, 'RADICADA', 1)\");\n"
        "        $stmt->execute([...]);\n"
        "        $id = (int)$this->db->lastInsertId();\n\n"
        "        // 2. Operación INSERT en sol_historial_estados (Audit Trail inmutable)\n"
        "        $stmtHist = $this->db->prepare(\"INSERT INTO sol_historial_estados (\n"
        "            solicitud_id, estado_anterior, estado_nuevo, usuario_responsable_id, observaciones_cambio\n"
        "        ) VALUES (:id, NULL, 'RADICADA', :usr, 'Radicación inicial asistencial')\");\n"
        "        $stmtHist->execute([...]);\n\n"
        "        // 3. Operación INSERT en ops_outbox_eventos\n"
        "        $stmtOut = $this->db->prepare(\"INSERT INTO ops_outbox_eventos (...) VALUES (...)\");\n"
        "        $stmtOut->execute([...]);\n\n"
        "        $this->db->commit();\n"
        "        return $id;\n"
        "    } catch (\\Throwable $e) {\n"
        "        $this->db->rollBack();\n"
        "        throw $e;\n"
        "    }\n"
        "}"
    )
    add_code_block(doc, php_code)

    add_header_styled(doc, "4.3. Conclusión del Desarrollo Backend", level=2)
    p_c4 = doc.add_paragraph()
    p_c4.add_run("La implementación del backend y la API REST para el Sistema de Seguimiento de Solicitudes Internas Hospitalarias (S3IH) demostró "
                "que una arquitectura en PHP puro estructurada bajo el patrón MVC y orientada al dominio permite desacoplar limpiamente la capa de "
                "presentación de la lógica de negocio y la persistencia relacional sin incurrir en la complejidad o sobrecarga de frameworks externos. "
                "A través de los endpoints desarrollados (POST, GET, PUT sobre /api/solicitudes), se validó el ciclo de vida completo de las peticiones "
                "clínicas: recepción de datos con sanitización Zero-PHI, control de concurrencia optimista (version) para evitar pérdidas de información "
                "entre guardias médicas simultáneas, transiciones gobernadas por una máquina de estados finita (FSM) y ejecución atómica multirregistro "
                "mediante PDO en transacciones ACID. La posterior ejecución y aprobación del 100% de las pruebas automatizadas de integración "
                "(test_cp_sol_04.php) certificó la robustez de las operaciones de lectura y modificación de datos, consolidando una solución de backend "
                "resiliente, auditable y directamente alineada con los requerimientos de misión crítica del entorno hospitalario.")

    doc.add_page_break()

    # =========================================================================
    # SECCIÓN 5: TRABAJO FINAL - LANDING PAGE FUNCIONAL
    # =========================================================================
    add_header_styled(doc, "TRABAJO FINAL: DESARROLLO DE UNA LANDING PAGE FUNCIONAL", level=1)
    
    p5 = doc.add_paragraph()
    p5.add_run("El Trabajo Final consistió en el desarrollo y despliegue de una Landing Page comercial plenamente funcional denominada "
               "\"Altiplano Marketplace\", orientada a la promoción de productos regionales en el mercado boliviano. La solución supera "
               "el nivel de una página estática integrando consumo dinámico de base de datos MySQL mediante PHP puro, diseño responsivo "
               "adaptable y microinteracciones del lado del cliente.")

    add_header_styled(doc, "5.1. Matriz de Cumplimiento de Requisitos del Trabajo Final", level=2)
    
    t_req = doc.add_table(rows=9, cols=3)
    t_req.alignment = WD_TABLE_ALIGNMENT.CENTER
    format_table_headers(t_req, ["Requisito Solicitado", "Implementación Técnica en Altiplano Marketplace", "Evidencia de Código"], [1.5, 3.2, 1.8])
    data_req = [
        ["1. Funcionalidad Integral", "Carga y visualización de 7 categorías y 70 productos con precios demostrativos en Bs.", "public/index.php"],
        ["2. Diseño Claro y Coherente", "Arquitectura visual sobria con jerarquía tipográfica, tarjetas uniformes y contrastes accesibles.", "public/assets/css/landing.css"],
        ["3. Adaptabilidad Multidispositivo", "Diseño mobile-first validado sin desbordamiento (0 px overflow) en anchos de 360px a 1440px.", "docs/validacion_final.md"],
        ["4. Comunicación del Propósito", "Sección Hero con propuesta de valor clara, avisos comerciales transparentes y CTAs directos.", "app/Views/sections/hero.php"],
        ["5. Interacción del Usuario", "Navegación fluida por anclas y sombra reactiva del encabezado optimizada con requestAnimationFrame.", "public/assets/js/landing.js"],
        ["6. Procesamiento del Servidor", "Composición dinámica modular en Front Controller, inyección de dependencias y escape seguro con htmlspecialchars.", "public/index.php"],
        ["7. Gestión de Fuente de Datos", "Persistencia relacional en MySQL con tablas landing_categoria y landing_producto gestionadas vía PDO.", "app/Repositories/"],
        ["8. Comunicación Cliente-Servidor", "Peticiones HTTP resueltas por el servidor generando renderizado dinámico e hidratación del catálogo.", "app/Services/LandingCatalogoService.php"]
    ]
    format_table_data(t_req, data_req, [1.5, 3.2, 1.8])

    add_header_styled(doc, "5.2. Métricas de Calidad y Rendimiento Web Alcanzadas", level=2)
    
    t_met = doc.add_table(rows=5, cols=3)
    t_met.alignment = WD_TABLE_ALIGNMENT.CENTER
    format_table_headers(t_met, ["Métrica Web", "Resultado Obtenido", "Estándar / Umbral Recomendado"], [2.0, 2.0, 2.5])
    data_met = [
        ["Largest Contentful Paint (LCP)", "500 ms", "< 2500 ms (Excelente - Rápido)"],
        ["Cumulative Layout Shift (CLS)", "0.00", "< 0.10 (Estabilidad visual absoluta)"],
        ["Lighthouse Accesibilidad", "100 / 100", "Cumplimiento total directrices WCAG"],
        ["Lighthouse SEO y Semántica", "100 / 100", "Optimización completa para indexación"]
    ]
    format_table_data(t_met, data_met, [2.0, 2.0, 2.5])

    add_header_styled(doc, "5.3. Guía de Capturas de Pantalla para el Informe PDF Final", level=2)
    p_cap = doc.add_paragraph()
    p_cap.add_run("Para completar la entrega del archivo PDF requerido, se deben adjuntar las capturas de pantalla de los siguientes elementos en ejecución:\n"
                  "1. Captura del Encabezado y Sección Hero: Vista principal en http://127.0.0.1:8110 mostrando la identidad de Altiplano Marketplace y los botones de acción.\n"
                  "2. Captura de la Grilla de Categorías: Visualización de las 7 categorías cargadas dinámicamente desde la base de datos relacional.\n"
                  "3. Captura del Catálogo de Productos en Bs: Tarjetas de productos con imagen, título, descripción y precio en moneda boliviana.\n"
                  "4. Captura de Responsividad Móvil (360px): Inspección en Chrome DevTools mostrando la perfecta adaptación en dispositivos móviles.\n"
                  "5. Captura de Consulta en Base de Datos MySQL: Consola o interfaz mostrando los registros de landing_categoria (7) y landing_producto (70).\n"
                  "6. Captura de Auditoría Lighthouse: Puntuaciones de 100 en Accesibilidad y SEO y LCP de 500 ms.")

    add_header_styled(doc, "5.4. Enlace al Repositorio y Ejecución del Proyecto", level=2)
    add_callout(doc, "Repositorio GitHub del Proyecto:\n"
                     "https://github.com/darkpro/MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO\n\n"
                     "Ruta del Código de la Landing Page: proyecto/07_landing/\n"
                     "Comando de Ejecución Local: php -S 127.0.0.1:8110 -t proyecto/07_landing/public",
                     title="REPOSITORIO DE CÓDIGO FUENTE")

    add_header_styled(doc, "5.5. Conclusión General del Módulo", level=2)
    p_c5 = doc.add_paragraph()
    p_c5.add_run("El desarrollo de la Landing Page funcional Altiplano Marketplace integró con éxito todas las competencias clave abordadas en el módulo: "
                "desde el diseño visual centrado en el usuario y la accesibilidad web, hasta el procesamiento estructurado en el servidor mediante PHP puro "
                "y la interacción con bases de datos relacionales a través de PDO. La solución implementada demostró que no es indispensable sobrecargar "
                "una aplicación con dependencias complejas o frameworks pesados para conseguir tiempos de carga excepcionales (LCP 500 ms), diseño adaptable "
                "a cualquier pantalla y una comunicación fluida entre el cliente y el servidor web, entregando un producto digital comercialmente viable, "
                "mantenible y rigurosamente alineado con las buenas prácticas de la ingeniería de software.")

    # Guardar documento
    doc.save(output_path)
    print(f"Documento generado exitosamente en: {output_path}")

if __name__ == "__main__":
    out_file = r"d:\mod3proyectoclone\MAESTRIA_EN_INGENIERIA_DE_SOFTWARE_Y_SISTEMAS_INFORMATICO\MODULO_3_DESARROLLO_AL_LADO_DEL_CLIENTE_Y_SERVIDOR_WEB\architecture-skills-framework\DOCUMENTO_FINAL_MODULO_3_COMPLETO.docx"
    build_word_document(out_file)
