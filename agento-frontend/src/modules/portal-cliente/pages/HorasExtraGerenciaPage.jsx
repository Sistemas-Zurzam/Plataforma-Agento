import { Alert, App, Button, Form, Input, InputNumber, Modal, Radio, Select, Space, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import EstadoSolicitud from '../components/EstadoSolicitud';
import PortalRangoFechas from '../components/PortalRangoFechas';
import { formatoFecha, formatoFechaHora, formatoMinutos } from '../constants/estadosPortal';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { useHorasExtraPortal } from '../hooks/usePortalGestion';

const { Text } = Typography;

const ESTADOS = [
  { value: 'pendiente', label: 'Por aprobar' },
  { value: 'aprobado_gerencia', label: 'En validación de RR. HH.' },
  { value: 'aprobado', label: 'Validadas por RR. HH.' },
  { value: 'rechazado', label: 'Rechazadas' },
];

function ModalDecision({ registro, onCerrar, onDecidido, decidir }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  const [conflicto, setConflicto] = useState(null);
  const accion = Form.useWatch('accion', form);

  const confirmar = async (valores) => {
    const minutos = valores.accion === 'aprobar_parcial' ? (valores.horas ?? 0) * 60 + (valores.minutos ?? 0) : undefined;
    setEnviando(true);
    setConflicto(null);
    try {
      const actualizado = await decidir(registro.id, {
        accion: valores.accion, minutos, motivo: valores.motivo, version: registro.version,
      });
      message.success(valores.accion === 'rechazar' ? 'Hora extra rechazada.' : 'Hora extra aprobada. Ahora la valida RR. HH.');
      onDecidido(actualizado);
    } catch (error) {
      if (error?.response?.status === 409) {
        setConflicto(mensajeDeError(error));
      } else if (error?.response?.status === 422 && error.response.data?.errors) {
        form.setFields(Object.entries(error.response.data.errors).map(([campo, errores]) => ({ name: campo === 'minutos' ? 'horas' : campo, errors: errores })));
      } else {
        message.error(mensajeDeError(error));
      }
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal
      open
      title={`Decidir horas extra · ${registro.colaborador?.nombre_completo}`}
      okText="Confirmar decisión"
      okButtonProps={{ danger: accion === 'rechazar', disabled: !!conflicto }}
      confirmLoading={enviando}
      onOk={() => form.submit()}
      onCancel={onCerrar}
      destroyOnHidden
    >
      <p className="mb-3 text-sm text-gray-600">
        {formatoFecha(registro.fecha)} · Recargo {registro.tasa_etiqueta} · Tiempo observado: <strong>{formatoMinutos(registro.minutos_observados)}</strong>
      </p>
      {conflicto && (
        <Alert className="mb-3" type="warning" showIcon message={conflicto} description="Cierra este diálogo para recargar el listado; tu motivo no se ha enviado." />
      )}
      <Form form={form} layout="vertical" initialValues={{ accion: 'aprobar_total' }} onFinish={confirmar}>
        <Form.Item name="accion" label="Decisión">
          <Radio.Group>
            <Radio value="aprobar_total">Aprobar total</Radio>
            <Radio value="aprobar_parcial">Aprobar parcial</Radio>
            <Radio value="rechazar">Rechazar</Radio>
          </Radio.Group>
        </Form.Item>
        {accion === 'aprobar_parcial' && (
          <Space align="start">
            <Form.Item name="horas" label="Horas" rules={[{ required: true, message: 'Indica las horas' }]}>
              <InputNumber min={0} max={Math.floor(registro.minutos_observados / 60)} precision={0} />
            </Form.Item>
            <Form.Item
              name="minutos"
              label="Minutos"
              dependencies={['horas']}
              rules={[{ required: true, message: 'Indica los minutos' }, ({ getFieldValue }) => ({
                validator(_, valor) {
                  const total = (getFieldValue('horas') ?? 0) * 60 + (valor ?? 0);
                  if (total <= 0) return Promise.reject(new Error('Debe ser mayor que 0.'));
                  if (total > registro.minutos_observados) return Promise.reject(new Error(`No puede superar ${formatoMinutos(registro.minutos_observados)}.`));
                  return Promise.resolve();
                },
              })]}
            >
              <InputNumber min={0} max={59} precision={0} />
            </Form.Item>
          </Space>
        )}
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'El motivo es obligatorio' }, { min: 5, message: 'Mínimo 5 caracteres' }]}>
          <Input.TextArea rows={3} maxLength={1000} showCount />
        </Form.Item>
      </Form>
      <Text type="secondary" className="text-xs">Tu aprobación no incorpora la hora extra a planilla: RR. HH. la valida después.</Text>
    </Modal>
  );
}

