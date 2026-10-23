import { PrinterOutlined } from '@ant-design/icons';
import { Button, Modal } from 'antd';
import { Fragment, useEffect, useMemo, useState } from 'react';
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
const AZUL = '#0B4F94';
const AZUL_TOTAL = '#083A6E';

// Columnas fijas antes de los montos (Empresa y Periodo ya van en el
// encabezado y en las filas de subtotal).
const COLUMNAS_FIJAS = [
  { titulo: 'DNI', clave: 'dni' },
  { titulo: 'Colaborador', clave: 'nombre' },
  { titulo: 'Área', clave: 'area' },
  { titulo: 'Cargo', clave: 'cargo' },
  { titulo: 'Tipo', clave: 'tipo' },
];

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Agrupa columnas contiguas del mismo grupo para la fila superior del encabezado (Ingresos, Bonos, Horas extra…). */
function gruposDeColumnas(columnas) {
  return columnas.reduce((grupos, columna) => {
    const ultimo = grupos[grupos.length - 1];
    if (ultimo && ultimo.grupo === columna.grupo) {
      ultimo.span += 1;
    } else {
      grupos.push({ grupo: columna.grupo, span: 1, clave: columna.clave });
    }
    return grupos;
  }, []);
}

/**
 * Vista imprimible del Reporte Ejecutivo de Remuneraciones — mismo detalle
 * por concepto y agrupado por empresa que el Excel
 * (ReporteEjecutivoRemuneracionesExcelExporter), con las mismas columnas que
 * calcula ReporteEjecutivoRemuneracionesCalculador::detalle() vía
 * /reporte-ejecutivo/datos. "Imprimir" usa window.print() (ver
 * EstilosImpresionReporteEjecutivo, que ajusta el zoom al ancho de la hoja)
 * para que el usuario elija "Guardar como PDF" desde el diálogo del navegador.
 */
export default function ReporteEjecutivoImprimibleModal({ open, onCancel, periodo, estado, categoria, empresaId, cargos, fetchReporteEjecutivoDatos }) {
  const [datos, setDatos] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!open || !periodo) return;
    let activo = true;
    setLoading(true);
    setError(null);
    fetchReporteEjecutivoDatos({
      periodo,
      estado,
      categoria,
      empresa_id: empresaId || undefined,
      cargos: cargos?.length ? cargos : undefined,
    })
      .then((data) => { if (activo) setDatos(data); })
      .catch((err) => { if (activo) setError(err.response?.data?.message ?? 'No hay boletas calculadas para el período y filtros seleccionados.'); })
      .finally(() => { if (activo) setLoading(false); });
    return () => { activo = false; };
  }, [open, periodo, estado, categoria, empresaId, cargos, fetchReporteEjecutivoDatos]);

  const columnas = useMemo(() => datos?.columnas ?? [], [datos]);
  const grupos = useMemo(() => gruposDeColumnas(columnas), [columnas]);

  const celdaEncabezado = 'whitespace-nowrap px-2 py-1 font-semibold';
  const celda = 'whitespace-nowrap px-2 py-1';

  const filaTotales = (etiqueta, totales, color) => (
    <tr style={{ backgroundColor: color }} className="font-semibold text-white">
      <td colSpan={COLUMNAS_FIJAS.length} className={celda}>{etiqueta}</td>
      {columnas.map((columna) => (
        <td key={columna.clave} className={`${celda} text-right`}>{soles(totales[columna.clave])}</td>
      ))}
      <td />
    </tr>
  );

  return (
    <Modal
      title="Reporte ejecutivo de remuneraciones"
      open={open}
      onCancel={onCancel}
      rootClassName="reporte-ejecutivo-print-root"
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

          <div className="reporte-ejecutivo-scroll overflow-x-auto">
            <table className="reporte-ejecutivo-tabla w-full border-collapse text-xs">
              <thead>
                <tr style={{ backgroundColor: AZUL }} className="text-white">
                  <th colSpan={COLUMNAS_FIJAS.length} className={`${celdaEncabezado} text-left`}>Colaborador</th>
                  {grupos.map((g) => (
                    <th key={g.clave} colSpan={g.span} className={`${celdaEncabezado} border-l border-white/30 text-center`}>{g.grupo}</th>
                  ))}
                  <th />
                </tr>
                <tr style={{ backgroundColor: AZUL }} className="text-white">
                  {COLUMNAS_FIJAS.map((columna) => (
                    <th key={columna.clave} className={`${celdaEncabezado} text-left`}>{columna.titulo}</th>
                  ))}
                  {columnas.map((columna) => (
                    <th key={columna.clave} className={`${celdaEncabezado} text-right`} style={columna.total ? { backgroundColor: AZUL_TOTAL } : undefined}>
                      {columna.titulo}
                    </th>
                  ))}
                  <th className={`${celdaEncabezado} text-left`}>Estado</th>
                </tr>
              </thead>
              <tbody>
                {datos.empresas.map((grupo, indice) => {
                  const colores = PALETA_EMPRESAS[indice % PALETA_EMPRESAS.length];
                  return (
                    <Fragment key={grupo.empresa}>
                      {grupo.filas.map((fila) => (
                        <tr key={`${grupo.empresa}-${fila.boleta_id}`} style={{ backgroundColor: colores.claro }}>
                          {COLUMNAS_FIJAS.map((columna) => (
                            <td key={columna.clave} className={celda}>{fila[columna.clave]}</td>
                          ))}
                          {columnas.map((columna) => (
                            <td key={columna.clave} className={`${celda} text-right ${columna.total ? 'font-semibold' : ''}`}>
                              {soles(fila.montos[columna.clave])}
                            </td>
                          ))}
                          <td className={celda}>{fila.estado}</td>
                        </tr>
                      ))}
                      {filaTotales(`Total ${grupo.empresa} (${grupo.colaboradores} colaboradores)`, grupo.subtotal, colores.oscuro)}
                    </Fragment>
                  );
                })}
                {filaTotales(`Total general (${datos.total_colaboradores} colaboradores)`, datos.total_general, AZUL)}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <EstilosImpresionReporteEjecutivo targetId="reporte-ejecutivo-imprimible" />
    </Modal>
  );
}
