import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, App, AutoComplete, Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Steps, Switch } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { MODALIDADES, MOTIVOS_SOLICITUD, TIPOS_CONTRATO, mensajeDeError } from '../constants/seleccion';

const PASOS = [
  { title: 'Puesto', campos: ['area_id', 'cargo', 'motivo', 'plazas', 'fecha_requerida', 'urgente', 'justificacion'] },
  { title: 'Condiciones', campos: ['salario_min', 'salario_max', 'tipo_contrato', 'modalidad', 'horario'] },
  { title: 'Perfil del puesto', campos: ['descripcion', 'actividades', 'requisitos', 'habilidades'] },
];

const normalizar = (texto) => (texto ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/**
 * Cargos sugeridos (texto libre): primero los del área elegida, luego los
 * del resto de la empresa, sin repetir nombres.
 */
function opcionesDeCargo(cargos, areaId) {
  const vistos = new Set();
  return [...cargos.filter((c) => c.area_id === areaId), ...cargos.filter((c) => c.area_id !== areaId)]
    .filter((c) => {
      const clave = normalizar(c.nombre);
      if (vistos.has(clave)) {
        return false;
      }
      vistos.add(clave);
      return true;
    })
    .map((c) => ({ value: c.nombre }));
}

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
      form.setFieldsValue({
        ...solicitud,
        fecha_requerida: solicitud.fecha_requerida ? dayjs(solicitud.fecha_requerida) : null,
        // Un sueldo fijo se guarda como rango con el mismo monto.
        salario_max: Number(solicitud.salario_max) === Number(solicitud.salario_min) ? null : solicitud.salario_max,
      });
    } else {
      form.setFieldsValue({ plazas: 1, motivo: 'nuevo_puesto', modalidad: 'presencial', tipo_contrato: 'plazo_fijo', urgente: false, habilidades: [] });
    }
  }, [abierto, solicitud, form]);

  const salarioMin = Form.useWatch('salario_min', form);
  const areaId = Form.useWatch('area_id', form);
  const rmv = referencias?.rmv ? Number(referencias.rmv) : null;
  const cargos = useMemo(() => opcionesDeCargo(referencias?.cargos ?? [], areaId), [referencias, areaId]);

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
        // Sueldo fijo: sin "hasta", el rango es el mismo monto.
        salario_max: valores.salario_max ?? valores.salario_min,
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
      centered
      styles={{ body: { maxHeight: 'calc(100vh - 220px)', overflowY: 'auto', overflowX: 'hidden' } }}
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
              placeholder="Escribe o selecciona el área"
              options={(referencias?.areas ?? []).map((a) => ({ value: a.id, label: a.nombre }))}
              notFoundContent="Sin áreas que coincidan"
              showSearch
              filterOption={(texto, opcion) => normalizar(opcion?.label).includes(normalizar(texto))}
            />
          </Form.Item>
          <Form.Item
            name="cargo"
            label="Cargo"
            rules={[{ required: true, min: 3, message: 'Indica el cargo (mín. 3 caracteres).' }]}
            extra={cargos.length ? 'Elige un cargo existente o escribe uno nuevo.' : undefined}
          >
            <AutoComplete
              options={cargos}
              maxLength={150}
              placeholder="Ej. Diseñador(a) gráfico(a)"
              filterOption={(texto, opcion) => normalizar(opcion?.value).includes(normalizar(texto))}
            />
          </Form.Item>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true }]}>
            <Select options={MOTIVOS_SOLICITUD} />
          </Form.Item>
          <Form.Item name="plazas" label="Número de plazas" rules={[{ required: true, type: 'number', min: 1, max: 50, message: 'Entre 1 y 50.' }]}>
            <InputNumber min={1} max={50} className="w-full!" />
          </Form.Item>
          <Form.Item name="fecha_requerida" label="Fecha de ingreso requerida" rules={[{ required: true, message: 'Indica la fecha.' }]}>
            <DatePicker className="w-full!" format="DD/MM/YYYY" disabledDate={(d) => d && d < dayjs().startOf('day')} />
          </Form.Item>
          <Form.Item name="urgente" label="Urgente" valuePropName="checked">
            <Switch />
          </Form.Item>
          <Form.Item
            name="justificacion"
            label="Detalle del motivo"
            className="sm:col-span-2"
            extra="Para RR. HH.: a quién reemplaza o por qué se crea el puesto. No se publica en el aviso."
          >
            <Input.TextArea rows={2} maxLength={2000} placeholder="Ej. Reemplazo de la diseñadora que renunció el 30/10." />
          </Form.Item>
        </div>

        <div className={paso === 1 ? 'grid grid-cols-1 gap-x-4 sm:grid-cols-2' : 'hidden'}>
          <Form.Item name="salario_min" label="Sueldo mensual (S/)" rules={[{ required: true, message: 'Indica el sueldo mensual.' }]}>
            <InputNumber min={0} className="w-full!" placeholder="Ej. 1400" />
          </Form.Item>
          <Form.Item
            name="salario_max"
            label="Hasta (S/)"
            extra="Solo si ofrecen un rango salarial."
            dependencies={['salario_min']}
            rules={[
              ({ getFieldValue }) => ({
                validator: (_, valor) => (valor == null || valor >= (getFieldValue('salario_min') ?? 0)
                  ? Promise.resolve()
                  : Promise.reject(new Error('Debe ser mayor o igual al sueldo mensual.'))),
              }),
            ]}
          >
            <InputNumber min={0} className="w-full!" placeholder="Opcional" />
          </Form.Item>
          {rmv && salarioMin != null && salarioMin < rmv && (
            <Alert
              className="mb-4 sm:col-span-2"
              type="warning"
              showIcon
              message={`El sueldo es menor a la RMV vigente (S/ ${rmv}, régimen ${referencias.rmv_regimen}) según Parámetros Laborales. Verifica si corresponde al tipo de contrato.`}
            />
          )}
          <Form.Item name="tipo_contrato" label="Tipo de contrato" rules={[{ required: true }]}>
            <Select options={TIPOS_CONTRATO} />
          </Form.Item>
          <Form.Item name="modalidad" label="Modalidad" rules={[{ required: true }]}>
            <Select options={MODALIDADES} />
          </Form.Item>
          <Form.Item name="horario" label="Horario laboral" className="sm:col-span-2">
            <Input.TextArea
              rows={2}
              maxLength={500}
              placeholder={'Ej. Lunes a sábado de 9:00 a. m. a 6:00 p. m.\nDos sábados al mes la jornada termina a la 1:00 p. m.'}
            />
          </Form.Item>
        </div>

        <div className={paso === 2 ? 'block' : 'hidden'}>
          <Form.Item name="descripcion" label="Descripción del puesto" rules={[{ required: true, min: 30, max: 2000, message: 'Entre 30 y 2000 caracteres.' }]}>
            <Input.TextArea
              rows={3}
              maxLength={2000}
              showCount
              placeholder="Ej. Buscamos un(a) diseñador(a) gráfico(a) creativo(a), con iniciativa y experiencia en piezas para redes sociales y comunicación visual de marca."
            />
          </Form.Item>
          <Form.Item name="actividades" label="Funciones principales" extra="Una función por línea.">
            <Input.TextArea
              rows={4}
              maxLength={4000}
              placeholder={'Diseñar piezas gráficas para redes sociales y material publicitario.\nAdaptar diseños a diferentes formatos y plataformas.'}
            />
          </Form.Item>
          <Form.Item name="requisitos" label="Requisitos" extra="Uno por línea. Indica si alguno es indispensable.">
            <Input.TextArea
              rows={4}
              maxLength={4000}
              placeholder={'Experiencia en diseño gráfico para redes sociales.\nManejo de Canva, Photoshop y/o Illustrator.\nPortafolio de trabajos (indispensable).'}
            />
          </Form.Item>
          <p className="mb-1 text-sm text-gray-700">Herramientas o habilidades clave <span className="text-gray-400">(opcional)</span></p>
          <p className="mb-2 text-xs text-gray-500">Nivel deseado de 1 (básico) a 5 (experto). Ayuda a RR. HH. a filtrar los CV.</p>
          <Form.List name="habilidades">
            {(campos, { add, remove }) => (
              <div className="mb-4 space-y-2">
                {campos.map((campo) => (
                  <div key={campo.key} className="flex items-start gap-2">
                    <Form.Item name={[campo.name, 'nombre']} className="mb-0! flex-1" rules={[{ required: true, message: 'Nombre' }]}>
                      <Input placeholder="Ej. Photoshop" maxLength={100} aria-label="Habilidad" />
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
        </div>
      </Form>
    </Modal>
  );
}
