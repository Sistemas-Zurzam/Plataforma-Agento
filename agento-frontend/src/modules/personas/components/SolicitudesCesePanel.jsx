import { ArrowLeftOutlined, PaperClipOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Form, Input, Modal, Radio, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import { useSolicitudesCese } from '../hooks/useSolicitudesCese';
import CesarColaboradorModal from './CesarColaboradorModal';

const { Text } = Typography;

const ETAPAS = {
  enviada: ['Por decidir', 'blue'],
  aprobada_pendiente_ejecucion: ['Aprobada · pendiente de ejecución', 'cyan'],
  ejecutada: ['Ejecutada', 'green'],
  rechazada: ['Rechazada', 'red'],
};
const fecha = (valor) => (valor ? dayjs(valor).format('DD/MM/YYYY') : '—');

function ModalDecision({ solicitud, onCerrar, onResuelta, resolver }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  const accion = Form.useWatch('accion', form);
  const fechaAprobada = Form.useWatch('fecha_cese_aprobada', form);
  const cambiaFecha = fechaAprobada && fechaAprobada.format('YYYY-MM-DD') !== solicitud.fecha_cese_propuesta;

  const enviar = async (valores) => {
    setEnviando(true);
    try {
      await resolver(solicitud.id, {
        accion: valores.accion,
        motivo_rechazo: valores.motivo_rechazo,
        fecha_cese_aprobada: valores.accion === 'aprobar' ? valores.fecha_cese_aprobada?.format('YYYY-MM-DD') : undefined,
        motivo_cambio_fecha: valores.motivo_cambio_fecha,
      });
      message.success(valores.accion === 'aprobar' ? 'Solicitud aprobada. Falta ejecutar el cese en Personas.' : 'Solicitud rechazada.');
      onResuelta();
    } catch (error) {
      const errores = error?.response?.data?.errors;
      if (errores) form.setFields(Object.entries(errores).map(([name, errors]) => ({ name, errors })));
      else message.error(mensajeDeError(error));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal open title={`Decidir solicitud de cese · ${solicitud.colaborador?.nombre_completo}`} okText="Confirmar" okButtonProps={{ danger: accion === 'rechazar' }} confirmLoading={enviando} onOk={() => form.submit()} onCancel={onCerrar} destroyOnHidden>
      <Form form={form} layout="vertical" initialValues={{ accion: 'aprobar', fecha_cese_aprobada: dayjs(solicitud.fecha_cese_propuesta) }} onFinish={enviar}>
        <Form.Item name="accion">
          <Radio.Group>
            <Radio value="aprobar">Aprobar</Radio>
            <Radio value="rechazar">Rechazar</Radio>
          </Radio.Group>
        </Form.Item>
        {accion === 'aprobar' ? (
          <>
            <Form.Item name="fecha_cese_aprobada" label="Fecha de cese aprobada" extra={`Propuesta por gerencia: ${fecha(solicitud.fecha_cese_propuesta)}`}>
              <DatePicker className="w-full" format="DD/MM/YYYY" allowClear={false} />
            </Form.Item>
            {cambiaFecha && (
              <Form.Item name="motivo_cambio_fecha" label="Motivo del cambio de fecha" rules={[{ required: true, message: 'Indica por qué cambia la fecha' }]}>
                <Input.TextArea rows={2} maxLength={1000} />
              </Form.Item>
            )}
            <Alert type="info" showIcon message="Aprobar no cesa al colaborador: después debes ejecutar el cese con el formulario de Personas." />
          </>
        ) : (
          <Form.Item name="motivo_rechazo" label="Motivo de rechazo (visible para gerencia)" rules={[{ required: true, message: 'El motivo es obligatorio' }, { min: 5, message: 'Mínimo 5 caracteres' }]}>
            <Input.TextArea rows={3} maxLength={1000} showCount />
          </Form.Item>
        )}
      </Form>
    </Modal>
  );
}

/**
 * Bandeja de RR. HH.: decide las solicitudes del Portal de Empresa y
 * ejecuta el cese con el MISMO formulario y caso de uso de Personas
 * (CesarColaboradorModal → ColaboradorService::cesar en el backend).
 */
export default function SolicitudesCesePanel({ onVolver }) {
  const { message } = App.useApp();
  const [filtros, setFiltros] = useState({ etapa: 'enviada', page: 1 });
  const [decision, setDecision] = useState(null);
  const [ejecucion, setEjecucion] = useState(null);
  const [ejecutando, setEjecutando] = useState(false);
  const { datos, cargando, error, recargar, acciones } = useSolicitudesCese(filtros);

  const descargar = async (solicitud, evidencia) => {
    try {
      const url = URL.createObjectURL(await acciones.evidencia(solicitud.id, evidencia.id));
      const enlace = document.createElement('a');
      enlace.href = url;
      enlace.download = evidencia.nombre;
      enlace.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo descargar la evidencia.'));
    }
  };

  const ejecutar = async (valores) => {
    setEjecutando(true);
    try {
      await acciones.ejecutar(ejecucion.id, valores);
      message.success('Cese registrado. La solicitud quedó como ejecutada.');
      setEjecucion(null);
      recargar();
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo registrar el cese; la solicitud sigue pendiente de ejecución.'));
    } finally {
      setEjecutando(false);
    }
  };

  const columnas = [
    { title: 'Colaborador', render: (_, r) => <div><div className="font-medium">{r.colaborador?.nombre_completo}</div><Text type="secondary" className="text-xs">{r.colaborador?.legajo} · {r.colaborador?.area} · {r.colaborador?.cargo}</Text></div> },
    { title: 'Solicitante', dataIndex: 'solicitante', width: 150 },
    { title: 'Fecha propuesta', dataIndex: 'fecha_cese_propuesta', render: fecha, width: 120 },
    { title: 'Fecha aprobada / efectiva', render: (_, r) => `${fecha(r.fecha_cese_aprobada)} / ${fecha(r.fecha_cese_efectiva)}`, width: 170 },
    { title: 'Motivo', dataIndex: 'motivo', ellipsis: true },
    { title: 'Evidencias', render: (_, r) => <Space direction="vertical" size={0}>{(r.evidencias ?? []).map((e) => <Button key={e.id} type="link" size="small" className="!px-0" icon={<PaperClipOutlined />} onClick={() => descargar(r, e)}>{e.nombre}</Button>)}</Space> },
    { title: 'Estado', width: 200, render: (_, r) => <Tag color={ETAPAS[r.etapa]?.[1]}>{ETAPAS[r.etapa]?.[0] ?? r.etapa}</Tag> },
    {
      title: 'Acciones',
      width: 140,
      render: (_, r) => {
        if (r.etapa === 'enviada') return <Button size="small" type="primary" onClick={() => setDecision(r)}>Decidir</Button>;
        if (r.etapa === 'aprobada_pendiente_ejecucion') return <Button size="small" danger onClick={() => setEjecucion(r)}>Ejecutar cese</Button>;
        return '—';
      },
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Button icon={<ArrowLeftOutlined />} onClick={onVolver}>Volver a colaboradores</Button>
        <Select
          className="w-72"
          aria-label="Estado"
          allowClear
          placeholder="Todas"
          value={filtros.etapa}
          options={Object.entries(ETAPAS).map(([value, [label]]) => ({ value, label }))}
          onChange={(etapa) => setFiltros({ etapa, page: 1 })}
        />
      </div>
      {error ? (
        <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo cargar la bandeja.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={datos?.data ?? []}
          columns={columnas}
          scroll={{ x: 1300 }}
          locale={{ emptyText: 'No hay solicitudes de cese para este filtro' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 20, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros((f) => ({ ...f, page })),
          }}
        />
      )}
      {decision && <ModalDecision solicitud={decision} resolver={acciones.resolver} onCerrar={() => setDecision(null)} onResuelta={() => { setDecision(null); recargar(); }} />}
      <CesarColaboradorModal
        open={!!ejecucion}
        colaborador={ejecucion ? { fecha_ingreso: ejecucion.colaborador?.fecha_ingreso } : null}
        valoresIniciales={ejecucion ? {
          fecha_cese: dayjs(ejecucion.fecha_cese_aprobada).isAfter(dayjs(), 'day') ? undefined : ejecucion.fecha_cese_aprobada,
          motivo_cese: ejecucion.motivo?.slice(0, 255),
        } : undefined}
        submitting={ejecutando}
        onGuardar={ejecutar}
        onCancel={() => setEjecucion(null)}
      />
    </div>
  );
}
