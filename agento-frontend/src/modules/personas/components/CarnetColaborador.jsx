import { BankOutlined, IdcardOutlined, SolutionOutlined, TeamOutlined, UserOutlined } from '@ant-design/icons';
import { QRCodeSVG } from 'qrcode.react';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

function Dato({ icono, label, valor, color }) {
  return (
    <div className="flex items-center gap-1.5 text-[10px]">
      <span
        className="flex h-5 w-5 shrink-0 items-center justify-center rounded-md text-xs"
        style={{ backgroundColor: `${color}1a`, color }}
      >
        {icono}
      </span>
      <span className="w-[44px] shrink-0 font-medium text-gray-500">{label}:</span>
      <span className="min-w-0 flex-1 truncate font-semibold text-gray-800" title={valor}>
        {valor ?? '—'}
      </span>
    </div>
  );
}

/**
 * Vertical, solo frente (decisión del usuario) — sin grupo sanguíneo,
 * contacto de emergencia, vigencia ni código de barras. El encabezado usa
 * el color y logo de la EMPRESA dueña del colaborador (multiempresa),
 * nunca la marca de la plataforma. El QR lleva únicamente el legajo
 * (decorativo, sin endpoint de verificación — no se definió otro contenido).
 *
 * El tamaño de acá (260x412px) es solo para la VISTA PREVIA en pantalla —
 * FichaColaborador::imprimirCarnet() fuerza el tamaño físico real de la
 * tarjeta (53.98x85.6mm) con `!important` al imprimir, así que agrandar
 * esto no afecta lo que sale impreso, solo mejora la legibilidad acá.
 */
export default function CarnetColaborador({ colaborador, fotoUrl }) {
  const color = colaborador.empresa?.color || '#014693';
  const logo = colaborador.empresa?.logo_url;
  const fotoOFallback = fotoUrl || logo;

  return (
    <div
      id="carnet-colaborador-imprimible"
      className="relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-lg"
      style={{ width: 260, height: 412 }}
    >
      <div className="relative h-24 shrink-0">
        <div className="absolute inset-0" style={{ background: color, clipPath: 'polygon(0 0, 100% 0, 100% 68%, 0 100%)' }} />
        <div className="relative z-10 flex h-full flex-col items-center justify-center gap-1 pb-3">
          {logo && <img src={logo} alt="" className="h-8 w-8 rounded bg-white/20 object-contain p-0.5" />}
          <span className="truncate px-4 text-[14px] font-bold text-white">
            {colaborador.empresa?.nombre ?? 'Empresa'}
          </span>
        </div>
      </div>

      <div className="-mt-1 flex flex-col items-center">
        {fotoOFallback ? (
          <img
            src={fotoOFallback}
            alt={colaborador.nombre_completo}
            className="h-24 w-24 rounded-full border-4 bg-white object-cover shadow"
            style={{ borderColor: color }}
          />
        ) : (
          <div
            className="flex h-24 w-24 items-center justify-center rounded-full border-4 text-2xl font-bold text-white shadow"
            style={{ backgroundColor: colorForName(colaborador.nombre_completo), borderColor: color }}
          >
            {initialsForName(colaborador.nombre_completo)}
          </div>
        )}
      </div>

      <p className="mt-3 px-4 text-center text-[15px] font-extrabold tracking-wide uppercase" style={{ color }}>
        Carnet de Colaborador
      </p>
      <span className="mx-auto mt-1 block h-0.5 w-14 rounded-full" style={{ backgroundColor: color }} />

      <div className="mt-4 flex flex-1 flex-col justify-center gap-2 px-5">
        <Dato icono={<UserOutlined />} label="Nombre" valor={colaborador.nombre_completo} color={color} />
        <Dato icono={<SolutionOutlined />} label="Cargo" valor={colaborador.cargo} color={color} />
        <Dato icono={<TeamOutlined />} label="Área" valor={colaborador.area?.nombre} color={color} />
        <Dato icono={<IdcardOutlined />} label="Código" valor={colaborador.legajo} color={color} />
        <Dato icono={<BankOutlined />} label="Empresa" valor={colaborador.empresa?.nombre} color={color} />
      </div>

      <div className="flex justify-center pb-4">
        <QRCodeSVG value={colaborador.legajo ?? colaborador.id?.toString() ?? ''} size={76} />
      </div>

      <div className="relative h-9 shrink-0">
        <div className="absolute inset-0" style={{ background: color, clipPath: 'polygon(0 32%, 100% 0, 100% 100%, 0 100%)' }} />
        <div className="relative z-10 flex h-full items-center justify-center">
          <span className="text-[9px] font-medium text-white">Documento de identificación interna</span>
        </div>
      </div>
    </div>
  );
}
