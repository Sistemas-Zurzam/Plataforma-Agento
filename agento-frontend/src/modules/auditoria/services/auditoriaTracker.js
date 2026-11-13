import api from '../../../services/api';

/**
 * Rastreador de interacciones para la bitácora de auditoría: registra clicks
 * sobre elementos interactivos y la navegación, y los envía en lotes a la API.
 *
 * Privacidad: solo captura la etiqueta visible del control (texto, aria-label
 * o title). Nunca lee el valor de inputs, textareas ni selects, y respeta
 * `data-audit-ignore` para excluir un elemento y sus hijos.
 */

const SELECTOR_INTERACTIVO = [
  'button',
  'a[href]',
  'summary',
  '[role="button"]',
  '[role="menuitem"]',
  '[role="tab"]',
  '[role="switch"]',
  '[role="checkbox"]',
  '[role="radio"]',
  '[role="option"]',
  'input[type="submit"]',
  'input[type="button"]',
  '.ant-btn',
  '.ant-menu-item',
  '.ant-tabs-tab',
  '.ant-dropdown-menu-item',
  '.ant-switch',
  '.ant-checkbox-wrapper',
  '.ant-radio-wrapper',
  '.ant-select-item-option',
  '.ant-pagination-item',
  '.ant-table-column-sorter',
].join(',');

const INTERVALO_ENVIO_MS = 5000;
const LOTE_MAXIMO = 50;
const COLA_MAXIMA = 500;

let cola = [];
let temporizador = null;
let iniciado = false;
let limpiar = null;

function recortar(texto, max) {
  const limpio = (texto ?? '').replace(/\s+/g, ' ').trim();
  return limpio.length > max ? `${limpio.slice(0, max - 1)}…` : limpio;
}

function etiquetaDe(elemento) {
  const explicita = elemento.getAttribute('data-audit')
    || elemento.getAttribute('aria-label')
    || elemento.getAttribute('title');
  if (explicita) return recortar(explicita, 150);

  // Solo llegan inputs submit/button: su value es la etiqueta visible del botón.
  if (elemento.tagName === 'INPUT') return recortar(elemento.getAttribute('value') || elemento.type, 60);

  const texto = recortar(elemento.textContent, 100);
  if (texto) return texto;

  // Botones solo con ícono: los íconos de Ant Design traen aria-label.
  const icono = elemento.querySelector('[aria-label]')?.getAttribute('aria-label');
  return icono ? `ícono ${recortar(icono, 60)}` : '';
}

function contextoDe(elemento) {
  const contenedor = elemento.closest('.ant-modal, .ant-drawer, .ant-popover');
  const titulo = contenedor?.querySelector('.ant-modal-title, .ant-drawer-title, .ant-popover-title');
  return titulo ? recortar(titulo.textContent, 150) : null;
}

function encolar(evento) {
  if (cola.length >= COLA_MAXIMA) cola.shift();
  cola.push({ ...evento, ocurrido_en: new Date().toISOString() });

  if (cola.length >= LOTE_MAXIMO) {
    enviar();
  } else if (!temporizador) {
    temporizador = setTimeout(enviar, INTERVALO_ENVIO_MS);
  }
}

export async function enviar() {
  clearTimeout(temporizador);
  temporizador = null;
  if (cola.length === 0 || !localStorage.getItem('access_token')) return;

  const lote = cola.splice(0, LOTE_MAXIMO);
  try {
    await api.post('/auditoria/ui-eventos', { eventos: lote });
  } catch {
    // La auditoría de UI es de mejor esfuerzo: nunca debe molestar al usuario.
  }
  if (cola.length > 0 && !temporizador) temporizador = setTimeout(enviar, INTERVALO_ENVIO_MS);
}

// Al cerrar o ocultar la pestaña axios puede cancelarse: fetch con keepalive sí sobrevive.
function enviarAlSalir() {
  const token = localStorage.getItem('access_token');
  if (cola.length === 0 || !token) return;

  const lote = cola.splice(0, LOTE_MAXIMO);
  try {
    fetch(`${api.defaults.baseURL}/auditoria/ui-eventos`, {
      method: 'POST',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ eventos: lote }),
    });
  } catch {
    // mejor esfuerzo
  }
}

function registrarNavegacion() {
  const ruta = window.location.pathname;
  encolar({ tipo: 'navegacion', etiqueta: recortar(ruta, 150), ruta: recortar(ruta, 255) });
}

function alHacerClick(evento) {
  if (!(evento.target instanceof Element)) return;

  const elemento = evento.target.closest(SELECTOR_INTERACTIVO);
  if (!elemento || elemento.closest('[data-audit-ignore]')) return;

  const etiqueta = etiquetaDe(elemento);
  if (!etiqueta) return;

  encolar({
    tipo: 'click',
    etiqueta,
    ruta: recortar(window.location.pathname, 255),
    elemento: elemento.tagName.toLowerCase(),
    contexto: contextoDe(elemento),
  });
}

export function iniciarAuditoriaTracker() {
  if (iniciado) return;
  iniciado = true;

  const pushOriginal = window.history.pushState;
  window.history.pushState = function pushState(...args) {
    const resultado = pushOriginal.apply(this, args);
    registrarNavegacion();
    return resultado;
  };

  const alVolver = () => registrarNavegacion();
  const alOcultar = () => {
    if (document.visibilityState === 'hidden') enviarAlSalir();
  };

  document.addEventListener('click', alHacerClick, true);
  window.addEventListener('popstate', alVolver);
  window.addEventListener('pagehide', enviarAlSalir);
  document.addEventListener('visibilitychange', alOcultar);

  registrarNavegacion();

  limpiar = () => {
    document.removeEventListener('click', alHacerClick, true);
    window.removeEventListener('popstate', alVolver);
    window.removeEventListener('pagehide', enviarAlSalir);
    document.removeEventListener('visibilitychange', alOcultar);
    window.history.pushState = pushOriginal;
    iniciado = false;
    limpiar = null;
  };
}

export function detenerAuditoriaTracker() {
  limpiar?.();
  clearTimeout(temporizador);
  temporizador = null;
  cola = [];
}
