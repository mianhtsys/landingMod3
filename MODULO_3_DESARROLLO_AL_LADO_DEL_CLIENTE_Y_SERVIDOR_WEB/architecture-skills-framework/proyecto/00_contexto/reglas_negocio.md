# Reglas de negocio

- **RN-001. Identificador único inmutable y correlativo por sede:** Toda solicitud registrada en el sistema debe recibir un identificador correlativo único e inalterable generado al momento de la radicación, compuesto por el código institucional de la sede hospitalaria, el año de registro y un número secuencial continuo (ej. `H1-2026-000452`).

- **RN-002. Datos mínimos obligatorios de radicación:** Ninguna solicitud puede ser radicada sin contar con los siguientes datos validados: sede hospitalaria de origen, servicio o dependencia solicitante, ubicación física detallada (piso/sala/quirófano/cama), tipo de solicitud tipificada, nivel de prioridad asistencial inicial, descripción técnica del requerimiento e identificación del usuario solicitante.

- **RN-003. Asignación obligatoria para inicio de atención:** Toda solicitud en estado "Asignada" o "En Proceso" debe tener asociado al menos un técnico responsable acreditado o un equipo de guardia en turno activo dentro de la sede y servicio resolutor correspondiente.

- **RN-004. Clasificación y priorización asistencial diferenciada:**
  - **Urgente Asistencial / Vital:** Aplica a fallas de soporte vital en áreas críticas (Quirófanos, UCI, Urgencias, Neonatología). Requiere asignación prioritaria inmediata y cómputo de SLA ininterrumpido 24 horas al día, 7 días a la semana (24/7).
  - **Alta:** Aplica a incidencias que comprometen la continuidad del servicio asistencial sin riesgo vital inmediato (ej. laboratorio de urgencias, aire medicinal en salas de hospitalización). SLA computado bajo calendario continuo extendido.
  - **Media:** Incidencias operativas estándar que admiten resolución programada sin detener la atención a pacientes. SLA computado bajo calendario hábil administrativo.
  - **Baja:** Mantenimientos preventivos menores, traslados de mobiliario o mejoras programables. SLA computado exclusivamente en horario hábil administrativo.

- **RN-005. Máquina de estados determinista y transiciones válidas:** El ciclo de vida de una solicitud debe ajustarse estrictamente al siguiente diagrama de transiciones permitidas:
  - `Registrada` $\rightarrow$ `Asignada`, `Rechazada` (con motivo obligatorio), `Cancelada` (por el solicitante antes de asignación).
  - `Asignada` $\rightarrow$ `En Proceso`, `Reasignada`, `Cancelada` (por el solicitante).
  - `En Proceso` $\rightarrow$ `En Espera` (con motivo justificado y congelamiento de SLA), `Atendida`, `Reasignada`.
  - `En Espera` $\rightarrow$ `En Proceso` (al superarse el motivo de espera, reanudando cómputo de SLA).
  - `Atendida` $\rightarrow$ `Cerrada` (tras confirmación de conformidad o automáticamente tras 48 horas sin objeción), `Reaperturada` (por inconformidad debidamente justificada).
  - `Reaperturada` $\rightarrow$ `En Proceso` (retoma atención técnica con nuevo ciclo de seguimiento).
  - `Cerrada` y `Rechazada` $\rightarrow$ Estados finales terminales inmutables.

- **RN-006. Suspensión regulada de tiempos en estado 'En Espera':** El estado "En Espera" solo puede ser activado por un técnico o supervisor asignado ante causales externas catalogadas (falta de repuesto en bodega, equipo en garantía con tercero técnico, espera de liberación de quirófano). Requiere registrar justificación formal, fecha tentativa de reanudación y pausa de manera automática el conteo del tiempo límite de SLA, registrando el intervalo en la bitácora de auditoría.

- **RN-007. Justificación técnica obligatoria para cierre o atención:** Ninguna solicitud puede transicionar al estado "Atendida" o "Cerrada" sin el registro obligatorio de: detalle de las acciones técnicas ejecutadas, tiempo real de dedicación, condición operativa final del activo o servicio intervenido, y detalle de insumos o repuestos utilizados (o indicación explícita de no requerir repuestos).

- **RN-008. Reasignaciones y cambios con usuario y motivo:** Toda reasignación de responsable o modificación manual de prioridad debe registrar obligatoriamente el usuario que autoriza, el motivo justificado y la estampa de tiempo oficial del servidor, sin permitir ediciones anónimas o desasistidas.

- **RN-009. Causal obligatoria para rechazo o cancelación:** El rechazo técnico de una solicitud (por duplicidad, improcedencia o falta de competencia del área) o la cancelación por parte del usuario solicitante (por cese de la necesidad) requiere registrar una justificación textual obligatoria de al menos 20 caracteres antes de consolidar el cambio de estado.

- **RN-010. Ventana temporal estricta para reapertura:** Una solicitud solo puede ser reabierta por el usuario solicitante o la jefatura del servicio solicitante dentro de una ventana máxima de 48 horas continuas posteriores a la notificación de atención o cierre. Transcurrido dicho plazo, el sistema impedirá la reapertura y exigirá la radicación de una nueva solicitud vinculada como antecedente.

- **RN-011. Idempotencia en radicación y actualizaciones:** Toda operación de radicación de solicitud y cambio de estado debe incluir un token o identificador de idempotencia generado en el origen, garantizando que reintentos de comunicación originados por inestabilidad de la red no generen solicitudes duplicadas ni transiciones de estado repetidas.

- **RN-012. Conservación obligatoria e inmutabilidad (Prohibición de borrado físico):** Las solicitudes, sus transiciones de estado, documentos adjuntos y registros de auditoría no pueden ser eliminados físicamente de la base de datos bajo ninguna circunstancia. Cualquier anulación o baja debe gestionarse exclusivamente mediante estados lógicos terminales con registro inmutable para cumplimiento de auditoría hospitalaria.

- **RN-013. Trazabilidad completa y no repudio:** Toda operación realizada en el sistema debe registrar en una bitácora inmutable: identificador del usuario responsable, dirección IP de origen, estampa de tiempo del servidor en UTC, estado anterior y estado nuevo, garantizando trazabilidad legal y técnica.
