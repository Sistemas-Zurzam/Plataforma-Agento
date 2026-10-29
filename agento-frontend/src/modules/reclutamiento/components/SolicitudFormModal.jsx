import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Steps, Switch } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { MODALIDADES, MOTIVOS_SOLICITUD, TIPOS_CONTRATO, mensajeDeError } from '../constants/seleccion';

const PASOS = [
  { title: 'Puesto', campos: ['area_id', 'cargo', 'motivo', 'plazas', 'fecha_requerida', 'urgente'] },
  { title: 'Condiciones', campos: ['salario_min', 'salario_max', 'tipo_contrato', 'modalidad', 'presupuesto'] },
  { title: 'Perfil', campos: ['descripcion', 'actividades', 'habilidades', 'justificacion'] },
];

/**
 * Solicitud de personal en pasos. "Guardar borrador" no exige completar los
 * pasos siguientes; "Enviar" valida todo y envía a RR. HH.
 */
export default function SolicitudFormModal({ abierto, solicitud, referencias, onGuardar, onCerrar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [paso, setPaso] = useState(0);
  const [enviando, setEnviando] = useState(null);

  // El padre monta el modal solo mientras está abierto: el paso inicia en 0.
  useEffect(() => {
    if (!abierto) {
      return;
    }
    form.resetFields();
    if (solicitud) {
      form.setFieldsValue({ ...solicitud, fecha_requerida: solicitud.fecha_requerida ? dayjs(solicitud.fecha_requerida) : null });
    } else {
      form.setFieldsValue({ plazas: 1, motivo: 'nuevo_puesto', modalidad: 'presencial', tipo_contrato: 'plazo_fijo', urgente: false, habilidades: [{ nivel: 3 }] });
    }
  }, [abierto, solicitud, form]);

  const salarioMin = Form.useWatch('salario_min', form);
  const rmv = referencias?.rmv ? Number(referencias.rmv) : null;

  const guardar = async (enviar) => {
    try {
      if (enviar) {
        await form.validateFields();
      } else {
        await form.validateFields(PASOS[0].campos.filter((c) => c !== 'urgente'));
      }
    } catch {
      message.warning('Revisa los campos marcados.');
      return;
    }
    const valores = form.getFieldsValue(true);
    setEnviando(enviar ? 'enviar' : 'borrador');
    try {
      await onGuardar({
        ...valores,
        fecha_requerida: valores.fecha_requerida?.format('YYYY-MM-DD'),
        habilidades: (valores.habilidades ?? []).filter((h) => h?.nombre),
        enviar,
      });
    } catch (error) {
      const errores = error?.response?.data?.errors;
      if (errores) {
        form.setFields(Object.entries(errores).map(([name, errors]) => ({ name: name.split('.').map((p) => (/^\d+$/.test(p) ? Number(p) : p)), errors })));
      }
      message.error(mensajeDeError(error));
    } finally {
      setEnviando(null);
    }
  };

  const siguiente = async () => {
    try {
      await form.validateFields(PASOS[paso].campos);
      setPaso(paso + 1);
    } catch {
      // Los errores se muestran junto a cada campo.
    }
  };

  return (
    <Modal
      open={abierto}
      title={solicitud ? 'Editar solicitud de personal' : 'Nueva solicitud de personal'}
      onCancel={onCerrar}
      width={760}
      destroyOnHidden
      footer={(
        <div className="flex flex-wrap justify-between gap-2">
          <Button onClick={() => guardar(false)} loading={enviando === 'borrador'} disabled={Boolean(enviando)}>Guardar borrador</Button>
          <Space>
            {paso > 0 && <Button onClick={() => setPaso(paso - 1)}>Anterior</Button>}
            {paso < PASOS.length - 1
              ? <Button type="primary" onClick={siguiente}>Siguiente</Button>
              : <Button type="primary" onClick={() => guardar(true)} loading={enviando === 'enviar'} disabled={Boolean(enviando)}>Enviar a RR. HH.</Button>}
          </Space>
        </div>
      )}
    >
      <Steps size="small" current={paso} items={PASOS.map((p) => ({ title: p.title }))} className="mb-6" onChange={setPaso} />
      <Form form={form} layout="vertical" requiredMark="optional">
        <div className={paso === 0 ? 'grid grid-cols-1 gap-x-4 sm:grid-cols-2' : 'hidden'}>
          <Form.Item name="area_id" label="Área" rules={[{ required: true, message: 'Selecciona el área.' }]}>
            <Select
              placeholder="Selecciona"
              options={(referencias?.areas ?? []).map((a) => ({ value: a.id, label: a.nombre }))}
              notFoundContent="No tienes áreas autorizadas"
              showSearch
              optionFilterProp="label"
            />
          </Form.Item>
          <Form.Item name="cargo" label="Cargo" rules={[{ required: true, min: 3, message: 'Indica el cargo (mín. 3 caracteres).' }]}>
            <Input maxLength={150} placeholder="Ej. Operario de almacén" />
          </Form.Item>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true }]}>
            <Select options={MOTIVOS_SOLICITUD} />
          </Form.Item>
          <Form.Item name="plazas" label="Número de plazas" rules={[{ required: true, type: 'number', min: 1, max: 50, message: 'Entre 1 y 50.' }]}>
            <InputNumber min={1} max={50} className="w-full!" />
          </Form.Item>
          <Form.Item name="fecha_requerida" label="Fecha requerida" rules={[{ required: true, message: 'Indica la fecha.' }]}>
            <DatePicker className="w-full!" format="DD/MM/YYYY" disabledDate={(d) => d && d < dayjs().startOf('day')} />
          </Form.Item>
          <Form.Item name="urgente" label="Urgente" valuePropName="checked">
            <Switch />
          </Form.Item>
        </div>

        <div className={paso === 1 ? 'grid grid-cols-1 gap-x-4 sm:grid-cols-2' : 'hidden'}>
          <Form.Item name="salario_min" label="Salario mínimo (S/)" rules={[{ required: true, message: 'Indica el salario mínimo.' }]}>
            <InputNumber min={0} className="w-full!" />
          </Form.Item>
          <Form.Item
            name="salario_max"
            label="Salario máximo (S/)"
            dependencies={['salario_min']}
            rules={[
              { required: true, message: 'Indica el salario máximo.' },
              ({ getFieldValue }) => ({
                validator: (_, valor) => (valor == null || valor >= (getFieldValue('salario_min') ?? 0)
                  ? Promise.resolve()
                  : Promise.reject(new Error('Debe ser mayor o igual al mínimo.'))),
              }),
            ]}
          >
            <InputNumber min={0} className="w-full!" />
          </Form.Item>
          {rmv && salarioMin != null && salarioMin < rmv && (
            <Alert
              className="mb-4 sm:col-span-2"
              type="warning"
              showIcon
              message={`El mínimo es menor a la RMV vigente (S/ ${rmv}, régimen ${referencias.rmv_regimen}) según Parámetros Laborales. Verifica si corresponde al tipo de contrato.`}
            />
          )}
          <Form.Item name="tipo_contrato" label="Tipo de contrato" rules={[{ required: true }]}>
            <Select options={TIPOS_CONTRATO} />
          </Form.Item>
          <Form.Item name="modalidad" label="Modalidad" rules={[{ required: true }]}>
            <Select options={MODALIDADES} />
          </Form.Item>
          <Form.Item name="presupuesto" label="Presupuesto (opcional)">
            <InputNumber min={0} className="w-full!" />
          </Form.Item>
        </div>

        <div className={paso === 2 ? 'block' : 'hidden'}>
          <Form.Item name="descripcion" label="Descripción del puesto" rules={[{ required: true, min: 30, max: 2000, message: 'Entre 30 y 2000 caracteres.' }]}>
            <Input.TextArea rows={4} maxLength={2000} showCount />
          </Form.Item>
          <Form.Item name="actividades" label="Actividades principales">
            <Input.TextArea rows={3} maxLength={4000} />
          </Form.Item>
          <p className="mb-2 text-sm font-medium text-gray-700">Habilidades y nivel deseado (1–5)</p>
          <Form.List name="habilidades">
            {(campos, { add, remove }) => (
              <div className="mb-4 space-y-2">
                {campos.map((campo) => (
                  <div key={campo.key} className="flex items-start gap-2">
                    <Form.Item name={[campo.name, 'nombre']} className="mb-0! flex-1" rules={[{ required: true, message: 'Nombre' }]}>
                      <Input placeholder="Ej. Excel" maxLength={100} aria-label="Habilidad" />
                    </Form.Item>
                    <Form.Item name={[campo.name, 'nivel']} className="mb-0!" rules={[{ required: true, message: 'Nivel' }]}>
                      <InputNumber min={1} max={5} aria-label="Nivel" />
                    </Form.Item>
                    <Button icon={<DeleteOutlined />} aria-label="Quitar habilidad" onClick={() => remove(campo.name)} />
                  </div>
                ))}
                <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ nivel: 3 })}>Agregar habilidad</Button>
              </div>
            )}
          </Form.List>
          <Form.Item name="justificacion" label="Justificación">
            <Input.TextArea rows={3} maxLength={2000} />
          </Form.Item>
        </div>
      </Form>
    </Modal>
  );
}
