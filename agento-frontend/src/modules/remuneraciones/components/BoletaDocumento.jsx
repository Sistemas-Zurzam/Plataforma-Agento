import {
  CalendarOutlined,
  CreditCardOutlined,
  UserOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Tag } from 'antd';
import dayjs from 'dayjs';
import agentoLogo from '../../../assets/agento-logo.png';
import { numeroALetras } from '../utils/numeroALetras';

// Aportaciones del empleador que SÍ debe ver el trabajador en su boleta —
// ESSALUD y SIS son mutuamente excluyentes (SIS es la alternativa de Micro
// Empresa/REMYPE a ESSALUD, nunca ambas a la vez para el mismo colaborador).
// El resto del catálogo 'aportacion' (CTS_PROVISION, GRATIFICACION_LEGAL,
// BONIFICACION_EXTRAORDINARIA, VACACIONES_PROVISION) son provisiones
// contables internas que no corresponde mostrarle al colaborador.
const CODIGOS_APORTE_VISIBLE = ['ESSALUD', 'SIS_APORTACION'];

// Únicos códigos cuyo `cantidad` no se lee en la misma unidad que el resto
// (minutos en vez de días/horas) — el resto se muestra tal cual con 2 decimales.
const UNIDADES_CANTIDAD = { DESCUENTO_TARDANZA: 'min' };

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

function formatCantidad(concepto) {
  if (concepto.cantidad === null || concepto.cantidad === undefined) return null;
  const unidad = UNIDADES_CANTIDAD[concepto.codigo];
  return unidad ? `${numero(concepto.cantidad, 0)} ${unidad}` : numero(concepto.cantidad, 2);
}

// "EMPLEADO MYPE" / "OBRERO MYPE" — MYPE se deriva del régimen laboral
// (Micro/Pequeña Empresa) porque no existe un campo propio para esa etiqueta.
function tipoTrabajadorLabel(categoriaTrabajador, regimenLaboral) {
  const base = categoriaTrabajador === 'obrero' ? 'OBRERO' : 'EMPLEADO';
  const esMype = regimenLaboral === 'Micro Empresa' || regimenLaboral === 'Pequeña Empresa';
  return esMype ? `${base} MYPE` : base;
}

function Dato({ label, value }) {
  return (
    <div className="flex justify-between gap-3 border-b border-gray-100 py-1 text-[11px] last:border-0 print:py-0.5">
      <span className="text-gray-500">{label}</span>
      <span className="text-right font-semibold text-gray-800">{value}</span>
    </div>
  );
}

function Tarjeta({ titulo, icono, children }) {
  return (
    <div className="overflow-hidden rounded-lg border border-gray-200 print:break-inside-avoid">
      <div className="flex items-center gap-1.5 bg-agento-blue px-3 py-1.5 text-[11px] font-semibold tracking-wide text-white uppercase print:py-0.5">
        {icono}
        {titulo}
      </div>
      <div className="bg-white px-3 py-2 print:py-1">{children}</div>
    </div>
  );
}

function FilaSeccion({ titulo }) {
  return (
    <tr className="bg-agento-blue-light">
      <td colSpan={6} className="px-2 py-1 text-[10px] font-bold tracking-wide text-agento-blue-dark uppercase print:py-0.5">
        {titulo}
      </td>
    </tr>
  );
}

function FilaVacia({ texto: mensaje }) {
  return (
    <tr>
      <td colSpan={6} className="px-2 py-2 text-[11px] text-gray-400 print:py-0.5">{mensaje}</td>
    </tr>
  );
}

function FilaMatriz({ nombre, sub, cantidad, ingresos, reintegros, descuentos, aporte }) {
  const celda = (valor) => (valor === null || valor === undefined ? <span className="text-gray-300">—</span> : soles(valor));

  return (
    <tr className="border-b border-gray-100 align-top text-[11px]">
      <td className="py-1 pr-2 text-gray-700 print:py-0.5">
        {nombre}
        {sub && <span className="block text-[10px] leading-tight text-gray-400">{sub}</span>}
      </td>
      <td className="py-1 text-right whitespace-nowrap text-gray-500 print:py-0.5">{cantidad ?? '—'}</td>
      <td className="py-1 text-right font-semibold whitespace-nowrap text-gray-900 print:py-0.5">{celda(ingresos)}</td>
      <td className="py-1 text-right font-semibold whitespace-nowrap text-gray-900 print:py-0.5">{celda(reintegros)}</td>
      <td className="py-1 text-right font-semibold whitespace-nowrap text-gray-900 print:py-0.5">{celda(descuentos)}</td>
      <td className="py-1 text-right font-semibold whitespace-nowrap text-gray-900 print:py-0.5">{celda(aporte)}</td>
    </tr>
  );
}

