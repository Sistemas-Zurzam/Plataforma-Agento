import { PaperClipOutlined, PlusOutlined, UploadOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Descriptions, Drawer, Form, Input, Modal, Select, Table, Timeline, Typography, Upload } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import EstadoSolicitud from '../components/EstadoSolicitud';
import SelectorColaborador from '../components/SelectorColaborador';
import { formatoFecha, formatoFechaHora } from '../constants/estadosPortal';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { useCesesPortal } from '../hooks/usePortalGestion';

const { Text, Paragraph } = Typography;

const ETAPAS = [
  { value: 'enviada', label: 'Enviadas' },
  { value: 'aprobada_pendiente_ejecucion', label: 'Aprobadas · pendientes de ejecución' },
  { value: 'ejecutada', label: 'Ejecutadas' },
  { value: 'rechazada', label: 'Rechazadas' },
];
const MAX_MB = 10;
const ACEPTADOS = ['application/pdf', 'image/jpeg', 'image/png'];

function ModalSolicitarCese({ solicitar, onCerrar, onCreada }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);

  const enviar = async (valores) => {
    const datos = new FormData();
    datos.append('colaborador_id', valores.colaborador_id);
    datos.append('fecha_cese_propuesta', valores.fecha_cese_propuesta.format('YYYY-MM-DD'));
    datos.append('motivo', valores.motivo);
    valores.evidencias.forEach((archivo) => datos.append('evidencias[]', archivo.originFileObj));
    setEnviando(true);
    try {
      await solicitar(datos);
      message.success('Solicitud de cese enviada a RR. HH.');
      onCreada();
    } catch (error) {
      const errores = error?.response?.data?.errors;
      if (error?.response?.status === 422 && errores) {
        form.setFields(Object.entries(errores).map(([campo, errors]) => ({ name: campo.startsWith('evidencias') ? 'evidencias' : campo, errors })));
      } else {
        message.error(mensajeDeError(error));
      }
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal open title="Solicitar cese" okText="Enviar a RR. HH." confirmLoading={enviando} onOk={() => form.submit()} onCancel={onCerrar} destroyOnHidden width={600}>
      <Alert className="mb-4" type="info" showIcon message="Solo solicitas el cese." description="RR. HH. decide y, si lo aprueba, registra el cese en Personas. Esta solicitud no genera automáticamente una solicitud de reemplazo." />
      <Form form={form} layout="vertical" onFinish={enviar}>
        <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Selecciona un colaborador' }]}>
          <SelectorColaborador />
        </Form.Item>
        <Form.Item name="fecha_cese_propuesta" label="Fecha de cese propuesta" rules={[{ required: true, message: 'Indica la fecha' }]} extra="Igual o posterior a hoy.">
          <DatePicker className="w-full" format="DD/MM/YYYY" minDate={dayjs()} />
        </Form.Item>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'El motivo es obligatorio' }, { min: 10, message: 'Mínimo 10 caracteres' }]}>
          <Input.TextArea rows={4} maxLength={1000} showCount />
        </Form.Item>
        <Form.Item
          name="evidencias"
          label="Evidencias (1 a 5 archivos PDF, JPG o PNG, máx. 10 MB c/u)"
          valuePropName="fileList"
          getValueFromEvent={(e) => e?.fileList ?? []}
          rules={[{ validator: (_, lista) => {
            if (!lista?.length) return Promise.reject(new Error('Adjunta al menos una evidencia.'));
            if (lista.length > 5) return Promise.reject(new Error('Máximo 5 archivos.'));
            if (lista.some((f) => !ACEPTADOS.includes(f.type))) return Promise.reject(new Error('Solo PDF, JPG o PNG.'));
            if (lista.some((f) => f.size > MAX_MB * 1024 * 1024)) return Promise.reject(new Error(`Cada archivo debe pesar como máximo ${MAX_MB} MB.`));
            return Promise.resolve();
          } }]}
        >
          <Upload beforeUpload={() => false} multiple maxCount={5} accept=".pdf,.jpg,.jpeg,.png">
            <Button icon={<UploadOutlined />}>Seleccionar archivos</Button>
          </Upload>
        </Form.Item>
      </Form>
    </Modal>
  );
}

