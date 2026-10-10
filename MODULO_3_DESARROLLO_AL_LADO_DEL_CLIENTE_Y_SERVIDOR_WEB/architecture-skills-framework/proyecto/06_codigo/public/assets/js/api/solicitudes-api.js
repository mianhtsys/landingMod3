// CP-SOL-03..05: Cliente API de Solicitudes Hospitalarias
async function send(method, path, payload = null, extraHeaders = {}) {
  const headers = { ...extraHeaders };
  if (payload) {
    headers['Content-Type'] = 'application/json';
  }

  const res = await fetch(path, {
    method,
    headers: Object.keys(headers).length > 0 ? headers : undefined,
    body: payload ? JSON.stringify(payload) : undefined,
    credentials: 'same-origin',
  });

  let data = null;
  try {
    data = await res.json();
  } catch {
    /* respuesta no JSON */
  }

  if (!res.ok || !data?.success) {
    const err = new Error(data?.error ?? 'Error de conexión con el servidor hospitalario');
    err.status = res.status;
    throw err;
  }
  return data.data;
}

export const listSolicitudes = (filters = {}) => {
  const params = new URLSearchParams();
  if (filters.sede_id) params.set('sede_id', filters.sede_id);
  if (filters.estado) params.set('estado', filters.estado);
  if (filters.prioridad_asistencial) params.set('prioridad_asistencial', filters.prioridad_asistencial);
  if (filters.es_expedita !== undefined && filters.es_expedita !== '') {
    params.set('es_expedita', filters.es_expedita);
  }
  const qs = params.toString();
  return send('GET', `/api/v1/solicitudes${qs ? '?' + qs : ''}`);
};

export const getSolicitud = (id) => send('GET', `/api/v1/solicitudes/${id}`);

export const createSolicitud = (payload, idempotencyKey = null) => {
  const headers = {};
  if (idempotencyKey) {
    headers['Idempotency-Key'] = idempotencyKey;
  }
  return send('POST', '/api/v1/solicitudes', payload, headers);
};

export const createSolicitudExpedita = (payload, idempotencyKey = null) => {
  const headers = {};
  if (idempotencyKey) {
    headers['Idempotency-Key'] = idempotencyKey;
  }
  return send('POST', '/api/v1/solicitudes/expedita', payload, headers);
};

export const transicionarSolicitud = (id, payload) =>
  send('POST', `/api/v1/solicitudes/${id}/transiciones`, payload);

export const getHistorialSolicitud = (id) =>
  send('GET', `/api/v1/solicitudes/${id}/historial`);