function FilaTotales({ ingresos, reintegros, descuentos, aporte }) {
  return (
    <tr className="bg-agento-blue-light text-xs font-bold text-agento-blue-dark">
      <td className="px-2 py-1.5 uppercase tracking-wide print:py-0.5">Totales</td>
      <td className="py-1.5 text-right print:py-0.5">—</td>
      <td className="py-1.5 text-right print:py-0.5">{soles(ingresos)}</td>
      <td className="py-1.5 text-right print:py-0.5">{soles(reintegros)}</td>
      <td className="py-1.5 text-right print:py-0.5">{soles(descuentos)}</td>
      <td className="py-1.5 text-right print:py-0.5">{soles(aporte)}</td>
    </tr>
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

  // "Reintegros" en la matriz muestra el bruto (antes del AFP/ONP adicional
  // que generó); el neto ya pagado se anota debajo de cada fila.
  const totalReintegrosBruto = reintegros.reduce((suma, r) => suma + Number(r.monto ?? 0) + Number(r.afp_retenido ?? 0), 0);

  // Los conceptos previsionales (AFP/ONP) muestran su monto VIGENTE
  // (remuneración + reintegros acumulados), no el congelado en la boleta
  // original — por eso Descuentos no puede sumarse directamente desde
  // `detalle.total_egresos`.
  const desglosePrevisionalPorCodigo = new Map((detalle?.desglose_previsional ?? []).map((d) => [d.codigo, d]));
  const totalDescuentosVigente = egresos.reduce((suma, c) => {
    const desglose = desglosePrevisionalPorCodigo.get(c.codigo);
    return suma + (desglose ? desglose.de_remuneracion + desglose.de_reintegros : Number(c.monto ?? 0));
  }, 0);

  const netoPagar = Number(detalle?.total_ingresos ?? 0) + totalReintegrosBruto - totalDescuentosVigente;

  return (
    <div className="boleta-documento overflow-hidden rounded-xl border border-gray-200">
      {!esOficial && (
        <div className="border-b border-orange-200 bg-orange-50 px-3 py-2 text-center text-xs font-semibold tracking-wide text-orange-700 uppercase print:hidden">
          Previsualización — documento no oficial, sujeto a cambios hasta que la boleta se marque como pagada
        </div>
      )}

      {/* Header de marca Agento */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-white px-5 py-3 print:px-4 print:py-2">
        <div className="flex items-center gap-2.5">
          <img src={agentoLogo} alt="Agento" className="h-9 w-9 rounded-full object-cover" />
          <div>
            <p className="text-lg leading-tight font-bold text-agento-blue">AGENTO</p>
            <p className="text-[9px] tracking-widest text-gray-400 uppercase">Sistema de Gestión de Personal</p>
          </div>
        </div>
        <div className="text-center">
          <p className="text-lg leading-tight font-bold text-gray-900">Boleta de Pago</p>
          {periodoLabel && <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">Planilla {periodoLabel}</p>}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <div className="rounded-lg bg-agento-blue px-3 py-1.5 text-right text-white print:py-0.5">
            <p className="text-[9px] tracking-widest uppercase opacity-80">N° Boleta</p>
            <p className="text-sm font-bold">{texto(detalle.numero_boleta)}</p>
          </div>
          <Tag className="print:!hidden" color={esOficial ? 'green' : 'orange'}>{esOficial ? 'Oficial' : `Versión ${detalle.version} — no oficial`}</Tag>
        </div>
      </div>

      {/* Barra con los datos de la empresa */}
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-5 py-2.5 print:px-4 print:py-1">
        <div>
          <p className="text-sm font-bold text-gray-900">{empresaTitulo}</p>
          <p className="text-[11px] text-gray-500">RUC: {texto(empresa?.ruc)} · {texto(empresa?.direccion)}</p>
        </div>
        {empresa?.logo_url && (
          <img src={empresa.logo_url} alt={empresaTitulo} className="h-10 max-w-[140px] object-contain print:h-8" />
        )}
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
            <Dato label="Sucursal" value={texto(detalle.colaborador?.sede)} />
          </Tarjeta>

          <Tarjeta titulo="Información del período" icono={<CalendarOutlined />}>
            <Dato label="Período" value={texto(periodoLabel)} />
            <Dato label="Fecha de Inicio" value={detalle.ciclo?.fecha_inicio ? dayjs(detalle.ciclo.fecha_inicio).format('DD/MM/YYYY') : '—'} />
            <Dato label="Fecha de Fin" value={detalle.ciclo?.fecha_fin ? dayjs(detalle.ciclo.fecha_fin).format('DD/MM/YYYY') : '—'} />
            <Dato label="Días Trabajados" value={numero(detalle.dias_pagados, 2)} />
            <Dato label="Horas Extras 25%" value={numero(sumaCantidad(conceptos, 'HE_25'), 1)} />
            <Dato label="Horas Extras 35%" value={numero(sumaCantidad(conceptos, 'HE_35'), 1)} />
            <Dato label="Vacaciones" value={sino(ausencias?.vacaciones)} />
            <Dato label="Descanso Médico" value={sino(ausencias?.descanso_medico)} />
            <Dato label="Licencias" value={ausencias?.licencia || 'Ninguna'} />
            <Dato label="Tipo de Trabajador" value={tipoTrabajadorLabel(detalle.colaborador?.categoria_trabajador, detalle.regimen_laboral)} />
            <Dato label="Sueldo / Jornal" value={numero(detalle.sueldo_basico, 2)} />
          </Tarjeta>
        </div>

        <div className="overflow-hidden rounded-lg border border-gray-200 print:break-inside-avoid">
          <table className="w-full border-collapse text-[11px]">
            <thead>
              <tr className="bg-agento-blue text-[10px] font-semibold tracking-wide text-white uppercase">
                <th className="px-2 py-1.5 text-left print:py-0.5">Concepto</th>
                <th className="px-2 py-1.5 text-right print:py-0.5">Días / Horas</th>
                <th className="px-2 py-1.5 text-right print:py-0.5">Ingresos (S/)</th>
                <th className="px-2 py-1.5 text-right print:py-0.5">
                  Reintegros (S/)
                  <span className="block text-[8px] font-normal normal-case opacity-80">(Importe bruto)</span>
                </th>
                <th className="px-2 py-1.5 text-right print:py-0.5">Descuentos (S/)</th>
                <th className="px-2 py-1.5 text-right print:py-0.5">Aporte Empleador (S/)</th>
              </tr>
            </thead>
            <tbody>
              <FilaSeccion titulo="Remuneración ordinaria" />
              {ingresos.length === 0 && <FilaVacia texto="Sin ingresos registrados" />}
              {ingresos.map((c) => (
                <FilaMatriz key={c.id} nombre={c.nombre} sub={c.formula_texto} cantidad={formatCantidad(c)} ingresos={c.monto} />
              ))}

              {reintegros.length > 0 && (
                <>
                  <FilaSeccion titulo="Reintegros pagados con posterioridad (Importe bruto)" />
                  {reintegros.map((r, indice) => (
                    <FilaMatriz
                      key={indice}
                      nombre={r.nombre}
                      sub={(
                        <>
                          {r.motivo}{r.pagado_at ? ` — pagado el ${dayjs(r.pagado_at).format('DD/MM/YYYY')}` : ''}
                          <span className="block text-[10px] text-gray-400">Neto pagado: {soles(r.monto)}</span>
                        </>
                      )}
                      reintegros={Number(r.monto ?? 0) + Number(r.afp_retenido ?? 0)}
                    />
                  ))}
                </>
              )}

              <FilaSeccion titulo="Descuentos" />
              {egresos.length === 0 && <FilaVacia texto="Sin descuentos" />}
              {egresos.map((c) => {
                const desglose = desglosePrevisionalPorCodigo.get(c.codigo);

                if (desglose) {
                  return (
                    <FilaMatriz
                      key={c.id}
                      nombre={c.nombre}
                      sub={(
                        <>
                          <span className="block text-[10px] text-gray-400">De remuneración: {soles(desglose.de_remuneracion)}</span>
                          <span className="block text-[10px] text-gray-400">De reintegros: {soles(desglose.de_reintegros)}</span>
                        </>
                      )}
                      descuentos={desglose.de_remuneracion + desglose.de_reintegros}
                    />
                  );
                }

                return <FilaMatriz key={c.id} nombre={c.nombre} sub={c.formula_texto} cantidad={formatCantidad(c)} descuentos={c.monto} />;
              })}

              <FilaSeccion titulo="Aportes del empleador (informativo)" />
              {aportacionesVisibles.length === 0 && <FilaVacia texto="Sin aportes" />}
              {aportacionesVisibles.map((c) => (
                <FilaMatriz key={c.id} nombre={c.nombre} sub={c.formula_texto} aporte={c.monto} />
              ))}

              <FilaTotales
                ingresos={detalle.total_ingresos}
                reintegros={totalReintegrosBruto}
                descuentos={totalDescuentosVigente}
                aporte={totalAportacionesVisibles}
              />
            </tbody>
          </table>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row print:break-inside-avoid">
          <div className="flex flex-1 overflow-hidden rounded-lg">
            <div className="flex flex-1 flex-col justify-center bg-agento-blue-dark px-4 py-3 text-white print:py-1">
              <span className="flex items-center gap-2 text-sm font-bold tracking-wide uppercase"><WalletOutlined /> Neto a pagar</span>
              <span className="text-[10px] uppercase opacity-80">Ingresos + Reintegros (brutos) – Descuentos</span>
            </div>
            <div className="flex items-center bg-agento-blue-light px-6 text-xl font-extrabold text-agento-blue-dark">
              {soles(netoPagar)}
            </div>
          </div>

          <div className="overflow-hidden rounded-lg border border-gray-200 sm:w-64 print:break-inside-avoid">
            <div className="bg-agento-blue px-3 py-1.5 text-[11px] font-semibold tracking-wide text-white uppercase print:py-0.5">Resumen</div>
            <div className="space-y-1 bg-white px-3 py-2 text-[11px] print:py-1">
              <div className="flex justify-between"><span className="text-gray-500">Total ingresos</span><span className="font-semibold text-gray-800">{soles(detalle.total_ingresos)}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">Total reintegros (brutos)</span><span className="font-semibold text-gray-800">{soles(totalReintegrosBruto)}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">Total descuentos</span><span className="font-semibold text-gray-800">{soles(totalDescuentosVigente)}</span></div>
              <div className="flex justify-between border-t border-gray-100 pt-1 font-bold text-agento-blue-dark"><span>Total a pagar</span><span>{soles(netoPagar)}</span></div>
            </div>
          </div>
        </div>

        <p className="text-[11px] font-semibold text-gray-700">
          <span className="font-normal text-gray-400">SON:</span> {numeroALetras(netoPagar, datosPago?.moneda)}
        </p>

        <Tarjeta titulo="Datos de pago" icono={<CreditCardOutlined />}>
          <div className="flex flex-wrap gap-x-8 gap-y-1">
            <span className="text-gray-500">Banco <b className="font-semibold text-gray-800">{texto(datosPago?.banco)}</b></span>
            <span className="text-gray-500">Cuenta <b className="font-semibold text-gray-800">{texto(datosPago?.numero_cuenta)}</b></span>
            <span className="text-gray-500">CCI <b className="font-semibold text-gray-800">{texto(datosPago?.cci)}</b></span>
            <span className="text-gray-500">Moneda <b className="font-semibold text-gray-800">{moneda(datosPago?.moneda)}</b></span>
          </div>
        </Tarjeta>

        <div className="rounded-md bg-gray-50 px-3 py-2 text-[10px] text-gray-400 print:break-inside-avoid print:py-0.5 print:leading-tight">
          <p>Documento emitido electrónicamente conforme al D.S. N° 001-98-TR y normas complementarias.</p>
          <p className="mt-1">Calculado el {detalle.calculado_at} · Parámetros: {detalle.snapshot_parametros_version} · Reglas: {detalle.snapshot_reglas_version}</p>
        </div>
      </div>
    </div>
  );
}
