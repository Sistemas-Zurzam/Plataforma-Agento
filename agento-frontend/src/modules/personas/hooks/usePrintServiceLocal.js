import { useCallback, useEffect, useState } from 'react';

const CLAVE_TOKEN = 'agento_print_service_token';
const URL_BASE = 'http://127.0.0.1:5588';

// Chromium/Brave 141+ exige declarar de forma explícita que estas peticiones
// HTTPS -> HTTP apuntan al propio equipo. Así puede aplicar el permiso
// "Red local" / "loopback" concedido por el usuario y relajar el bloqueo de
// contenido mixto únicamente para este destino local.
const OPCIONES_LOOPBACK = {
  mode: 'cors',
  targetAddressSpace: 'loopback',
};

/**
 * Puente hacia Agento Print Service (servicio PowerShell local, ver
 * agento-print-service/ en la raíz del repo) — permite imprimir el carnet
 * directo a la Epson L8050 (bandeja de tarjeta ID) sin pasar por Epson
 * Photo+. Es enteramente OPCIONAL: si el servicio no está corriendo en esta
 * PC, `disponible` queda en false y el resto del carnet sigue funcionando
 * igual (Imprimir/Descargar PNG por el navegador, sin ningún cambio).
 *
 * El token es un secreto POR MÁQUINA — cada PC con su propia Epson corre su
 * propia instancia del servicio con su propio token (ver README del
 * servicio) — por eso vive en localStorage de ESTE navegador, nunca en el
 * bundle de JS ni en el backend de Agento, y se pide una sola vez la
 * primera vez que se usa "Imprimir en PVC" en esa máquina.
 */
export function usePrintServiceLocal() {
  const [disponible, setDisponible] = useState(false);

  useEffect(() => {
    let cancelado = false;
    fetch(`${URL_BASE}/health`, OPCIONES_LOOPBACK)
      .then((respuesta) => { if (!cancelado && respuesta.ok) setDisponible(true); })
      .catch(() => {});
    return () => { cancelado = true; };
  }, []);

  const enviarTrabajo = useCallback(async (cuerpo) => {
    let token = window.localStorage.getItem(CLAVE_TOKEN);
    if (!token) {
      token = window.prompt(
        'Pega el token que muestra la consola de Agento Print Service en esta PC (solo hace falta una vez por máquina):',
      );
      if (!token) throw new Error('Se necesita el token del servicio de impresión local para continuar.');
      window.localStorage.setItem(CLAVE_TOKEN, token);
    }

    const respuesta = await fetch(`${URL_BASE}/print/carnet`, {
      ...OPCIONES_LOOPBACK,
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Agento-Token': token },
      body: JSON.stringify(cuerpo),
    });

    if (respuesta.status === 401) {
      window.localStorage.removeItem(CLAVE_TOKEN);
      throw new Error('El token guardado ya no es válido — inténtalo de nuevo, se te volverá a pedir.');
    }

    if (!respuesta.ok) {
      const cuerpo = await respuesta.json().catch(() => ({}));
      throw new Error(cuerpo.error ?? 'No se pudo imprimir el carnet.');
    }
  }, []);

  const imprimirEnPvc = useCallback(async (pngDataUrl, { colaboradorId, nombreMostrable, cara }) => {
    return enviarTrabajo({ imagenBase64: pngDataUrl, colaboradorId, nombreMostrable, cara });
  }, [enviarTrabajo]);

  const imprimirDosEnPvc = useCallback(async (carnets) => {
    return enviarTrabajo({ imagenes: carnets });
  }, [enviarTrabajo]);

  return { disponible, imprimirEnPvc, imprimirDosEnPvc };
}
