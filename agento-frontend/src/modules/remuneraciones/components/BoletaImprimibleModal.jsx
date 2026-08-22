import { PrinterOutlined } from '@ant-design/icons';
import { Button, Modal, Tag } from 'antd';
import { useEffect, useState } from 'react';

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function BloqueImprimible({ titulo, lineas }) {
  if (!lineas?.length) return null;

  return (
    <div className="mb-3">
      <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">{titulo}</p>
      <table className="w-full border-collapse text-sm">
        <tbody>
          {lineas.map((linea) => (
            <tr key={linea.id} className="border-b border-gray-100">
              <td className="py-1 pr-2">
                {linea.nombre}
                {linea.formula_texto && <span className="block text-[10px] text-gray-400">{linea.formula_texto}</span>}
              </td>
              <td className="py-1 text-right font-medium">{soles(linea.monto)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Vista imprimible de una boleta YA calculada — renderiza el snapshot tal
 * cual quedó guardado, nunca recalcula nada. "Imprimir" usa window.print()
 * con CSS que oculta el resto de la página, para que el usuario elija
 * "Guardar como PDF" desde el diálogo del navegador — evita agregar una
 * librería de generación de PDF en el backend solo para esto.
 */
export default function BoletaImprimibleModal({ open, onCancel, boletaId, verBoleta }) {
  const [detalle, setDetalle] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!open || !boletaId) return;
    let activo = true;
    setLoading(true);
    verBoleta(boletaId).then((data) => { if (activo) setDetalle(data); }).finally(() => activo && setLoading(false));
    return () => { activo = false; };
  }, [open, boletaId, verBoleta]);

  const conceptos = detalle?.conceptos ?? [];
  const esOficial = detalle?.estado === 'pagada';

  return (
    <Modal
      title={esOficial ? 'Boleta oficial' : 'Previsualización de boleta'}
      open={open}
      onCancel={onCancel}
      footer={[
        <Button key="cerrar" onClick={onCancel}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} onClick={() => window.print()}>
          {esOficial ? 'Descargar boleta oficial' : 'Imprimir vista previa'}
        </Button>,
      ]}
      width={{ xs: '95%', sm: '85%', md: 640 }}
      destroyOnHidden
    >
      {loading || !detalle ? (
        <p className="p-6 text-center text-sm text-gray-400">Cargando boleta...</p>
      ) : (
        <div id="boleta-imprimible" className="p-2">
          {!esOficial && (
            <div className="mb-3 rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-center text-xs font-semibold tracking-wide text-orange-700 uppercase">
              Previsualización — documento no oficial, sujeto a cambios hasta que la boleta se marque como pagada
            </div>
          )}
          <div className="mb-4 flex items-start justify-between border-b border-gray-100 pb-3">
            <div>
              <p className="text-lg font-semibold text-gray-900">{detalle.colaborador?.nombre_completo}</p>
              <p className="text-xs text-gray-500">{detalle.colaborador?.legajo} · {detalle.colaborador?.empresa}</p>
              <p className="text-xs text-gray-500">Régimen: {detalle.regimen_laboral}</p>
            </div>
            <Tag color={esOficial ? 'green' : 'orange'}>{esOficial ? 'Oficial' : `Versión ${detalle.version} — no oficial`}</Tag>
          </div>

          <BloqueImprimible titulo="Ingresos" lineas={conceptos.filter((c) => c.tipo === 'ingreso')} />
          <BloqueImprimible titulo="Egresos / descuentos" lineas={conceptos.filter((c) => c.tipo === 'egreso')} />
          <BloqueImprimible titulo="Aportaciones del empleador" lineas={conceptos.filter((c) => c.tipo === 'aportacion')} />

          <div className="mt-3 space-y-1 border-t border-gray-200 pt-3 text-sm">
            <div className="flex justify-between"><span>Total ingresos</span><span className="font-semibold text-green-600">{soles(detalle.total_ingresos)}</span></div>
            <div className="flex justify-between"><span>Total descuentos</span><span className="font-semibold text-red-500">{soles(detalle.total_egresos)}</span></div>
            <div className="flex justify-between text-base"><span className="font-semibold">Neto a pagar</span><span className="font-bold">{soles(detalle.neto_a_pagar)}</span></div>
          </div>

          <p className="mt-4 text-[10px] text-gray-400">
            Calculado el {detalle.calculado_at} · Parámetros: {detalle.snapshot_parametros_version} · Reglas: {detalle.snapshot_reglas_version}
          </p>
        </div>
      )}

      <style>{`
        @media print {
          body * { visibility: hidden; }
          #boleta-imprimible, #boleta-imprimible * { visibility: visible; }
          #boleta-imprimible { position: fixed; top: 0; left: 0; width: 100%; }
        }
      `}</style>
    </Modal>
  );
}
