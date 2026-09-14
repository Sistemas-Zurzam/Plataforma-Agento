import { PrinterOutlined } from '@ant-design/icons';
import { Button, Modal } from 'antd';
import { Fragment, useEffect, useState } from 'react';
import EstilosImpresionReporteEjecutivo from './EstilosImpresionReporteEjecutivo';

// Mismo criterio de color que ReporteEjecutivoRemuneracionesExcelExporter::PALETA_EMPRESAS
// (un color por empresa, rotando si hay más de 5), para que el PDF impreso
// se vea consistente con el Excel del mismo reporte.
const PALETA_EMPRESAS = [
  { claro: '#D9EAF7', oscuro: '#0B4F94' },
  { claro: '#E2F0D9', oscuro: '#2E7D32' },
  { claro: '#FFF2CC', oscuro: '#8A6D00' },
  { claro: '#EAE0F5', oscuro: '#5B2C87' },
  { claro: '#D9F0EE', oscuro: '#007A6E' },
];

const COLUMNAS = [
  { titulo: 'DNI', clave: 'dni', align: 'left' },
  { titulo: 'Colaborador', clave: 'nombre', align: 'left' },
  { titulo: 'Tipo', clave: 'tipo', align: 'left' },
  { titulo: 'Sueldo bruto', clave: 'sueldo_bruto' },
  { titulo: 'Bonos / HE', clave: 'bonos' },
  { titulo: 'Base AFP', clave: 'base_afp' },
  { titulo: 'AFP 10%', clave: 'aporte_obligatorio' },
  { titulo: 'Prima seguro', clave: 'prima_seguro' },
  { titulo: 'Comisión AFP', clave: 'comision_afp' },
  { titulo: 'Total AFP', clave: 'total_afp' },
  { titulo: 'Otros descuentos', clave: 'otros_descuentos' },
  { titulo: 'Neto a pagar', clave: 'neto' },
  { titulo: 'ESSALUD', clave: 'essalud' },
  { titulo: 'Costo empresa', clave: 'costo_empresa' },
  { titulo: 'Estado', clave: 'estado', align: 'left' },
];

const COLUMNAS_MONTO = COLUMNAS.filter((c) => !c.align).map((c) => c.clave);

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * Vista imprimible del Reporte Ejecutivo de Remuneraciones — mismo detalle
 * agrupado por empresa que el Excel (ReporteEjecutivoRemuneracionesExcelExporter),
 * consumiendo los mismos datos ya calculados por
 * ReporteEjecutivoRemuneracionesCalculador vía /reporte-ejecutivo/datos.
 * "Imprimir" usa window.print() (ver EstilosImpresionReporteEjecutivo) para
 * que el usuario elija "Guardar como PDF" desde el diálogo del navegador —
 * mismo mecanismo que la boleta, sin agregar una librería de PDF al backend.
 */
export default function ReporteEjecutivoImprimibleModal({ open, onCancel, periodo, estado, categoria, fetchReporteEjecutivoDatos }) {
  const [datos, setDatos] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!open || !periodo) return;
    let activo = true;
    setLoading(true);
    setError(null);
    fetchReporteEjecutivoDatos({ periodo, estado, categoria })
      .then((data) => { if (activo) setDatos(data); })
      .catch(() => { if (activo) setError('No hay boletas pagadas para el período y filtros seleccionados.'); })
      .finally(() => { if (activo) setLoading(false); });
    return () => { activo = false; };
  }, [open, periodo, estado, categoria, fetchReporteEjecutivoDatos]);

  return (
    <Modal
      title="Reporte ejecutivo de remuneraciones"
      open={open}
      onCancel={onCancel}
      footer={[
        <Button key="cerrar" onClick={onCancel}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} disabled={!datos} onClick={() => window.print()}>
          Guardar como PDF
        </Button>,
      ]}
      width={{ xs: '96%', sm: '94%', md: 1180 }}
      destroyOnHidden
    >
      {loading && <p className="p-6 text-center text-sm text-gray-400">Cargando reporte...</p>}
      {!loading && error && <p className="p-6 text-center text-sm text-red-500">{error}</p>}

      {!loading && !error && datos && (
        <div id="reporte-ejecutivo-imprimible" className="space-y-3">
          <div>
            <h2 className="text-lg font-bold text-agento-blue">REPORTE EJECUTIVO DE REMUNERACIONES</h2>
            <p className="text-sm text-gray-600">
              Periodo: <strong>{datos.periodo}</strong> · Empresas incluidas: <strong>{datos.empresas.length}</strong> · Colaboradores: <strong>{datos.total_colaboradores}</strong>
            </p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-xs">
              <thead>
                <tr style={{ backgroundColor: '#0B4F94' }} className="text-white">
                  {COLUMNAS.map((columna) => (
                    <th key={columna.clave} className="whitespace-nowrap px-2 py-1 font-semibold" style={{ textAlign: columna.align ?? 'right' }}>
                      {columna.titulo}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {datos.empresas.map((grupo, indice) => {
                  const colores = PALETA_EMPRESAS[indice % PALETA_EMPRESAS.length];
                  return (
                    <Fragment key={grupo.empresa}>
                      {grupo.filas.map((fila) => (
                        <tr key={`${grupo.empresa}-${fila.dni}`} style={{ backgroundColor: colores.claro }}>
                          {COLUMNAS.map((columna) => (
                            <td key={columna.clave} className="whitespace-nowrap px-2 py-1" style={{ textAlign: columna.align ?? 'right' }}>
                              {columna.align ? fila[columna.clave] : soles(fila[columna.clave])}
                            </td>
                          ))}
                        </tr>
                      ))}
                      <tr style={{ backgroundColor: colores.oscuro }} className="font-semibold text-white">
                        <td colSpan={3} className="whitespace-nowrap px-2 py-1">
                          Total {grupo.empresa} ({grupo.colaboradores} colaboradores)
                        </td>
                        {COLUMNAS_MONTO.map((clave) => (
                          <td key={clave} className="whitespace-nowrap px-2 py-1 text-right">{soles(grupo.subtotal[clave])}</td>
                        ))}
                        <td />
                      </tr>
                    </Fragment>
                  );
                })}
                <tr style={{ backgroundColor: '#0B4F94' }} className="font-semibold text-white">
                  <td colSpan={3} className="whitespace-nowrap px-2 py-1">
                    Total general ({datos.total_colaboradores} colaboradores)
                  </td>
                  {COLUMNAS_MONTO.map((clave) => (
                    <td key={clave} className="whitespace-nowrap px-2 py-1 text-right">{soles(datos.total_general[clave])}</td>
                  ))}
                  <td />
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}

      <EstilosImpresionReporteEjecutivo targetId="reporte-ejecutivo-imprimible" />
    </Modal>
  );
}
