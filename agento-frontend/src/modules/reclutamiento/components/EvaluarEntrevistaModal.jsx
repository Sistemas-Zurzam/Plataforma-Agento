import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Button, Form, Input, InputNumber, Modal, Select } from 'antd';
import { useEffect, useState } from 'react';
import { RECOMENDACIONES, mensajeDeError } from '../constants/seleccion';

/**
 * Evaluación de una entrevista: competencias en escala 1–5 normalizadas por
 * el backend con ((promedio − 1) / 4) × 100.
 */
const SIN_COMPETENCIAS = [];

export default function EvaluarEntrevistaModal({ entrevista, competencias = SIN_COMPETENCIAS, onEvaluar, onCerrar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  const calificaciones = Form.useWatch('calificaciones', form) ?? [];
  const valores = calificaciones.map((c) => c?.calificacion).filter(Boolean);
  const promedio = valores.length ? valores.reduce((a, b) => a + b, 0) / valores.length : null;

  useEffect(() => {
    if (entrevista) {
      form.resetFields();
      form.setFieldsValue({
        calificaciones: (competencias.length ? competencias : ['General']).map((nombre) => ({ competencia: nombre, calificacion: 3 })),
        recomendacion: 'avanzar',
      });
    }
  }, [entrevista, competencias, form]);

  const guardar = async () => {
    const datos = await form.validateFields();
    setEnviando(true);
    try {
      await onEvaluar(entrevista.id, datos);
      message.success('Evaluación registrada.');
      onCerrar(true);
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal open={Boolean(entrevista)} title="Evaluar entrevista" okText="Registrar evaluación" cancelText="Cancelar" onOk={guardar} okButtonProps={{ loading: enviando }} onCancel={() => onCerrar(false)} width={640} destroyOnHidden>
      <Form form={form} layout="vertical">
        <p className="mb-2 text-sm font-medium text-gray-700">Competencias (1 = muy bajo, 5 = sobresaliente)</p>
        <Form.List name="calificaciones">
          {(campos, { add, remove }) => (
            <div className="mb-3 space-y-2">
              {campos.map((campo) => (
                <div key={campo.key} className="flex items-start gap-2">
                  <Form.Item name={[campo.name, 'competencia']} className="mb-0! flex-1" rules={[{ required: true, message: 'Competencia' }]}>
                    <Input aria-label="Competencia" maxLength={100} />
                  </Form.Item>
                  <Form.Item name={[campo.name, 'calificacion']} className="mb-0!" rules={[{ required: true, message: '1–5' }]}>
                    <InputNumber min={1} max={5} aria-label="Calificación" />
                  </Form.Item>
                  <Button icon={<DeleteOutlined />} aria-label="Quitar" onClick={() => remove(campo.name)} disabled={campos.length === 1} />
                </div>
              ))}
              <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ calificacion: 3 })}>Agregar competencia</Button>
            </div>
          )}
        </Form.List>
        {promedio && (
          <p className="mb-3 text-sm text-gray-600">
            Promedio {promedio.toFixed(2)} → puntaje normalizado {(((promedio - 1) / 4) * 100).toFixed(1)}
          </p>
        )}
        <Form.Item name="fortalezas" label="Fortalezas" rules={[{ required: true, message: 'Indica las fortalezas.' }]}>
          <Input.TextArea rows={2} maxLength={2000} />
        </Form.Item>
        <Form.Item name="riesgos" label="Riesgos" rules={[{ required: true, message: 'Indica los riesgos.' }]}>
          <Input.TextArea rows={2} maxLength={2000} />
        </Form.Item>
        <Form.Item name="recomendacion" label="Recomendación" rules={[{ required: true }]}>
          <Select options={RECOMENDACIONES} />
        </Form.Item>
        <Form.Item name="comentarios" label="Comentarios">
          <Input.TextArea rows={2} maxLength={4000} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
