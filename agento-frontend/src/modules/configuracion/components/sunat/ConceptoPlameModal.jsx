import { DatePicker, Form, Input, Modal, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

const TIPO_COLOR = { ingreso: 'green', egreso: 'red', aportacion: 'blue' };

/**
 * Editar el código PLAME de un concepto (Tabla 22 SUNAT). Cada guardado
 * inserta una fila de historial nueva en el backend (nunca sobrescribe la
 * anterior) — vigencia_desde por defecto es hoy, pero puede adelantarse si
 * el cambio corresponde a una fecha específica ya conocida.
 */
export default function ConceptoPlameModal({ open, concepto, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();

  useEffect(() => {
    if (!open || !concepto) return;
    form.setFieldsValue({
      codigo_plame: concepto.codigo_plame ?? '',
      descripcion_sunat: concepto.descripcion_sunat ?? '',
      vigencia_desde: dayjs(),
    });
  }, [open, concepto, form]);

  const guardar = (values) => {
    onGuardar(concepto.id, {
      codigo_plame: values.codigo_plame?.trim() || null,
      descripcion_sunat: values.descripcion_sunat?.trim() || null,
      vigencia_desde: values.vigencia_desde.format('YYYY-MM-DD'),
    });
  };

  return (
    <Modal
      title="Editar código PLAME"
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      okText="Guardar"
      cancelText="Cancelar"
      confirmLoading={submitting}
      destroyOnHidden
    >
      <div className="mb-4 grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg bg-gray-50 p-3 text-sm">
        <span className="text-gray-400">Concepto</span>
        <span className="font-medium text-gray-900">{concepto?.nombre}</span>
        <span className="text-gray-400">Código interno</span>
        <span><code className="text-xs">{concepto?.codigo}</code></span>
        <span className="text-gray-400">Tipo</span>
        <span><Tag color={TIPO_COLOR[concepto?.tipo]}>{concepto?.tipo}</Tag></span>
      </div>
      <Form form={form} layout="vertical" onFinish={guardar}>
        <Form.Item
          label="Código PLAME (Tabla 22 — 4 dígitos)"
          name="codigo_plame"
          rules={[{ pattern: /^\d{1,4}$/, message: 'Solo dígitos (hasta 4)' }]}
          extra="Se completa con ceros a la izquierda automáticamente (ej. 121 → 0121)."
        >
          <Input placeholder="Sin configurar" maxLength={4} />
        </Form.Item>
        <Form.Item label="Descripción SUNAT" name="descripcion_sunat">
          <Input placeholder="Descripción oficial del código (opcional)" maxLength={255} />
        </Form.Item>
        <Form.Item
          label="Vigencia desde"
          name="vigencia_desde"
          rules={[{ required: true, message: 'La vigencia es obligatoria' }]}
          extra="Las boletas ya calculadas conservan su propio código histórico (snapshot) — este cambio solo afecta cálculos nuevos."
        >
          <DatePicker className="w-full" format="DD/MM/YYYY" />
        </Form.Item>
      </Form>
    </Modal>
  );
}
