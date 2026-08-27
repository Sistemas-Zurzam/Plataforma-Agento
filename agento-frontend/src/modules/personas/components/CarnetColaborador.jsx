import { TeamOutlined } from '@ant-design/icons';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

/** Alto de la parte "plana" del encabezado (logo+nombre) y punto más bajo
 * de la curva — el `-mt-14` (56px, mitad de la foto de 112px) de la foto
 * está calculado para que su centro caiga justo en ALTO_CURVA. Un <path>
 * de SVG en vez de border-radius elíptico: control exacto y predecible
 * sobre la forma de la onda, sin depender de ajustar a ojo. */
const ALTO_PLANO = 100;
const ALTO_CURVA = 168;
/** Una sola curva suave (no dos lomos — eso generaba picos/quiebres poco
 * naturales) con los bordes bajos (mucho verde visible a los costados,
 * como en la referencia) y un único punto de control desplazado un poco a
 * la izquierda del centro para que el valle no sea perfectamente
 * simétrico. */
const Y_BORDE_IZQUIERDO = 122;
const Y_BORDE_DERECHO = 130;
const X_VALLE = 105;
/** Misma curva, más clara y con los bordes un poco más abajo — asoma
 * detrás de la onda principal dando sensación de capas. */
const Y_BORDE_IZQUIERDO_FONDO = 140;
const Y_BORDE_DERECHO_FONDO = 148;

/** Degradado claro→oscuro real (no solo una capa translúcida) usando
 * color-mix — funciona sobre cualquier color de empresa sin necesitar una
 * librería de manipulación de color. Soportado en navegadores modernos
 * (Chrome/Edge 111+, Firefox 113+); si el navegador no lo soporta, el
 * degradado simplemente cae al color plano de la empresa sin romper nada. */
const degradado = (color, angulo = '135deg') =>
  `linear-gradient(${angulo}, color-mix(in srgb, ${color} 55%, white), ${color} 55%, color-mix(in srgb, ${color} 78%, black))`;

/**
 * Vertical, solo frente (decisión del usuario) — sin grupo sanguíneo,
 * contacto de emergencia ni código de barras. Diseño alineado a la
 * referencia entregada: encabezado curvo con degradado y realces suaves,
 * usando el color/logo de la EMPRESA dueña del colaborador (multiempresa,
 * nunca la marca de la plataforma), foto circular superpuesta al borde del
 * encabezado, y el cargo como insignia tipo "pill" grande en la base. Se
 * retiró el QR (era decorativo, sin endpoint de verificación) para
 * mantener la limpieza visual de la referencia.
 *
 * El tamaño de acá (260x414px, proporción 54:86 — el tamaño físico real de
 * la tarjeta para impresora de PVC tipo Epson L8050) es la VISTA PREVIA en
 * pantalla. VerCarnetModal::imprimirCarnet() escala este mismo diseño con
 * `transform: scale()` al tamaño físico exacto al imprimir — por eso la
 * proporción acá DEBE mantenerse 54:86 (o el escalado deja franjas en
 * blanco o recorta contenido); no cambiar el ancho/alto sin ajustar el
 * otro para conservar esa proporción.
 */
