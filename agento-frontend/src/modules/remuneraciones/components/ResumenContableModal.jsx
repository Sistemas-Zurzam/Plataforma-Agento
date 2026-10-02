import { BarChartOutlined, FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons';
import { Button, DatePicker, Empty, message, Modal, Select, Table, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import ReporteEjecutivoImprimibleModal from './ReporteEjecutivoImprimibleModal';

const ESTADO_COLOR = {
  borrador: 'default',
  abierto: 'blue',
  calculado: 'cyan',
  cerrado: 'green',
  reabierto: 'orange',
  pagado: 'purple',
  mixto: 'default',
};

function soles(valor) {
  const texto = `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  return <span className="whitespace-nowrap">{texto}</span>;
}

export default function ResumenContableModal({ open, onCancel, ciclo, fetchResumenContable, fetchReporteEjecutivoDatos, exportarReporteEjecutivoExcel, onVerPlanilla }) {
  const [periodo, setPeriodo] = useState(dayjs());
  const [estado, setEstado] = useState(null);
  const [categoria, setCategoria] = useState(null);
  const [empresaExport, setEmpresaExport] = useState(null);
  const [cargosExport, setCargosExport] = useState([]);
  const [resultado, setResultado] = useState(null);
  const [loading, setLoading] = useState(false);
  const [exportando, setExportando] = useState(false);
  const [pdfModalOpen, setPdfModalOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    setPeriodo(ciclo?.fecha_inicio ? dayjs(ciclo.fecha_inicio) : dayjs());
    setEstado(null);
    setCategoria(null);
    setEmpresaExport(null);
    setCargosExport([]);
  }, [open, ciclo?.fecha_inicio]);

  useEffect(() => {
    if (!open || !periodo) return;
    let activo = true;
    setLoading(true);
    fetchResumenContable({
      periodo: periodo.format('YYYY-MM'),
      estado: estado || undefined,
      categoria: categoria || undefined,
    }).then((data) => {
      if (activo) setResultado(data);
    }).catch(() => {
      if (activo) setResultado({ empresas: [], totales: null });
    }).finally(() => {
      if (activo) setLoading(false);
    });
    return () => { activo = false; };
  }, [open, periodo, estado, categoria, fetchResumenContable]);

  const columnas = useMemo(() => [
    {
      title: 'Empresa',
      dataIndex: 'empresa',
      render: (valor, fila) => (
        <div>
          <p className="font-medium text-gray-900">{valor}</p>
          {fila.razon_social && fila.razon_social !== valor && <p className="text-xs text-gray-400">{fila.razon_social}</p>}
        </div>
      ),
    },
    { title: 'Estado', dataIndex: 'estado', width: 105, render: (valor) => <Tag color={ESTADO_COLOR[valor] ?? 'default'}>{valor}</Tag> },
    { title: 'Colaboradores', dataIndex: 'colaboradores', align: 'right', width: 125 },
    { title: 'Ingresos', dataIndex: 'total_ingresos', align: 'right', render: soles },
    { title: 'Descuentos', dataIndex: 'total_egresos', align: 'right', render: soles },
    { title: 'Aportaciones', dataIndex: 'total_aportaciones', align: 'right', render: soles },
    {
      title: (
        <Tooltip title="Ajuste neto de planillas complementarias aprobadas/pagadas del período (bonos, comisiones, descuentos y correcciones de asistencia) — ya incluido en Neto.">
          Complementarias
        </Tooltip>
      ),
      dataIndex: 'total_complementarias',
      align: 'right',
      render: (valor) => <span className={Number(valor) >= 0 ? 'text-green-600' : 'text-red-500'}>{soles(valor)}</span>,
    },
    { title: 'Neto', dataIndex: 'neto_a_pagar', align: 'right', render: (valor) => <strong>{soles(valor)}</strong> },
    {
      title: 'Acciones',
      width: 115,
      render: (_, fila) => (
        <Button size="small" disabled={!fila.ciclo_id} onClick={() => onVerPlanilla(fila.ciclo_id)}>
          Ver planilla
        </Button>
      ),
    },
  ], [onVerPlanilla]);

  const totales = resultado?.totales;

  const opcionesEmpresa = useMemo(() => (resultado?.empresas ?? [])
    .map((fila) => ({ value: fila.empresa_id, label: fila.empresa })), [resultado]);

  // Sin empresa elegida se listan los cargos de todas, sin repetir.
  const opcionesCargo = useMemo(() => {
    const cargos = (resultado?.cargos ?? [])
      .filter((fila) => !empresaExport || fila.empresa_id === empresaExport)
      .map((fila) => fila.cargo);
    return [...new Set(cargos)].sort((a, b) => a.localeCompare(b)).map((cargo) => ({ value: cargo, label: cargo }));
  }, [resultado, empresaExport]);

  // Al cambiar empresa o filtros, se descartan cargos que ya no aplican.
  useEffect(() => {
    const vigentes = new Set(opcionesCargo.map((opcion) => opcion.value));
    setCargosExport((actuales) => {
      const filtrados = actuales.filter((cargo) => vigentes.has(cargo));
      return filtrados.length === actuales.length ? actuales : filtrados;
    });
  }, [opcionesCargo]);

  useEffect(() => {
    if (empresaExport && resultado && !resultado.empresas?.some((fila) => fila.empresa_id === empresaExport)) {
      setEmpresaExport(null);
    }
  }, [resultado, empresaExport]);

  const handleExportarExcel = async () => {
    if (!periodo) return;

    setExportando(true);
    try {
      await exportarReporteEjecutivoExcel({
        periodo: periodo.format('YYYY-MM'),
        estado: estado || undefined,
        categoria: categoria || undefined,
        empresaId: empresaExport || undefined,
        cargos: cargosExport,
      });
      message.success('Reporte ejecutivo de remuneraciones generado');
    } catch (error) {
      // responseType blob: el mensaje de error del backend llega como Blob.
      const texto = error.response?.data instanceof Blob ? await error.response.data.text().catch(() => '') : '';
      let detalle = null;
      try { detalle = JSON.parse(texto)?.message; } catch { /* respuesta sin JSON */ }
      message.error(detalle ?? 'No se pudo generar el reporte ejecutivo de remuneraciones');
    } finally {
      setExportando(false);
    }
  };

  return (
    <Modal
      title={<span className="flex items-center gap-2"><BarChartOutlined /> Resumen contable global</span>}
      open={open}
      onCancel={onCancel}
      footer={null}
      width={{ xs: '96%', sm: '94%', md: 1180 }}
      destroyOnHidden
    >
      <div className="space-y-4">
        <p className="text-sm text-gray-500">Consolidado transversal de todas las empresas a las que tienes acceso. No modifica el ciclo seleccionado.</p>

        <div className="flex flex-wrap items-center gap-2">
          <DatePicker picker="month" value={periodo} onChange={setPeriodo} format="MMMM YYYY" allowClear={false} />
          <Select
            className="w-40"
            value={estado ?? 'todos'}
            onChange={(valor) => setEstado(valor === 'todos' ? null : valor)}
            options={[
              { value: 'todos', label: 'Todos los estados' },
              { value: 'borrador', label: 'Borrador' },
              { value: 'abierto', label: 'Abierto' },
              { value: 'calculado', label: 'Calculado' },
              { value: 'cerrado', label: 'Cerrado' },
              { value: 'reabierto', label: 'Reabierto' },
              { value: 'pagado', label: 'Pagado' },
            ]}
          />
          <Select
            className="w-48"
            value={categoria ?? 'todos'}
            onChange={(valor) => setCategoria(valor === 'todos' ? null : valor)}
            options={[
              { value: 'todos', label: 'Planilla y RH' },
              { value: 'planilla', label: 'Solo planilla (5ta)' },
              { value: 'honorarios', label: 'Solo RH (4ta)' },
            ]}
          />
          <div className="ml-auto flex flex-wrap items-center gap-2">
            <Select
              allowClear
              showSearch
              optionFilterProp="label"
              className="w-44"
              placeholder="Empresa: todas"
              value={empresaExport}
              onChange={(valor) => setEmpresaExport(valor ?? null)}
              options={opcionesEmpresa}
            />
            <Select
              mode="multiple"
              allowClear
              showSearch
              optionFilterProp="label"
              maxTagCount="responsive"
              className="w-56"
              placeholder="Cargo: todos"
              value={cargosExport}
              onChange={setCargosExport}
              options={opcionesCargo}
              notFoundContent="Sin cargos en este período"
            />
            <Tooltip title="Reporte ejecutivo de remuneraciones: desglose de AFP/ONP y ESSALUD por colaborador, agrupado por empresa. Respeta estado, Planilla/RH, empresa y cargo.">
              <Button icon={<FileExcelOutlined />} loading={exportando} onClick={handleExportarExcel}>Excel</Button>
            </Tooltip>
            <Tooltip title="Reporte ejecutivo de remuneraciones, listo para guardar como PDF desde el navegador. Respeta estado, Planilla/RH, empresa y cargo.">
              <Button icon={<FilePdfOutlined />} onClick={() => setPdfModalOpen(true)}>PDF</Button>
            </Tooltip>
          </div>
        </div>

        <Table
          rowKey="empresa_id"
          loading={loading}
          dataSource={resultado?.empresas ?? []}
          columns={columnas}
          pagination={false}
          scroll={{ x: 1120 }}
          locale={{ emptyText: <Empty description="No hay ciclos para los filtros seleccionados" /> }}
          summary={() => totales && (
            <Table.Summary fixed>
              <Table.Summary.Row>
                <Table.Summary.Cell index={0}><strong>Totales ({totales.empresas} empresas)</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={1} />
                <Table.Summary.Cell index={2} align="right"><strong>{totales.colaboradores}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={3} align="right"><strong>{soles(totales.total_ingresos)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={4} align="right"><strong>{soles(totales.total_egresos)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={5} align="right"><strong>{soles(totales.total_aportaciones)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={6} align="right">
                  <strong className={totales.total_complementarias >= 0 ? 'text-green-600' : 'text-red-500'}>{soles(totales.total_complementarias)}</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={7} align="right"><strong>{soles(totales.neto_a_pagar)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={8} />
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </div>

      <ReporteEjecutivoImprimibleModal
        open={pdfModalOpen}
        onCancel={() => setPdfModalOpen(false)}
        periodo={periodo?.format('YYYY-MM')}
        estado={estado || undefined}
        categoria={categoria || undefined}
        empresaId={empresaExport || undefined}
        cargos={cargosExport}
        fetchReporteEjecutivoDatos={fetchReporteEjecutivoDatos}
      />
    </Modal>
  );
}
