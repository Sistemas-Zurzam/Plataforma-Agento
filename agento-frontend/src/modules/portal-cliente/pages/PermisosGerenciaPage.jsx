import { PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Form, Input, Modal, Radio, Select, Switch, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import EstadoSolicitud from '../components/EstadoSolicitud';
import PortalRangoFechas from '../components/PortalRangoFechas';
import SelectorColaborador from '../components/SelectorColaborador';
import { formatoFecha, formatoFechaHora } from '../constants/estadosPortal';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { usePermisosPortal, useTiposPermiso } from '../hooks/usePortalGestion';

const { Text } = Typography;
const { RangePicker } = DatePicker;

const ESTADOS = [
  { value: 'pendiente', label: 'Pendiente de RR. HH.' },
  { value: 'aprobado', label: 'Aprobado' },
  { value: 'rechazado', label: 'Rechazado' },
];

function ModalSolicitarPermiso({ onCerrar, onCreado, solicitar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  const tipos = useTiposPermiso(true);
  const tipo = Form.useWatch('tipo', form);
  const tipoSeleccionado = (tipos.datos ?? []).find((t) => t.codigo === tipo);

  const enviar = async (valores) => {
    setEnviando(true);
    try {
      await solicitar({
        colaborador_id: valores.colaborador_id,
        tipo: valores.tipo,
        modalidad: valores.modalidad,
        fecha_inicio: valores.fechas[0].format('YYYY-MM-DD'),
        fecha_fin: valores.fechas[1].format('YYYY-MM-DD'),
        con_goce: tipoSeleccionado?.requiere_con_goce ? !!valores.con_goce : undefined,
        motivo: valores.motivo,
      });
      message.success('Permiso enviado a RR. HH. para su aprobación.');
      onCreado();
    } catch (error) {
      const errores = error?.response?.data?.errors;
      if (error?.response?.status === 422 && errores) {
        form.setFields(Object.entries(errores).map(([campo, mensajes]) => ({
          name: ['fecha_inicio', 'fecha_fin'].includes(campo) ? 'fechas' : campo, errors: mensajes,
        })));
      } else {
        message.error(mensajeDeError(error));
      }
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal open title="Solicitar permiso" okText="Enviar a RR. HH." confirmLoading={enviando} onOk={() => form.submit()} onCancel={onCerrar} destroyOnHidden width={600}>
      <Alert className="mb-4" type="info" showIcon message="RR. HH. aprueba o rechaza la solicitud. Solicitar un permiso no justifica faltas ni tardanzas ya registradas." />
      <Form form={form} layout="vertical" initialValues={{ modalidad: 'dia_completo', con_goce: true }} onFinish={enviar}>
        <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Selecciona un colaborador' }]}>
          <SelectorColaborador />
        </Form.Item>
        <Form.Item name="tipo" label="Tipo de permiso" rules={[{ required: true, message: 'Selecciona el tipo' }]}>
          <Select loading={tipos.cargando} options={(tipos.datos ?? []).map((t) => ({ value: t.codigo, label: t.nombre }))} placeholder="Selecciona el tipo" />
        </Form.Item>
        <Form.Item name="modalidad" label="Modalidad" extra="Los permisos por horas aún no son soportados por el procesamiento de asistencia y planilla.">
          <Radio.Group>
            <Radio value="dia_completo">Día completo</Radio>
            <Radio value="por_horas" disabled>Por horas</Radio>
          </Radio.Group>
        </Form.Item>
        <Form.Item name="fechas" label="Fechas" rules={[{ required: true, message: 'Indica las fechas' }]}>
          <RangePicker className="w-full" format="DD/MM/YYYY" />
        </Form.Item>
        {tipoSeleccionado?.requiere_con_goce && (
          <Form.Item name="con_goce" label="Con goce de haber" valuePropName="checked">
            <Switch checkedChildren="Con goce" unCheckedChildren="Sin goce" />
          </Form.Item>
        )}
        {tipoSeleccionado && tipoSeleccionado.con_goce_fijo !== null && tipoSeleccionado.con_goce_fijo !== undefined && (
          <Text type="secondary" className="mb-3 block text-xs">Este tipo es {tipoSeleccionado.con_goce_fijo ? 'con' : 'sin'} goce según el catálogo.</Text>
        )}
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'El motivo es obligatorio' }, { min: 5, message: 'Mínimo 5 caracteres' }]}>
          <Input.TextArea rows={3} maxLength={1000} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}

export default function PermisosGerenciaPage({ puedeSolicitar }) {
  const [filtros, setFiltros] = useFiltrosUrl({
    fecha_desde: dayjs().subtract(30, 'day').format('YYYY-MM-DD'),
    fecha_hasta: dayjs().add(60, 'day').format('YYYY-MM-DD'),
    estado: undefined,
    page: 1,
  });
  const [abierto, setAbierto] = useState(false);
  const { datos, cargando, error, recargar, solicitar } = usePermisosPortal(filtros, true);

  const columnas = [
    { title: 'Colaborador', render: (_, r) => <div><div>{r.colaborador?.nombre_completo}</div><Text type="secondary" className="text-xs">{r.colaborador?.area}</Text></div> },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => v?.replaceAll('_', ' ') },
    { title: 'Desde', dataIndex: 'fecha_inicio', render: formatoFecha, width: 105 },
    { title: 'Hasta', dataIndex: 'fecha_fin', render: formatoFecha, width: 105 },
    { title: 'Goce', dataIndex: 'con_goce', render: (v) => (v ? 'Con goce' : 'Sin goce'), width: 90 },
    {
      title: 'Motivo',
      dataIndex: 'motivo',
      ellipsis: true,
      render: (v, r) => (r.motivo_oculto_por_privacidad ? <Text type="secondary" italic>Información médica reservada</Text> : v),
    },
    { title: 'Estado', render: (_, r) => <EstadoSolicitud estado={r.estado} siguienteResponsable={r.siguiente_responsable} />, width: 180 },
    {
      title: 'Respuesta de RR. HH.',
      render: (_, r) => (r.resuelto_at
        ? <div className="text-xs"><div>{r.resuelto_por ?? 'RR. HH.'} · {formatoFechaHora(r.resuelto_at)}</div><Text type="secondary">{r.respuesta_rrhh ?? 'Sin observaciones'}</Text></div>
        : '—'),
    },
    { title: 'Origen', dataIndex: 'origen', render: (v) => (v === 'portal' ? 'Portal' : 'RR. HH.'), width: 85 },
  ];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <PortalRangoFechas value={filtros} onChange={(rango) => setFiltros({ ...rango, page: 1 })} />
        <Select allowClear placeholder="Estado" className="w-56" aria-label="Estado" value={filtros.estado} options={ESTADOS} onChange={(estado) => setFiltros({ estado, page: 1 })} />
        {puedeSolicitar && <Button type="primary" icon={<PlusOutlined />} className="ml-auto" onClick={() => setAbierto(true)}>Solicitar permiso</Button>}
      </div>
      {error ? (
        <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar los permisos.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={datos?.data ?? []}
          columns={columnas}
          scroll={{ x: 1100 }}
          locale={{ emptyText: 'No hay permisos en este rango' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 15, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros({ page }),
          }}
        />
      )}
      {abierto && (
        <ModalSolicitarPermiso solicitar={solicitar} onCerrar={() => setAbierto(false)} onCreado={() => { setAbierto(false); recargar(); }} />
      )}
    </div>
  );
}
