import { CheckOutlined, CloseCircleOutlined, DollarOutlined, PrinterOutlined, ReloadOutlined } from '@ant-design/icons';
import { App, Button, DatePicker, Form, Input, Modal, Select, Space, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useCallback, useEffect, useState } from 'react';
import api from '../../../services/api';
import LiquidacionImprimibleModal from './LiquidacionImprimibleModal';

const { RangePicker } = DatePicker;
const soles = (valor) => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(Number(valor ?? 0));
const fecha = (valor) => (valor ? String(valor).slice(0, 10).split('-').reverse().join('/') : '—');
const colores = { calculada: 'blue', aprobada: 'cyan', pagada: 'green', anulada: 'red' };
const ESTADOS = [
  { value: 'calculada', label: 'Calculada' },
  { value: 'aprobada', label: 'Aprobada' },
  { value: 'pagada', label: 'Pagada' },
  { value: 'anulada', label: 'Anulada' },
];
const MODOS = { asistido: ['Asistido', 'blue'], manual: ['Manual', 'purple'], legado: ['Anterior', 'default'] };
const MEDIOS = [
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'efectivo', label: 'Efectivo' },
  { value: 'otro', label: 'Otro' },
];

const mensajeError = (error, porDefecto) => {
  const errores = error.response?.data?.errors;
  return errores ? Object.values(errores)[0]?.[0] : (error.response?.data?.message ?? porDefecto);
};

export default function LiquidacionesCeseTab({ empresaId, puedeAprobar, puedePagar, recargarSenal }) {
  const { message, modal } = App.useApp();
  const [filas, setFilas] = useState([]);
  const [loading, setLoading] = useState(false);
  const [paginacion, setPaginacion] = useState({ current: 1, pageSize: 20, total: 0 });
  const [filtros, setFiltros] = useState({ estado: undefined, colaborador: undefined, rango: null });
  const [imprimiendoId, setImprimiendoId] = useState(null);
  const [pagando, setPagando] = useState(null);
  const [registrandoPago, setRegistrandoPago] = useState(false);
  const [formPago] = Form.useForm();

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
    } catch (error) {
      message.error(mensajeError(error, 'No se pudieron cargar las liquidaciones'));
    } finally { setLoading(false); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtros]);

  useEffect(() => {
    const timer = setTimeout(() => cargar(1, paginacion.pageSize), 0);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cargar, empresaId, recargarSenal]);

  const recargar = () => cargar(paginacion.current, paginacion.pageSize);

  const aprobar = async (fila) => {
    try {
      await api.patch(`/liquidaciones-cese/${fila.id}/aprobar`);
      message.success('Liquidación aprobada');
      recargar();
    } catch (error) {
      message.error(mensajeError(error, 'No se pudo aprobar la liquidación'));
    }
  };

  const anular = (fila) => {
    let motivo = '';
    modal.confirm({
      title: 'Anular liquidación',
      content: (
        <div className="mt-3 space-y-2">
          <p className="text-sm text-gray-500">El colaborador sigue cesado y vuelve a "Pendientes de liquidar" para registrar una nueva liquidación. Para revertir el cese, usa "Reactivar" en su ficha.</p>
          <Input.TextArea rows={2} placeholder="Motivo de anulación" onChange={(e) => { motivo = e.target.value; }} />
        </div>
      ),
      okText: 'Anular', okButtonProps: { danger: true },
      onOk: async () => {
        if (!motivo.trim()) { message.warning('Ingresa el motivo de anulación'); throw new Error('dato_requerido'); }
        try {
          await api.patch(`/liquidaciones-cese/${fila.id}/anular`, { motivo: motivo.trim() });
          message.success('Liquidación anulada');
          recargar();
        } catch (error) {
          message.error(mensajeError(error, 'No se pudo anular la liquidación'));
          throw error;
        }
      },
    });
  };

  const abrirPago = (fila) => {
    formPago.setFieldsValue({ referencia_pago: '', fecha_pago: dayjs(), medio_pago: 'transferencia' });
    setPagando(fila);
  };

  const registrarPago = async (values) => {
    setRegistrandoPago(true);
    try {
      await api.patch(`/liquidaciones-cese/${pagando.id}/pagar`, { ...values, fecha_pago: values.fecha_pago.format('YYYY-MM-DD') });
      message.success('Pago registrado');
      setPagando(null);
      recargar();
    } catch (error) {
      message.error(mensajeError(error, 'No se pudo registrar el pago'));
    } finally { setRegistrandoPago(false); }
  };

  const columns = [
    { title: 'Colaborador', render: (_, f) => <div><strong>{`${f.colaborador?.nombres ?? ''} ${f.colaborador?.apellidos ?? ''}`.trim()}</strong><p className="text-xs text-gray-400">{f.colaborador?.legajo}</p></div> },
    { title: 'Fecha de cese', dataIndex: 'fecha_cese', render: fecha },
    { title: 'Modo', dataIndex: 'modo', render: (v) => <Tag color={MODOS[v]?.[1]}>{MODOS[v]?.[0] ?? v}</Tag> },
    { title: 'Neto', dataIndex: 'neto_pagar', render: (v) => <strong>{soles(v)}</strong> },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <Tag color={colores[v]}>{v}</Tag> },
    { title: 'Acciones', render: (_, fila) => <Space wrap>
      <Button size="small" icon={<PrinterOutlined />} onClick={() => setImprimiendoId(fila.id)}>Ver</Button>
      {fila.estado === 'calculada' && puedeAprobar && <Button size="small" icon={<CheckOutlined />} onClick={() => aprobar(fila)}>Aprobar</Button>}
      {fila.estado === 'aprobada' && puedePagar && <Button size="small" type="primary" icon={<DollarOutlined />} onClick={() => abrirPago(fila)}>Pagar</Button>}
      {['calculada', 'aprobada'].includes(fila.estado) && puedeAprobar && <Button size="small" danger icon={<CloseCircleOutlined />} onClick={() => anular(fila)}>Anular</Button>}
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
    <Modal title="Registrar pago de liquidación" open={Boolean(pagando)} onCancel={() => setPagando(null)} onOk={() => formPago.submit()} okText="Registrar pago" confirmLoading={registrandoPago} destroyOnHidden>
      {pagando && <p className="mb-3 text-sm text-gray-500">Neto a pagar: <strong>{soles(pagando.neto_pagar)}</strong></p>}
      <Form form={formPago} layout="vertical" onFinish={registrarPago}>
        <Form.Item label="Fecha de pago" name="fecha_pago" rules={[{ required: true, message: 'Indica la fecha de pago' }]}>
          <DatePicker className="w-full" format="DD/MM/YYYY" maxDate={dayjs()} />
        </Form.Item>
        <Form.Item label="Medio de pago" name="medio_pago"><Select options={MEDIOS} /></Form.Item>
        <Form.Item label="Referencia (N.° de operación, cheque, etc.)" name="referencia_pago" rules={[{ required: true, whitespace: true, message: 'Indica la referencia del pago' }]}>
          <Input maxLength={255} />
        </Form.Item>
      </Form>
    </Modal>
  </div>;
}
