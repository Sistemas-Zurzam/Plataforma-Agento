import { PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, Form, Input, InputNumber, Modal, Radio, Select, Table, Typography } from 'antd';
import { useState } from 'react';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import EstadoSolicitud from '../components/EstadoSolicitud';
import SelectorColaborador from '../components/SelectorColaborador';
import { formatoFecha, formatoFechaHora } from '../constants/estadosPortal';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { useBonosPortal, useCiclosAbiertos } from '../hooks/usePortalGestion';

const { Text } = Typography;

const ESTADOS = [
  { value: 'propuesto', label: 'Propuestas (RR. HH. decide)' },
  { value: 'validado', label: 'Validadas' },
  { value: 'rechazado', label: 'Rechazadas' },
];

// El monto viaja como texto decimal (InputNumber en stringMode): nunca se
// opera en punto flotante; el backend lo guarda en decimal(12,2).
const normalizarMonto = (valor) => {
  const [enteros, decimales = ''] = String(valor).split('.');
  return `${enteros}.${`${decimales}00`.slice(0, 2)}`;
};

function ModalPropuesta({ proponer, onCerrar, onCreada }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  // Una clave por intento de propuesta: un reintento (doble clic, red) no
  // duplica; una nueva propuesta abre un formulario con otra clave.
  const [clave] = useState(() => crypto.randomUUID());
  const ciclos = useCiclosAbiertos(true);

  const enviar = async (valores) => {
    setEnviando(true);
    try {
      await proponer({ ...valores, monto: normalizarMonto(valores.monto), clave_idempotencia: clave });
      message.success('Propuesta enviada a RR. HH.');
      onCreada();
    } catch (error) {
      const errores = error?.response?.data?.errors;
      if (error?.response?.status === 422 && errores) {
        form.setFields(Object.entries(errores).map(([name, errors]) => ({ name, errors })));
      } else {
        message.error(mensajeDeError(error));
      }
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal open title="Proponer bono o comisión" okText="Enviar propuesta" confirmLoading={enviando} onOk={() => form.submit()} onCancel={onCerrar} destroyOnHidden>
      {!ciclos.cargando && (ciclos.datos ?? []).length === 0 && (
        <Alert className="mb-3" type="warning" showIcon message="No hay ciclos abiertos que admitan propuestas. Consulta con RR. HH." />
      )}
      <Form form={form} layout="vertical" initialValues={{ tipo: 'bono' }} onFinish={enviar}>
        <Form.Item name="ciclo_id" label="Ciclo" rules={[{ required: true, message: 'Selecciona el ciclo' }]}>
          <Select
            loading={ciclos.cargando}
            placeholder="Ciclo abierto"
            options={(ciclos.datos ?? []).map((c) => ({ value: c.id, label: `${c.nombre} (${formatoFecha(c.fecha_inicio)} – ${formatoFecha(c.fecha_fin)})` }))}
          />
        </Form.Item>
        <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Selecciona un colaborador' }]}>
          <SelectorColaborador />
        </Form.Item>
        <Form.Item name="tipo" label="Tipo">
          <Radio.Group>
            <Radio value="bono">Bono</Radio>
            <Radio value="comision">Comisión</Radio>
          </Radio.Group>
        </Form.Item>
        <Form.Item name="monto" label="Monto propuesto (S/)" rules={[{ required: true, message: 'Indica el monto' }]}>
          <InputNumber className="w-full" min={0.01} precision={2} step={10} stringMode />
        </Form.Item>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'El motivo es obligatorio' }, { min: 5, message: 'Mínimo 5 caracteres' }]}>
          <Input.TextArea rows={3} maxLength={500} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}

export default function BonosGerenciaPage() {
  const [filtros, setFiltros] = useFiltrosUrl({ estado: undefined, page: 1 });
  const [abierto, setAbierto] = useState(false);
  const { datos, cargando, error, recargar, proponer } = useBonosPortal(filtros);

  const columnas = [
    { title: 'Colaborador', render: (_, r) => <div><div>{r.colaborador?.nombre_completo}</div><Text type="secondary" className="text-xs">{r.colaborador?.area}</Text></div> },
    { title: 'Ciclo', render: (_, r) => r.ciclo?.nombre },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => (v === 'comision' ? 'Comisión' : 'Bono'), width: 95 },
    { title: 'Monto propuesto', dataIndex: 'monto', render: (v) => `S/ ${v}`, align: 'right', width: 130 },
    { title: 'Motivo', dataIndex: 'motivo', ellipsis: true },
    {
      title: 'Estado',
      width: 200,
      render: (_, r) => (
        <div>
          <EstadoSolicitud estado={r.estado} siguienteResponsable={r.siguiente_responsable} />
          {r.ciclo_pagado && <Text type="success" className="text-xs">Ciclo pagado</Text>}
          {r.retirado_por_rrhh && <Text type="warning" className="text-xs">Retirado del ciclo por RR. HH.</Text>}
        </div>
      ),
    },
    { title: 'Fecha', dataIndex: 'creado_at', render: formatoFechaHora, width: 140 },
    {
      title: 'Observación de RR. HH.',
      render: (_, r) => (r.resuelto_at
        ? <div className="text-xs"><div>{r.resuelto_por} · {formatoFechaHora(r.resuelto_at)}</div><Text type="secondary">{r.observacion_rrhh ?? 'Sin observaciones'}</Text></div>
        : '—'),
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <Alert
        type="info"
        showIcon
        message="Tú propones; RR. HH. valida y lo incorpora al ciclo."
        description="“Validada” significa que RR. HH. la registró en el ciclo remunerativo; se paga cuando el ciclo se cierra y paga. No puedes validar, vincular a boletas ni marcar pagos."
      />
      <div className="flex flex-wrap items-center gap-2">
        <Select allowClear placeholder="Estado" className="w-60" aria-label="Estado" value={filtros.estado} options={ESTADOS} onChange={(estado) => setFiltros({ estado, page: 1 })} />
        <Button type="primary" icon={<PlusOutlined />} className="ml-auto" onClick={() => setAbierto(true)}>Proponer</Button>
      </div>
      {error ? (
        <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar las propuestas.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={datos?.data ?? []}
          columns={columnas}
          scroll={{ x: 1100 }}
          locale={{ emptyText: 'Aún no hay propuestas' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 15, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros({ page }),
          }}
        />
      )}
      {abierto && <ModalPropuesta proponer={proponer} onCerrar={() => setAbierto(false)} onCreada={() => { setAbierto(false); recargar(); }} />}
    </div>
  );
}