export default function CarnetColaborador({ colaborador, fotoUrl }) {
  const color = colaborador.empresa?.color || '#014693';
  const logo = colaborador.empresa?.logo_url;
  const fotoOFallback = fotoUrl || logo;
  // Carnet físico: solo primer nombre + primer apellido (paterno) — nombres
  // y apellidos pueden traer varias palabras (segundo nombre, materno), y
  // ponerlas todas no entra ni hace falta en una tarjeta de este tamaño.
  const primerNombre = colaborador.nombres?.trim().split(/\s+/)[0] ?? '';
  const primerApellido = colaborador.apellidos?.trim().split(/\s+/)[0] ?? '';
  const nombreCarnet = `${primerNombre} ${primerApellido}`.trim() || colaborador.nombre_completo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative flex flex-col overflow-hidden rounded-3xl bg-white shadow-lg"
      style={{ width: 260, height: 414 }}
    >
      <div className="relative shrink-0" style={{ height: ALTO_CURVA }}>
        <svg viewBox={`0 0 260 ${ALTO_CURVA}`} preserveAspectRatio="none" className="absolute inset-0 h-full w-full">
          <defs>
            <clipPath id="carnet-header-clip">
              <path d={`M0,0 H260 V${Y_BORDE_DERECHO} Q${X_VALLE},${ALTO_CURVA} 0,${Y_BORDE_IZQUIERDO} Z`} />
            </clipPath>
            {/* El `fill` de SVG solo acepta color/url()/none — nunca una
              función linear-gradient() directa (por eso el rect quedaba
              negro: el navegador descartaba el valor inválido y caía al
              negro por defecto). Acá sí corresponde un <linearGradient>
              real referenciado por id, con cada <stop> resuelto vía
              color-mix en el atributo style. */}
            <linearGradient id="carnet-header-grad" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" style={{ stopColor: `color-mix(in srgb, ${color} 55%, white)` }} />
              <stop offset="55%" style={{ stopColor: color }} />
              <stop offset="100%" style={{ stopColor: `color-mix(in srgb, ${color} 78%, black)` }} />
            </linearGradient>
          </defs>
          {/* Onda "de fondo" — más clara, converge al mismo punto central
            pero sus bordes asoman por debajo de la onda principal. */}
          <path
            d={`M0,0 H260 V${Y_BORDE_DERECHO_FONDO} Q${X_VALLE},${ALTO_CURVA} 0,${Y_BORDE_IZQUIERDO_FONDO} Z`}
            style={{ fill: `color-mix(in srgb, ${color} 45%, white)` }}
          />
          <g clipPath="url(#carnet-header-clip)">
            <rect width="260" height={ALTO_CURVA} fill="url(#carnet-header-grad)" />
            <circle cx="215" cy="20" r="75" fill="rgba(255,255,255,.16)" />
            <circle cx="245" cy="75" r="52" fill="rgba(255,255,255,.12)" />
          </g>
        </svg>
        <div className="relative z-10 flex flex-col items-center justify-center gap-2 px-6" style={{ height: ALTO_PLANO }}>
          {logo ? (
            <img src={logo} alt="" className="h-11 w-11 rounded-xl bg-white/20 object-contain p-1.5" />
          ) : (
            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-white/20 text-xl font-black text-white">
              {colaborador.empresa?.nombre?.[0] ?? '?'}
            </span>
          )}
          <span className="line-clamp-2 text-center text-[15px] leading-tight font-extrabold tracking-wide text-white uppercase">
            {colaborador.empresa?.nombre ?? 'Empresa'}
          </span>
        </div>
      </div>

      <div className="relative z-20 -mt-14 flex justify-center">
        {fotoOFallback ? (
          <img
            src={fotoOFallback}
            alt={colaborador.nombre_completo}
            className="h-28 w-28 rounded-full border-4 border-white bg-white object-cover"
            style={{ boxShadow: `0 0 0 2px ${color}, 0 8px 16px rgba(0,0,0,.2)` }}
          />
        ) : (
          <div
            className="flex h-28 w-28 items-center justify-center rounded-full border-4 border-white text-2xl font-bold text-white"
            style={{ backgroundColor: colorForName(colaborador.nombre_completo), boxShadow: `0 0 0 2px ${color}, 0 8px 16px rgba(0,0,0,.2)` }}
          >
            {initialsForName(colaborador.nombre_completo)}
          </div>
        )}
      </div>

      <p className="mt-4 px-4 text-center text-[21px] leading-tight font-extrabold text-gray-900">
        {nombreCarnet}
      </p>

      <div className="mx-auto mt-2.5 flex w-28 items-center gap-1.5">
        <span className="h-px flex-1 bg-gray-200" />
        <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: color }} />
        <span className="h-px flex-1 bg-gray-200" />
      </div>

      <p className="mt-2 text-center text-sm font-semibold tracking-wide text-gray-500">
        {colaborador.numero_documento}
      </p>

      <div className="flex-1" />

      <div className="mb-6 flex justify-center px-4">
        <div
          className="flex w-full items-center gap-2 rounded-full py-1.5 pr-3 pl-1"
          style={{ backgroundImage: degradado(color, '90deg') }}
        >
          <span
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-sm"
            style={{ color }}
          >
            <TeamOutlined />
          </span>
          <span className="min-w-0 flex-1 truncate text-[10px] font-bold tracking-tight text-white uppercase">
            {colaborador.cargo ?? 'Colaborador'}
          </span>
        </div>
      </div>
    </div>
  );
}
