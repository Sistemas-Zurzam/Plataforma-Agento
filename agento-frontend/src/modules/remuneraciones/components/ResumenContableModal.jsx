import { BarChartOutlined, FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons';
import { Button, DatePicker, Empty, Modal, Select, Table, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';

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
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function ResumenContableModal({ open, onCancel, ciclo, fetchResumenContable, onVerPlanilla }) {
  const [periodo, setPeriodo] = useState(dayjs());
  const [estado, setEstado] = useState(null);
  const [categoria, setCategoria] = useState(null);
  const [resultado, setResultado] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return;
    setPeriodo(ciclo?.fecha_inicio ? dayjs(ciclo.fecha_inicio) : dayjs());
    setEstado(null);
    setCategoria(null);
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
              { value: 'todos', label: '4ta y 5ta categoría' },
              { value: 'planilla', label: '5ta categoría' },
              { value: 'honorarios', label: '4ta categoría' },
            ]}
          />
          <div className="ml-auto flex gap-2">
            <Tooltip title="Disponible próximamente"><Button disabled icon={<FileExcelOutlined />}>Excel</Button></Tooltip>
            <Tooltip title="Disponible próximamente"><Button disabled icon={<FilePdfOutlined />}>PDF</Button></Tooltip>
          </div>
        </div>

        <Table
          rowKey="empresa_id"
          loading={loading}
          dataSource={resultado?.empresas ?? []}
          columns={columnas}
          pagination={false}
          scroll={{ x: 980 }}
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
                <Table.Summary.Cell index={6} align="right"><strong>{soles(totales.neto_a_pagar)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={7} />
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </div>
    </Modal>
  );
}