export default function HorasExtraGerenciaPage({ puedeAprobar }) {
  const [filtros, setFiltros] = useFiltrosUrl({
    fecha_desde: dayjs().subtract(30, 'day').format('YYYY-MM-DD'),
    fecha_hasta: dayjs().format('YYYY-MM-DD'),
    estado: 'pendiente',
    page: 1,
  });
  const [seleccion, setSeleccion] = useState(null);
  const { datos, cargando, error, recargar, decidir } = useHorasExtraPortal(filtros, true);

  const columnas = [
    { title: 'Colaborador', render: (_, r) => <div><div>{r.colaborador?.nombre_completo}</div><Text type="secondary" className="text-xs">{r.colaborador?.area}</Text></div> },
    { title: 'Fecha', dataIndex: 'fecha', render: formatoFecha, width: 105 },
    { title: 'Recargo', dataIndex: 'tasa_etiqueta', width: 85 },
    { title: 'Observado', dataIndex: 'minutos_observados', render: formatoMinutos, width: 100 },
    { title: 'Aprobado gerencia', dataIndex: 'minutos_aprobados_gerencia', render: formatoMinutos, width: 130 },
    { title: 'Validado RR. HH.', dataIndex: 'minutos_validados_rrhh', render: formatoMinutos, width: 125 },
    { title: 'Estado', render: (_, r) => <EstadoSolicitud estado={r.estado} siguienteResponsable={r.siguiente_responsable} />, width: 190 },
    {
      title: 'Decisión de gerencia',
      render: (_, r) => (r.gerencia_resuelto_at ? (
        <div className="text-xs"><div>{r.gerencia_resuelto_por} · {formatoFechaHora(r.gerencia_resuelto_at)}</div><Text type="secondary">{r.motivo_gerencia}</Text></div>
      ) : '—'),
    },
    {
      title: 'Acciones',
      width: 110,
      render: (_, r) => (puedeAprobar && r.estado === 'pendiente'
        ? <Button size="small" type="primary" onClick={() => setSeleccion(r)}>Decidir</Button>
        : <Text type="secondary" className="text-xs">{r.estado === 'aprobado_gerencia' ? 'Valida RR. HH.' : '—'}</Text>),
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        <PortalRangoFechas value={filtros} onChange={(rango) => setFiltros({ ...rango, page: 1 })} />
        <Select allowClear placeholder="Estado" className="w-60" aria-label="Estado" value={filtros.estado} options={ESTADOS} onChange={(estado) => setFiltros({ estado, page: 1 })} />
      </div>
      {!puedeAprobar && <Alert type="info" showIcon message="Puedes consultar las horas extra, pero tu rol no puede aprobarlas." />}
      {error ? (
        <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar las horas extra.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={datos?.data ?? []}
          columns={columnas}
          scroll={{ x: 1100 }}
          locale={{ emptyText: 'No hay horas extra para este filtro' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 15, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros({ page }),
          }}
        />
      )}
      {seleccion && (
        <ModalDecision
          registro={seleccion}
          decidir={decidir}
          onCerrar={() => { setSeleccion(null); recargar(); }}
          onDecidido={() => { setSeleccion(null); recargar(); }}
        />
      )}
    </div>
  );
}
