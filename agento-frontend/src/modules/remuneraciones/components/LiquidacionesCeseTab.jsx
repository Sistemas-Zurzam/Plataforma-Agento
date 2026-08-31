import { CheckOutlined, CloseCircleOutlined, DollarOutlined, PrinterOutlined, ReloadOutlined } from '@ant-design/icons';
import { App, Button, DatePicker, Input, Select, Space, Table, Tag } from 'antd';
import { useCallback, useEffect, useState } from 'react';
import api from '../../../services/api';
import LiquidacionImprimibleModal from './LiquidacionImprimibleModal';

const { RangePicker } = DatePicker;
const soles = (valor) => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(Number(valor ?? 0));
const colores = { calculada: 'blue', aprobada: 'cyan', pagada: 'green', anulada: 'red' };
const ESTADOS = [
  { value: 'calculada', label: 'Calculada' },
  { value: 'aprobada', label: 'Aprobada' },
  { value: 'pagada', label: 'Pagada' },
  { value: 'anulada', label: 'Anulada' },
];

export default function LiquidacionesCeseTab({ empresaId, puedeAprobar, puedePagar }) {
  const { message, modal } = App.useApp();
  const [filas, setFilas] = useState([]);
  const [loading, setLoading] = useState(false);
  const [paginacion, setPaginacion] = useState({ current: 1, pageSize: 20, total: 0 });
  const [filtros, setFiltros] = useState({ estado: undefined, colaborador: undefined, rango: null });
  const [imprimiendoId, setImprimiendoId] = useState(null);

  const cargar = useCallback(async (page = 1, pageSize = 20) => {
    setLoading(true);
    try {
      const { data } = await api.get('/liquidaciones-cese', {
        params: {
          page,
          per_page: pageSize,
          estado: filtros.estado,
          colaborador: filtros.colaborador || undefined,
          fecha_desde: filtros.rango?.[0]?.format('YYYY-MM-DD'),
          fecha_hasta: filtros.rango?.[1]?.format('YYYY-MM-DD'),
        },
      });
      setFilas(data.data ?? []);
      setPaginacion({ current: data.current_page, pageSize: data.per_page, total: data.total });
    } finally { setLoading(false); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtros]);

  useEffect(() => {
    const timer = setTimeout(() => cargar(1, paginacion.pageSize), 0);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cargar, empresaId]);

  const recargar = () => cargar(paginacion.current, paginacion.pageSize);

  const aprobar = async (fila) => {
    await api.patch(`/liquidaciones-cese/${fila.id}/aprobar`);
    message.success('Liquidación aprobada');
    recargar();
  };

  const pedirTexto = (titulo, etiqueta, onOk, danger = false) => {
    let valor = '';
    modal.confirm({
      title: titulo,
      content: <Input className="mt-3" placeholder={etiqueta} onChange={(e) => { valor = e.target.value; }} />,
      okText: 'Confirmar', okButtonProps: { danger },
      onOk: async () => {
        if (!valor.trim()) { message.warning(`Ingresa ${etiqueta.toLowerCase()}`); throw new Error('dato_requerido'); }
        await onOk(valor.trim());
        recargar();
      },
    });
  };

  const columns = [
    { title: 'Colaborador', render: (_, f) => <div><strong>{`${f.colaborador?.nombres ?? ''} ${f.colaborador?.apellidos ?? ''}`.trim()}</strong><p className="text-xs text-gray-400">{f.colaborador?.legajo}</p></div> },
    { title: 'Fecha de cese', dataIndex: 'fecha_cese' },
    { title: 'Remuneración', dataIndex: 'remuneracion_snapshot', render: soles },
    { title: 'Neto', dataIndex: 'neto_pagar', render: (v) => <strong>{soles(v)}</strong> },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <Tag color={colores[v]}>{v}</Tag> },
    { title: 'Acciones', render: (_, fila) => <Space>
      <Button size="small" icon={<PrinterOutlined />} onClick={() => setImprimiendoId(fila.id)}>Ver</Button>
      {fila.estado === 'calculada' && puedeAprobar && <Button size="small" icon={<CheckOutlined />} onClick={() => aprobar(fila)}>Aprobar</Button>}
      {fila.estado === 'aprobada' && puedePagar && <Button size="small" type="primary" icon={<DollarOutlined />} onClick={() => pedirTexto('Registrar pago', 'Referencia de pago', (referencia_pago) => api.patch(`/liquidaciones-cese/${fila.id}/pagar`, { referencia_pago }))}>Pagar</Button>}
      {['calculada', 'aprobada'].includes(fila.estado) && puedeAprobar && <Button size="small" danger icon={<CloseCircleOutlined />} onClick={() => pedirTexto('Anular y revertir cese', 'Motivo de anulación', (motivo) => api.patch(`/liquidaciones-cese/${fila.id}/anular-revertir`, { motivo }), true)}>Revertir</Button>}
    </Space> },
  ];

  return <div className="space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <Space wrap>
        <Select className="w-40" allowClear placeholder="Estado" options={ESTADOS} value={filtros.estado} onChange={(estado) => setFiltros((f) => ({ ...f, estado }))} />
        <Input.Search className="w-56" allowClear placeholder="Buscar colaborador" onSearch={(colaborador) => setFiltros((f) => ({ ...f, colaborador }))} />
        <RangePicker format="DD/MM/YYYY" value={filtros.rango} onChange={(rango) => setFiltros((f) => ({ ...f, rango }))} />
      </Space>
      <Button icon={<ReloadOutlined />} onClick={recargar}>Actualizar</Button>
    </div>
    <Table
      rowKey="id" loading={loading} dataSource={filas} columns={columns} scroll={{ x: 900 }}
      pagination={{ ...paginacion, showSizeChanger: true, onChange: (page, pageSize) => cargar(page, pageSize) }}
      locale={{ emptyText: 'Todavía no hay liquidaciones por cese' }}
    />
    <LiquidacionImprimibleModal open={imprimiendoId !== null} liquidacionId={imprimiendoId} onCancel={() => setImprimiendoId(null)} />
  </div>;
}
