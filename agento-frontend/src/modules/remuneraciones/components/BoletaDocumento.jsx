import {
  ArrowDownOutlined,
  ArrowUpOutlined,
  CalendarOutlined,
  CreditCardOutlined,
  DollarCircleOutlined,
  UserOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Tag } from 'antd';
import dayjs from 'dayjs';

// Aportaciones del empleador que SÍ debe ver el trabajador en su boleta —
// ESSALUD y SIS son mutuamente excluyentes (SIS es la alternativa de Micro
// Empresa/REMYPE a ESSALUD, nunca ambas a la vez para el mismo colaborador).
// El resto del catálogo 'aportacion' (CTS_PROVISION, GRATIFICACION_LEGAL,
// BONIFICACION_EXTRAORDINARIA, VACACIONES_PROVISION) son provisiones
// contables internas que no corresponde mostrarle al colaborador.
const CODIGOS_APORTE_VISIBLE = ['ESSALUD', 'SIS_APORTACION'];

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function numero(valor, decimales = 0) {
  return Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}

function texto(valor) {
  return valor === null || valor === undefined || valor === '' ? '—' : valor;
}

function sino(valor) {
  return valor ? 'Sí' : 'No';
}

function moneda(codigo) {
  if (codigo === 'USD') return 'Dólares (US$)';
  if (codigo === 'PEN') return 'Soles (S/)';
  return '—';
}

function sumaCantidad(conceptos, codigo) {
  return conceptos.filter((c) => c.codigo === codigo).reduce((total, c) => total + Number(c.cantidad ?? 0), 0);
}

function Dato({ label, value }) {
  return (
    <div className="flex justify-between gap-3 border-b border-gray-100 py-1 text-[11px] last:border-0 print:py-0.5">
      <span className="text-gray-500">{label}</span>
      <span className="text-right font-semibold text-gray-800">{value}</span>
    </div>
  );
}

function Tarjeta({ titulo, icono, children, pie }) {
  return (
    <div className="overflow-hidden rounded-lg border border-gray-200 print:break-inside-avoid">
      <div className="flex items-center gap-1.5 bg-agento-blue px-3 py-1.5 text-[11px] font-semibold tracking-wide text-white uppercase print:py-0.5">
        {icono}
        {titulo}
      </div>
      <div className="bg-white px-3 py-2 print:py-1">{children}</div>
      {pie}
    </div>
  );
}

