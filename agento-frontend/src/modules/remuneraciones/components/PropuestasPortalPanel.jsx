import { Alert, App, Button, Form, Input, Modal, Radio, Select, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { useConceptoDefinicionesPlame } from '../../configuracion/hooks/useConceptoDefinicionesPlame';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import { useCatalogoConceptos, usePropuestasConcepto } from '../hooks/usePropuestasConcepto';

const { Text } = Typography;

const ESTADOS = { propuesto: ['Por decidir', 'gold'], validado: ['Validada', 'green'], rechazado: ['Rechazada', 'red'] };
// Mismos conceptos de ingreso que RegistrarConceptoModal para bonos/comisiones.
const CONCEPTOS_POR_TIPO = { comision: ['COMISION'], bono: ['BONIFICACION', 'BONO_NO_REMUNERATIVO'] };
const CONCEPTOS_CON_DEFINICION = ['BONIFICACION', 'BONO_NO_REMUNERATIVO'];

function ModalResolver({ propuesta, resolver, onCerrar, onResuelta }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  const catalogo = useCatalogoConceptos(true);
  const { definiciones, loading: cargandoDefiniciones, fetchDefiniciones } = useConceptoDefinicionesPlame();
  const accion = Form.useWatch('accion', form);
  const conceptoId = Form.useWatch('concepto_id', form);
  const opciones = (catalogo.datos ?? []).filter((c) => CONCEPTOS_POR_TIPO[propuesta.tipo]?.includes(c.codigo));
  const conceptoSeleccionado = opciones.find((c) => c.id === conceptoId);

  const enviar = async (valores) => {
    setEnviando(true);
    try {
      await resolver(propuesta.id, valores);
      message.success(valores.accion === 'validar' ? 'Propuesta validada e incorporada al ciclo.' : 'Propuesta rechazada.');
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
    <Modal open title={`Propuesta de ${propuesta.tipo === 'comision' ? 'comisión' : 'bono'} · ${propuesta.colaborador?.nombre_completo}`} okText="Confirmar" okButtonProps={{ danger: accion === 'rechazar' }} confirmLoading={enviando} onOk={() => form.submit()} onCancel={onCerrar} destroyOnHidden>
      <p className="mb-3 text-sm">S/ {propuesta.monto} · {propuesta.ciclo?.nombre} · Propuesto por {propuesta.propuesto_por}</p>
      <Text type="secondary" className="mb-3 block text-sm">{propuesta.motivo}</Text>
      <Form form={form} layout="vertical" initialValues={{ accion: 'validar' }} onFinish={enviar}>
        <Form.Item name="accion">
          <Radio.Group>
            <Radio value="validar">Validar</Radio>
            <Radio value="rechazar">Rechazar</Radio>
          </Radio.Group>
        </Form.Item>
        {accion === 'validar' && (
          <>
            <Form.Item name="concepto_id" label="Concepto remunerativo" rules={[{ required: true, message: 'Selecciona el concepto' }]}>
              <Select
                loading={catalogo.cargando}
                options={opciones.map((c) => ({ value: c.id, label: c.nombre }))}
                onChange={(id) => {
                  form.setFieldValue('concepto_definicion_id', undefined);
                  const concepto = opciones.find((c) => c.id === id);
                  if (CONCEPTOS_CON_DEFINICION.includes(concepto?.codigo)) fetchDefiniciones(concepto.id, concepto.codigo);
                }}
              />
            </Form.Item>
            {CONCEPTOS_CON_DEFINICION.includes(conceptoSeleccionado?.codigo) && (
              <Form.Item name="concepto_definicion_id" label="Clasificación PLAME" rules={[{ required: true, message: 'Selecciona la clasificación' }]}>
                <Select loading={cargandoDefiniciones} options={definiciones.filter((d) => d.activo).map((d) => ({ value: d.id, label: `${d.nombre} (${d.codigo_plame})` }))} />
              </Form.Item>
            )}
          </>
        )}
        <Form.Item
          name="observacion"
          label="Observación (visible para gerencia)"
          rules={accion === 'rechazar' ? [{ required: true, message: 'Indica el motivo del rechazo' }] : []}
        >
          <Input.TextArea rows={2} maxLength={500} showCount />
        </Form.Item>
      </Form>
      <Alert type="info" showIcon message="Validar registra el concepto en el ciclo (mismas reglas que el registro manual). No marca el pago." />
    </Modal>
  );
}

export default function PropuestasPortalPanel() {
  const [filtros, setFiltros] = useState({ estado: 'propuesto', page: 1 });
  const [seleccion, setSeleccion] = useState(null);
  const { datos, cargando, error, recargar, resolver } = usePropuestasConcepto(filtros);

  const columnas = [
    { title: 'Colaborador', render: (_, r) => <div><div>{r.colaborador?.nombre_completo}</div><Text type="secondary" className="text-xs">{r.colaborador?.legajo} · {r.colaborador?.area}</Text></div> },
    { title: 'Ciclo', render: (_, r) => r.ciclo?.nombre },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => (v === 'comision' ? 'Comisión' : 'Bono'), width: 95 },
    { title: 'Monto', dataIndex: 'monto', render: (v) => `S/ ${v}`, align: 'right', width: 110 },
    { title: 'Motivo', dataIndex: 'motivo', ellipsis: true },
    { title: 'Propuesto por', render: (_, r) => <div className="text-xs"><div>{r.propuesto_por}</div><Text type="secondary">{dayjs(r.creado_at).format('DD/MM/YYYY HH:mm')}</Text></div> },
    { title: 'Estado', render: (_, r) => <Tag color={ESTADOS[r.estado]?.[1]}>{ESTADOS[r.estado]?.[0] ?? r.estado}</Tag>, width: 120 },
    { title: '', width: 100, render: (_, r) => (r.estado === 'propuesto' ? <Button size="small" type="primary" onClick={() => setSeleccion(r)}>Resolver</Button> : (r.observacion_rrhh ?? '—')) },
  ];

  return (
    <div className="space-y-3">
      <Select
        className="w-56"
        aria-label="Estado"
        allowClear
        placeholder="Todas"
        value={filtros.estado}
        options={Object.entries(ESTADOS).map(([value, [label]]) => ({ value, label }))}
        onChange={(estado) => setFiltros({ estado, page: 1 })}
      />
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
          locale={{ emptyText: 'No hay propuestas del portal para este filtro' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 20, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros((f) => ({ ...f, page })),
          }}
        />
      )}
      {seleccion && <ModalResolver propuesta={seleccion} resolver={resolver} onCerrar={() => setSeleccion(null)} onResuelta={() => { setSeleccion(null); recargar(); }} />}
    </div>
  );
}
