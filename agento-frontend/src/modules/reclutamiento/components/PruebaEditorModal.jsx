import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, Card, Form, Input, InputNumber, Modal, Select } from 'antd';
import { useEffect, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';

/**
 * Editor de una prueba en borrador. El "valor" de cada opción es la clave de
 * calificación: nunca se envía al candidato.
 */
export default function PruebaEditorModal({ abierto, prueba, onGuardar, onCerrar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const dimensiones = Form.useWatch('dimensiones', form) ?? [];

  useEffect(() => {
    if (!abierto) {
      return;
    }
    form.resetFields();
    form.setFieldsValue(prueba ?? {
      tipo: 'conocimientos',
      tiempo_limite_minutos: 20,
      dimensiones: [{ clave: 'd1', nombre: '', peso: 1 }],
      preguntas: [],
    });
  }, [abierto, prueba, form]);

  const guardar = async () => {
    const valores = await form.validateFields();
    setGuardando(true);
    try {
      await onGuardar(valores);
      onCerrar();
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Modal open={abierto} title={prueba ? `Editar ${prueba.nombre} (v${prueba.version})` : 'Nueva prueba'} width={860} onOk={guardar} okText="Guardar borrador" cancelText="Cancelar" okButtonProps={{ loading: guardando }} onCancel={onCerrar} destroyOnHidden>
      <Alert
        type="warning"
        showIcon
        className="mb-4"
        message="Agento no incluye baterías psicométricas validadas. Usa contenido aprobado por un profesional; la prueba se mostrará como “no validada” hasta registrar la fuente de validación."
      />
      <Form form={form} layout="vertical">
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
          <Form.Item name="nombre" label="Nombre" className="sm:col-span-2" rules={[{ required: true }]}>
            <Input maxLength={150} />
          </Form.Item>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select options={[{ value: 'conocimientos', label: 'Conocimientos' }, { value: 'psicometrica', label: 'Psicométrica' }]} />
          </Form.Item>
          <Form.Item name="tiempo_limite_minutos" label="Tiempo límite (min)" rules={[{ required: true }]}>
            <InputNumber min={1} max={240} className="w-full!" />
          </Form.Item>
          <Form.Item name="descripcion" label="Instrucciones para el candidato" className="sm:col-span-2">
            <Input.TextArea rows={2} maxLength={2000} />
          </Form.Item>
        </div>

        <p className="mb-2 text-sm font-medium">Dimensiones (peso relativo)</p>
        <Form.List name="dimensiones">
          {(campos, { add, remove }) => (
            <div className="mb-4 space-y-2">
              {campos.map((campo) => (
                <div key={campo.key} className="flex items-start gap-2">
                  <Form.Item name={[campo.name, 'clave']} hidden><Input /></Form.Item>
                  <Form.Item name={[campo.name, 'nombre']} className="mb-0! flex-1" rules={[{ required: true, message: 'Nombre' }]}>
                    <Input placeholder="Ej. Razonamiento" maxLength={100} aria-label="Dimensión" />
                  </Form.Item>
                  <Form.Item name={[campo.name, 'peso']} className="mb-0!" rules={[{ required: true }]}>
                    <InputNumber min={1} max={100} addonBefore="Peso" aria-label="Peso" />
                  </Form.Item>
                  <Button icon={<DeleteOutlined />} aria-label="Quitar dimensión" disabled={campos.length === 1} onClick={() => remove(campo.name)} />
                </div>
              ))}
              <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ clave: `d${Date.now()}`, peso: 1 })}>Agregar dimensión</Button>
            </div>
          )}
        </Form.List>

        <p className="mb-2 text-sm font-medium">Preguntas</p>
        <Form.List name="preguntas">
          {(preguntas, { add, remove }) => (
            <div className="space-y-3">
              {preguntas.map((pregunta, indice) => (
                <Card key={pregunta.key} size="small" title={`Pregunta ${indice + 1}`} extra={<Button size="small" danger onClick={() => remove(pregunta.name)}>Quitar</Button>}>
                  <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-3">
                    <Form.Item name={[pregunta.name, 'enunciado']} label="Enunciado" className="sm:col-span-3" rules={[{ required: true }]}>
                      <Input.TextArea rows={2} maxLength={2000} />
                    </Form.Item>
                    <Form.Item name={[pregunta.name, 'dimension']} label="Dimensión" rules={[{ required: true }]}>
                      <Select options={dimensiones.filter((d) => d?.clave).map((d) => ({ value: d.clave, label: d.nombre || d.clave }))} />
                    </Form.Item>
                    <Form.Item name={[pregunta.name, 'peso']} label="Peso" initialValue={1}>
                      <InputNumber min={1} max={10} className="w-full!" />
                    </Form.Item>
                  </div>
                  <Form.List name={[pregunta.name, 'opciones']}>
                    {(opciones, { add: agregar, remove: quitar }) => (
                      <div className="space-y-2">
                        {opciones.map((opcion) => (
                          <div key={opcion.key} className="flex items-start gap-2">
                            <Form.Item name={[opcion.name, 'texto']} className="mb-0! flex-1" rules={[{ required: true, message: 'Texto' }]}>
                              <Input placeholder="Opción" maxLength={500} aria-label="Texto de la opción" />
                            </Form.Item>
                            <Form.Item name={[opcion.name, 'valor']} className="mb-0!" rules={[{ required: true, message: 'Valor' }]}>
                              <InputNumber min={0} max={100} addonBefore="Valor" aria-label="Valor (clave)" />
                            </Form.Item>
                            <Button icon={<DeleteOutlined />} aria-label="Quitar opción" disabled={opciones.length <= 2} onClick={() => quitar(opcion.name)} />
                          </div>
                        ))}
                        <Button size="small" type="dashed" icon={<PlusOutlined />} onClick={() => agregar({ valor: 0 })}>Opción</Button>
                      </div>
                    )}
                  </Form.List>
                </Card>
              ))}
              <Button
                type="dashed"
                icon={<PlusOutlined />}
                onClick={() => add({ dimension: dimensiones[0]?.clave, peso: 1, opciones: [{ valor: 1 }, { valor: 0 }] })}
              >
                Agregar pregunta
              </Button>
            </div>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}
