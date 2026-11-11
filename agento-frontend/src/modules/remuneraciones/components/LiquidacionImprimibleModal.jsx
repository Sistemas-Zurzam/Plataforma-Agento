import { PrinterOutlined } from '@ant-design/icons';
import { Button, Modal, Tag } from 'antd';
import { useEffect, useState } from 'react';
import api from '../../../services/api';

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// Fecha sin hora: se toma la parte YYYY-MM-DD tal cual, sin pasar por la
// zona horaria del navegador (evita mostrar un día menos).
const fecha = (valor) => (valor ? String(valor).slice(0, 10).split('-').reverse().join('/') : '—');

const ESTADOS = { calculada: 'blue', aprobada: 'cyan', pagada: 'green', anulada: 'red' };
const MODOS = { asistido: 'Cálculo asistido', manual: 'Cálculo manual', legado: 'Liquidación anterior (incluye sueldo del mes)' };
const MEDIOS = { transferencia: 'Transferencia', cheque: 'Cheque', efectivo: 'Efectivo', otro: 'Otro' };

// Valor legible de un dato del cálculo (las opciones muestran su etiqueta).
const valorDato = (d, valor) => {
  if (valor === null || valor === undefined) return 'sin dato';
  if (d.tipo === 'monto') return soles(valor);
  if (d.tipo === 'fecha') return fecha(valor);
  if (d.tipo === 'si_no') return valor ? 'Sí' : 'No';
  if (d.tipo === 'opcion') return d.opciones?.find((o) => o.value === valor)?.label ?? valor;
  return valor;
};

/**
 * Hoja de liquidación de beneficios por cese — window.print() con CSS que
 * oculta el resto de la página, mismo patrón que BoletaImprimibleModal.
 */