function TablaConceptos({ conceptos, vacio }) {
  if (!conceptos.length) return <p className="py-2 text-[11px] text-gray-400 print:py-0.5">{vacio}</p>;

  return (
    <table className="w-full text-[11px]">
      <tbody>
        {conceptos.map((c) => (
          <tr key={c.id} className="border-b border-gray-100 last:border-0">
            <td className="py-1 pr-2 align-top text-gray-700 print:py-0.5">
              {c.nombre}
              {c.formula_texto && <span className="block text-[10px] leading-tight text-gray-400">{c.formula_texto}</span>}
            </td>
            <td className="py-1 text-right align-top font-semibold whitespace-nowrap text-gray-900 print:py-0.5">{soles(c.monto)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function FilaTotal({ label, valor }) {
  return (
    <div className="flex justify-between bg-agento-blue-light px-3 py-1.5 text-xs font-bold text-agento-blue-dark print:py-0.5">
      <span className="uppercase tracking-wide">{label}</span>
      <span>{soles(valor)}</span>
    </div>
  );
}

/**
 * Documento de una boleta YA calculada — renderiza el snapshot tal cual
 * quedó guardado, nunca recalcula nada. Extraído de BoletaImprimibleModal
 * para que la impresión individual y la masiva (BoletasImprimirMasivoModal)
 * compartan exactamente el mismo layout, sin duplicarlo.
 */
export default function BoletaDocumento({ detalle }) {
  const conceptos = detalle?.conceptos ?? [];
  const esOficial = detalle?.estado === 'pagada';
  const ingresos = conceptos.filter((c) => c.tipo === 'ingreso');
  const egresos = conceptos.filter((c) => c.tipo === 'egreso');
  const aportacionesVisibles = conceptos.filter((c) => c.tipo === 'aportacion' && CODIGOS_APORTE_VISIBLE.includes(c.codigo));
  const totalAportacionesVisibles = aportacionesVisibles.reduce((suma, c) => suma + Number(c.monto ?? 0), 0);

  const empresa = detalle?.empresa;
  const empresaTitulo = [empresa?.razon_social, empresa?.nombre_comercial]
    .filter((valor, indice, lista) => valor && lista.indexOf(valor) === indice)
    .join(' - ') || detalle?.colaborador?.empresa || '—';

  const periodoLabel = detalle?.ciclo?.nombre
    || (detalle?.ciclo?.fecha_fin ? dayjs(detalle.ciclo.fecha_fin).format('MMMM YYYY').toUpperCase() : '');

  const datosPago = detalle?.datos_pago;
  const ausencias = detalle?.ausencias_periodo;
  const sistemaPrevisional = detalle?.colaborador?.sistema_previsional === 'onp' ? 'ONP' : 'AFP';
  const reintegros = detalle?.reintegros ?? [];
  const totalReintegros = reintegros.reduce((suma, r) => suma + Number(r.monto ?? 0), 0);
  const totalAfpReintegros = reintegros.reduce((suma, r) => suma + Number(r.afp_retenido ?? 0), 0);
  const netoConReintegros = Number(detalle?.neto_a_pagar ?? 0) + totalReintegros;

  return (
    <div className="boleta-documento overflow-hidden rounded-xl border border-gray-200">
      {!esOficial && (
        <div className="border-b border-orange-200 bg-orange-50 px-3 py-2 text-center text-xs font-semibold tracking-wide text-orange-700 uppercase print:hidden">
          Previsualización — documento no oficial, sujeto a cambios hasta que la boleta se marque como pagada
        </div>
      )}

      {/* Header de marca Agento */}
      <div className="flex items-center justify-between gap-4 bg-gradient-to-br from-agento-blue to-agento-blue-dark px-5 py-4 text-white print:px-4 print:py-2">
        <div>
          <p className="text-xs font-semibold tracking-widest uppercase opacity-80">Agento</p>
          <p className="text-lg leading-tight font-bold">Boleta de Pago</p>
          {periodoLabel && <p className="text-xs tracking-wide uppercase opacity-90">{periodoLabel}</p>}
        </div>
        <div className="shrink-0 rounded-lg border border-white/30 px-3 py-1.5 text-right print:py-0.5">
          <p className="text-[9px] tracking-widest uppercase opacity-80">N° Boleta</p>
          <p className="text-sm font-bold">{texto(detalle.numero_boleta)}</p>
        </div>
      </div>

      {/* Barra con los datos de la empresa */}
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-5 py-2.5 print:px-4 print:py-1">
        <div>
          <p className="text-sm font-bold text-gray-900">{empresaTitulo}</p>
          <p className="text-[11px] text-gray-500">RUC: {texto(empresa?.ruc)} · {texto(empresa?.direccion)}</p>
        </div>
        <Tag className="print:!hidden" color={esOficial ? 'green' : 'orange'}>{esOficial ? 'Oficial' : `Versión ${detalle.version} — no oficial`}</Tag>
      </div>

      <div className="space-y-3 p-4 print:space-y-1 print:p-1.5">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 print:gap-1">
          <Tarjeta titulo="Datos del trabajador" icono={<UserOutlined />}>
            <Dato label="Apellidos y Nombres" value={detalle.colaborador?.nombre_completo} />
            <Dato label="DNI" value={texto(detalle.colaborador?.numero_documento)} />
            <Dato label="Código" value={texto(detalle.colaborador?.legajo)} />
            <Dato label="Cargo" value={texto(detalle.colaborador?.cargo)} />
            <Dato label="Área" value={texto(detalle.colaborador?.area)} />
            <Dato label="Fecha de Ingreso" value={detalle.colaborador?.fecha_ingreso ? dayjs(detalle.colaborador.fecha_ingreso).format('DD/MM/YYYY') : '—'} />
            <Dato label="Régimen Laboral" value={texto(detalle.regimen_laboral)} />
            <Dato label="AFP / ONP" value={sistemaPrevisional} />
            <Dato label="CUSPP" value={detalle.colaborador?.cuspp || 'No registrado'} />
          </Tarjeta>

          <Tarjeta titulo="Información del período" icono={<CalendarOutlined />}>
            <Dato label="Horas Extras 25%" value={numero(sumaCantidad(conceptos, 'HE_25'), 1)} />
            <Dato label="Horas Extras 35%" value={numero(sumaCantidad(conceptos, 'HE_35'), 1)} />
            <Dato label="Vacaciones" value={sino(ausencias?.vacaciones)} />
            <Dato label="Descanso Médico" value={sino(ausencias?.descanso_medico)} />
            <Dato label="Licencias" value={ausencias?.licencia || 'Ninguna'} />
          </Tarjeta>
        </div>

        <Tarjeta
          titulo="Ingresos"
          icono={<DollarCircleOutlined />}
          pie={<FilaTotal label="Total ingresos" valor={detalle.total_ingresos} />}
        >
          <TablaConceptos conceptos={ingresos} vacio="Sin ingresos registrados" />
        </Tarjeta>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 print:gap-1">
          <Tarjeta
            titulo="Descuentos"
            icono={<ArrowDownOutlined />}
            pie={<FilaTotal label="Total descuentos" valor={detalle.total_egresos} />}
          >
            <TablaConceptos conceptos={egresos} vacio="Sin descuentos" />
          </Tarjeta>

          <Tarjeta
            titulo="Aportes del empleador (informativo)"
            icono={<ArrowUpOutlined />}
            pie={aportacionesVisibles.length > 0 && <FilaTotal label="Total aportes" valor={totalAportacionesVisibles} />}
          >
            <TablaConceptos conceptos={aportacionesVisibles} vacio="Sin aportes" />
          </Tarjeta>

          <Tarjeta titulo="Datos de pago" icono={<CreditCardOutlined />}>
            <Dato label="Banco" value={texto(datosPago?.banco)} />
            <Dato label="Cuenta" value={texto(datosPago?.numero_cuenta)} />
            <Dato label="CCI" value={texto(datosPago?.cci)} />
            <Dato label="Moneda" value={moneda(datosPago?.moneda)} />
          </Tarjeta>
        </div>

        {reintegros.length > 0 && (
          <Tarjeta
            titulo="Reintegros pagados con posterioridad"
            icono={<DollarCircleOutlined />}
            pie={
              <>
                <FilaTotal label="Total reintegros" valor={totalReintegros} />
                {totalAfpReintegros !== 0 && (
                  <div className="flex justify-between bg-gray-50 px-3 py-1 text-[10px] text-gray-500 print:py-0.5">
                    <span>AFP/ONP retenido en reintegros</span>
                    <span>{soles(totalAfpReintegros)}</span>
                  </div>
                )}
              </>
            }
          >
            <table className="w-full text-[11px]">
              <tbody>
                {reintegros.map((r, indice) => (
                  <tr key={indice} className="border-b border-gray-100 last:border-0">
                    <td className="py-1 pr-2 align-top text-gray-700 print:py-0.5">
                      {r.tipo}
                      <span className="block text-[10px] leading-tight text-gray-400">
                        {r.motivo}{r.pagado_at ? ` — pagado el ${dayjs(r.pagado_at).format('DD/MM/YYYY')}` : ''}
                      </span>
                    </td>
                    <td className={`py-1 text-right align-top font-semibold whitespace-nowrap print:py-0.5 ${Number(r.monto) >= 0 ? 'text-green-700' : 'text-red-600'}`}>
                      {soles(r.monto)}
                      {Number(r.afp_retenido) !== 0 && (
                        <span className="block text-[9px] leading-tight font-normal text-gray-400">
                          AFP/ONP: {soles(r.afp_retenido)}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Tarjeta>
        )}

        <div className="flex overflow-hidden rounded-lg print:break-inside-avoid">
          <div className="flex flex-1 flex-col justify-center bg-agento-blue-dark px-4 py-3 text-white print:py-1">
            <span className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide"><WalletOutlined /> Neto a pagar</span>
            <span className="text-[10px] uppercase opacity-80">
              {reintegros.length > 0 ? `Abonado en cuenta — boleta ${soles(detalle.neto_a_pagar)} + reintegros ${soles(totalReintegros)}` : 'Abonado en cuenta'}
            </span>
          </div>
          <div className="flex items-center bg-agento-blue-light px-6 text-xl font-extrabold text-agento-blue-dark">
            {soles(reintegros.length > 0 ? netoConReintegros : detalle.neto_a_pagar)}
          </div>
        </div>

        <div className="rounded-md bg-gray-50 px-3 py-2 text-[10px] text-gray-400 print:break-inside-avoid print:py-0.5 print:leading-tight">
          <p>Documento emitido electrónicamente conforme al D.S. N° 001-98-TR y normas complementarias. Esta boleta ha sido firmada digitalmente y puede verificarse mediante el código QR.</p>
          <p className="mt-1">Calculado el {detalle.calculado_at} · Parámetros: {detalle.snapshot_parametros_version} · Reglas: {detalle.snapshot_reglas_version}</p>
        </div>
      </div>
    </div>
  );
}
