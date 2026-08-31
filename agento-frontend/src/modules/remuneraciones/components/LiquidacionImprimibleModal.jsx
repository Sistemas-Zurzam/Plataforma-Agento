import { PrinterOutlined } from '@ant-design/icons';
import { Button, Modal, Tag } from 'antd';
import { useEffect, useState } from 'react';
import api from '../../../services/api';

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const ESTADOS = { calculada: 'blue', aprobada: 'cyan', pagada: 'green', anulada: 'red' };

/**
 * Vista imprimible de una liquidación por cese — mismo patrón que
 * BoletaImprimibleModal: window.print() con CSS que oculta el resto de la
 * página, sin generar PDF en el backend. Muestra también los conceptos
 * marcados como no incluidos (tachados) porque forman parte del respaldo
 * de por qué el neto quedó en ese monto, no solo el detalle final.
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
  const esOficial = detalle?.estado === 'pagada';

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
      width={{ xs: '95%', sm: '85%', md: 640 }}
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
              <p className="text-lg font-semibold text-gray-900">{`${detalle.colaborador?.nombres ?? ''} ${detalle.colaborador?.apellidos ?? ''}`.trim()}</p>
              <p className="text-xs text-gray-500">{detalle.colaborador?.legajo}</p>
              <p className="text-xs text-gray-500">Fecha de cese: {detalle.fecha_cese} · Régimen: {detalle.regimen_laboral_snapshot}</p>
              <p className="text-xs text-gray-500">Motivo: {detalle.motivo_cese}</p>
            </div>
            <Tag color={ESTADOS[detalle.estado]}>{detalle.estado} — v{detalle.version}</Tag>
          </div>

          <table className="w-full border-collapse text-sm">
            <tbody>
              {conceptos.map((c) => (
                <tr key={c.id} className={`border-b border-gray-100 ${c.tipo === 'egreso' ? 'text-red-600' : ''}`}>
                  <td className="py-1 pr-2">
                    {c.nombre}
                    {c.formula_texto && <span className="block text-[10px] text-gray-400">{c.formula_texto}</span>}
                  </td>
                  <td className="py-1 text-right font-medium">{c.tipo === 'egreso' ? '-' : ''}{soles(c.monto)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {detalle.alertas?.length > 0 && (
            <ul className="mt-3 list-disc space-y-1 pl-4 text-[11px] text-amber-700">
              {detalle.alertas.map((alerta) => <li key={alerta}>{alerta}</li>)}
            </ul>
          )}

          <div className="mt-3 space-y-1 border-t border-gray-200 pt-3 text-sm">
            <div className="flex justify-between"><span>Total ingresos</span><span className="font-semibold text-green-600">{soles(detalle.total_ingresos)}</span></div>
            <div className="flex justify-between"><span>Total egresos</span><span className="font-semibold text-red-500">{soles(detalle.total_egresos)}</span></div>
            <div className="flex justify-between text-base"><span className="font-semibold">Neto a pagar</span><span className="font-bold">{soles(detalle.neto_pagar)}</span></div>
          </div>

          {detalle.referencia_pago && (
            <p className="mt-4 text-[10px] text-gray-400">Referencia de pago: {detalle.referencia_pago}</p>
          )}
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