export default function LiquidacionImprimibleModal({ open, onCancel, liquidacionId }) {
  const [detalle, setDetalle] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!open || !liquidacionId) return;
    let activo = true;
    setLoading(true);
    api.get(`/liquidaciones-cese/${liquidacionId}`)
      .then(({ data }) => { if (activo) setDetalle(data.data); })
      .finally(() => activo && setLoading(false));
    return () => { activo = false; };
  }, [open, liquidacionId]);

  const conceptos = detalle?.conceptos ?? [];
  const ingresos = conceptos.filter((c) => c.tipo === 'ingreso');
  const egresos = conceptos.filter((c) => c.tipo === 'egreso');
  const aportes = conceptos.filter((c) => c.tipo === 'aportacion');
  const datos = Object.entries(detalle?.datos_calculo ?? {});
  const decisiones = datos.filter(([, d]) => d.es_decision);
  const esOficial = detalle?.estado === 'pagada';
  const colaborador = detalle?.colaborador;

  const filas = (lista) => lista.map((c) => (
    <tr key={c.id} className={`border-b border-gray-100 ${c.tipo === 'egreso' ? 'text-red-600' : ''}`}>
      <td className="py-1 pr-2">
        {c.nombre}
        {c.origen === 'ajustado' && <span className="ml-1 text-[10px] font-semibold text-amber-600">(ajustado)</span>}
        {c.origen === 'manual' && <span className="ml-1 text-[10px] font-semibold text-gray-500">(manual)</span>}
        {c.formula_texto && <span className="block text-[10px] text-gray-400">{c.formula_texto}</span>}
        {c.origen === 'ajustado' && <span className="block text-[10px] text-amber-700">Calculado: {soles(c.monto_calculado)} · Motivo: {c.motivo_ajuste}</span>}
        {c.origen === 'manual' && c.motivo_ajuste && <span className="block text-[10px] text-gray-500">Sustento: {c.motivo_ajuste}</span>}
      </td>
      <td className="py-1 text-right font-medium">{c.tipo === 'egreso' ? '-' : ''}{soles(c.monto)}</td>
    </tr>
  ));

  return (
    <Modal
      title={esOficial ? 'Liquidación de beneficios sociales' : 'Previsualización de liquidación'}
      open={open}
      onCancel={onCancel}
      footer={[
        <Button key="cerrar" onClick={onCancel}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} onClick={() => window.print()}>
          {esOficial ? 'Descargar liquidación oficial' : 'Imprimir vista previa'}
        </Button>,
      ]}
      width={{ xs: '95%', sm: '85%', md: 680 }}
      destroyOnHidden
    >
      {loading || !detalle ? (
        <p className="p-6 text-center text-sm text-gray-400">Cargando liquidación...</p>
      ) : (
        <div id="liquidacion-imprimible" className="p-2">
          {!esOficial && (
            <div className="mb-3 rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-center text-xs font-semibold tracking-wide text-orange-700 uppercase">
              Previsualización — documento no oficial, sujeto a cambios hasta que se marque como pagada
            </div>
          )}
          <div className="mb-4 flex items-start justify-between border-b border-gray-100 pb-3">
            <div>
              <p className="text-lg font-semibold text-gray-900">{`${colaborador?.nombres ?? ''} ${colaborador?.apellidos ?? ''}`.trim()}</p>
              <p className="text-xs text-gray-500">{colaborador?.tipo_documento?.toUpperCase()} {colaborador?.numero_documento} · {colaborador?.cargo}</p>
              <p className="text-xs text-gray-500">Período laborado: {fecha(colaborador?.fecha_ingreso)} al {fecha(detalle.fecha_cese)} · Régimen: {detalle.regimen_laboral_snapshot}</p>
              <p className="text-xs text-gray-500">Motivo de cese: {detalle.motivo_cese}{detalle.datos_calculo?.causal_cese ? ` · Causal: ${valorDato(detalle.datos_calculo.causal_cese, detalle.datos_calculo.causal_cese.valor)}` : ''}</p>
              {detalle.regimen_tramos?.length > 0 && (
                <p className="text-xs text-gray-500">Régimen por período: {detalle.regimen_tramos.map((t) => `${t.regimen} (${fecha(t.desde)}–${fecha(t.hasta)})`).join(' · ')}</p>
              )}
              <p className="text-xs text-gray-500">{MODOS[detalle.modo] ?? detalle.modo}</p>
            </div>
            <Tag color={ESTADOS[detalle.estado]}>{detalle.estado} — v{detalle.version}</Tag>
          </div>

          <table className="w-full border-collapse text-sm">
            <tbody>
              <tr><td colSpan={2} className="pt-1 pb-1 text-xs font-semibold text-gray-500 uppercase">Ingresos</td></tr>
              {filas(ingresos)}
              {egresos.length > 0 && <tr><td colSpan={2} className="pt-3 pb-1 text-xs font-semibold text-gray-500 uppercase">Descuentos</td></tr>}
              {filas(egresos)}
            </tbody>
          </table>

          <div className="mt-3 space-y-1 border-t border-gray-200 pt-3 text-sm">
            <div className="flex justify-between"><span>Total ingresos</span><span className="font-semibold text-green-600">{soles(detalle.total_ingresos)}</span></div>
            <div className="flex justify-between"><span>Total descuentos</span><span className="font-semibold text-red-500">{soles(detalle.total_egresos)}</span></div>
            <div className="flex justify-between text-base"><span className="font-semibold">Neto a pagar</span><span className="font-bold">{soles(detalle.neto_pagar)}</span></div>
          </div>

          {aportes.length > 0 && (
            <div className="mt-3 text-[11px] text-gray-600">
              <p className="font-semibold">Aportes del empleador (no se descuentan al trabajador)</p>
              {aportes.map((c) => <p key={c.id} className="flex justify-between"><span>{c.nombre}<span className="block text-gray-400">{c.formula_texto}</span></span><span>{soles(c.monto)}</span></p>)}
            </div>
          )}

          {detalle.datos_calculo && (
            <div className="mt-3 text-[11px] text-gray-600">
              <p className="font-semibold">Datos del cálculo</p>
              <ul className="mt-1 grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                {datos.filter(([, d]) => !d.es_decision).map(([clave, d]) => (
                  <li key={clave}>
                    {d.etiqueta}: <strong>{valorDato(d, d.valor)}</strong>
                    {d.origen === 'manual' && <span className="text-blue-600"> (ingresado; sistema: {valorDato(d, d.valor_sistema)})</span>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {decisiones.length > 0 && (
            <div className="mt-3 text-[11px] text-gray-600">
              <p className="font-semibold">Decisiones laborales</p>
              <ul className="mt-1 space-y-1">
                {decisiones.map(([clave, d]) => (
                  <li key={clave}>{d.etiqueta}: <strong className={d.valor === null ? 'text-amber-700' : ''}>{d.valor === null ? 'Pendiente de validación laboral' : valorDato(d, d.valor)}</strong></li>
                ))}
              </ul>
            </div>
          )}

          {detalle.parametros_version && <p className="mt-2 text-[10px] text-gray-400">Parámetros utilizados: {detalle.parametros_version}</p>}

          {detalle.sustento && <p className="mt-3 text-[11px] text-gray-600"><strong>Sustento:</strong> {detalle.sustento}</p>}

          {detalle.alertas?.length > 0 && (
            <ul className="mt-3 list-disc space-y-1 pl-4 text-[11px] text-amber-700">
              {detalle.alertas.map((alerta) => <li key={alerta}>{alerta}</li>)}
            </ul>
          )}

          {detalle.referencia_pago && (
            <p className="mt-3 text-[11px] text-gray-500">
              Pagado el {fecha(detalle.fecha_pago ?? detalle.pagado_at)}{detalle.medio_pago ? ` · ${MEDIOS[detalle.medio_pago] ?? detalle.medio_pago}` : ''} · Referencia: {detalle.referencia_pago}
            </p>
          )}

          <div className="mt-12 grid grid-cols-2 gap-10 text-center text-xs text-gray-600">
            <div className="border-t border-gray-400 pt-1">Empleador</div>
            <div className="border-t border-gray-400 pt-1">Trabajador<br />{colaborador?.numero_documento}</div>
          </div>
        </div>
      )}

      <style>{`
        @media print {
          #root { display: none !important; }
          body * { visibility: hidden; }
          #liquidacion-imprimible, #liquidacion-imprimible * { visibility: visible; }
          #liquidacion-imprimible { position: absolute; top: 0; left: 0; width: 100%; }
        }
      `}</style>
    </Modal>
  );
}