function DetalleCese({ solicitud, onCerrar, descargarEvidencia }) {
  const { message } = App.useApp();

  const descargar = async (evidencia) => {
    try {
      const blob = await descargarEvidencia(solicitud.id, evidencia.id);
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');
      enlace.href = url;
      enlace.download = evidencia.nombre;
      enlace.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      message.error(mensajeDeError(error, 'No se pudo descargar la evidencia.'));
    }
  };

  const historial = [
    { color: 'blue', children: <>Enviada por {solicitud.solicitante ?? '—'} · {formatoFechaHora(solicitud.creado_at)}</> },
    solicitud.resuelto_at && {
      color: solicitud.estado === 'rechazada' ? 'red' : 'green',
      children: <>{solicitud.estado === 'rechazada' ? 'Rechazada' : 'Aprobada'} por {solicitud.resuelto_por ?? 'RR. HH.'} · {formatoFechaHora(solicitud.resuelto_at)}</>,
    },
    solicitud.ejecutada_at && { color: 'green', children: <>Cese ejecutado por {solicitud.ejecutada_por ?? 'RR. HH.'} · {formatoFechaHora(solicitud.ejecutada_at)}</> },
  ].filter(Boolean);

  return (
    <Drawer open title={`Solicitud de cese · ${solicitud.colaborador?.nombre_completo}`} onClose={onCerrar} width={520}>
      <div className="flex flex-col gap-4">
        <EstadoSolicitud estado={solicitud.etapa} siguienteResponsable={solicitud.siguiente_responsable} />
        <Descriptions column={1} size="small" bordered>
          <Descriptions.Item label="Área">{solicitud.colaborador?.area ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Fecha propuesta">{formatoFecha(solicitud.fecha_cese_propuesta)}</Descriptions.Item>
          {solicitud.fecha_cese_aprobada && <Descriptions.Item label="Fecha aprobada">{formatoFecha(solicitud.fecha_cese_aprobada)}</Descriptions.Item>}
          {solicitud.motivo_cambio_fecha && <Descriptions.Item label="Motivo del cambio de fecha">{solicitud.motivo_cambio_fecha}</Descriptions.Item>}
          {solicitud.fecha_cese_efectiva && <Descriptions.Item label="Fecha efectiva del cese">{formatoFecha(solicitud.fecha_cese_efectiva)}</Descriptions.Item>}
          {solicitud.motivo_rechazo && <Descriptions.Item label="Motivo de rechazo">{solicitud.motivo_rechazo}</Descriptions.Item>}
        </Descriptions>
        <div>
          <Text strong>Motivo</Text>
          <Paragraph className="whitespace-pre-line">{solicitud.motivo}</Paragraph>
        </div>
        <div>
          <Text strong>Evidencias</Text>
          <ul className="mt-1 space-y-1">
            {(solicitud.evidencias ?? []).map((e) => (
              <li key={e.id}><Button type="link" className="!px-0" icon={<PaperClipOutlined />} onClick={() => descargar(e)}>{e.nombre}</Button></li>
            ))}
          </ul>
        </div>
        <div>
          <Text strong>Historial</Text>
          <Timeline className="mt-3" items={historial} />
        </div>
      </div>
    </Drawer>
  );
}

export default function CesesGerenciaPage() {
  const [filtros, setFiltros] = useFiltrosUrl({ etapa: undefined, page: 1 });
  const [abierto, setAbierto] = useState(false);
  const [detalle, setDetalle] = useState(null);
  const { datos, cargando, error, recargar, solicitar, evidencia } = useCesesPortal(filtros);

  const columnas = [
    { title: 'Colaborador', render: (_, r) => <div><div>{r.colaborador?.nombre_completo}</div><Text type="secondary" className="text-xs">{r.colaborador?.area}</Text></div> },
    { title: 'Fecha propuesta', dataIndex: 'fecha_cese_propuesta', render: formatoFecha, width: 130 },
    { title: 'Fecha efectiva', dataIndex: 'fecha_cese_efectiva', render: formatoFecha, width: 120 },
    { title: 'Estado', render: (_, r) => <EstadoSolicitud estado={r.etapa} siguienteResponsable={r.siguiente_responsable} />, width: 240 },
    { title: 'Actualizada', dataIndex: 'actualizado_at', render: formatoFechaHora, width: 140 },
    { title: '', width: 90, render: (_, r) => <Button size="small" onClick={() => setDetalle(r)}>Ver detalle</Button> },
  ];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Select allowClear placeholder="Estado" className="w-72" aria-label="Estado" value={filtros.etapa} options={ETAPAS} onChange={(etapa) => setFiltros({ etapa, page: 1 })} />
        <Button type="primary" icon={<PlusOutlined />} className="ml-auto" onClick={() => setAbierto(true)}>Solicitar cese</Button>
      </div>
      {error ? (
        <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar las solicitudes.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={datos?.data ?? []}
          columns={columnas}
          scroll={{ x: 900 }}
          locale={{ emptyText: 'No hay solicitudes de cese' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 15, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros({ page }),
          }}
        />
      )}
      {abierto && <ModalSolicitarCese solicitar={solicitar} onCerrar={() => setAbierto(false)} onCreada={() => { setAbierto(false); recargar(); }} />}
      {detalle && <DetalleCese solicitud={detalle} descargarEvidencia={evidencia} onCerrar={() => setDetalle(null)} />}
    </div>
  );
}
