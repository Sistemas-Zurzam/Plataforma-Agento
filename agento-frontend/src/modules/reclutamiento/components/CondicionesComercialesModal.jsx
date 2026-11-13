import { Alert, App, Form, Input, InputNumber, Modal, Select } from 'antd';
import { useEffect, useState } from 'react';
import { MODALIDADES_COBRO, mensajeDeError } from '../constants/seleccion';

/**
 * Condiciones comerciales del servicio de reclutamiento acordadas con la
 * empresa cliente. Son internas de Agento: no se muestran en el portal.
 */
export default function CondicionesComercialesModal({ solicitud, onGuardar, onCerrar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const modalidad = Form.useWatch('cobro_modalidad', form);

  useEffect(() => {
    if (solicitud) {
      const c = solicitud.condiciones ?? {};
      form.setFieldsValue({
        cobro_modalidad: c.cobro_modalidad ?? undefined,
        cobro_monto: c.cobro_monto != null ? Number(c.cobro_monto) : undefined,
        cobro_moneda: c.cobro_moneda ?? 'PEN',
        garantia_dias: c.garantia_dias ?? undefined,
        condiciones_notas: c.notas ?? undefined,
      });
    }
  }, [solicitud, form]);

  const guardar = async () => {
    const valores = await form.validateFields();
    setGuardando(true);
    try {
      await onGuardar(solicitud.id, {
        ...valores,
        cobro_moneda: valores.cobro_modalidad === 'porcentaje_salario' ? null : valores.cobro_moneda,
      });
      message.success('Condiciones comerciales guardadas.');
      onCerrar(true);
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  const esPorcentaje = modalidad === 'porcentaje_salario';

  return (
    <Modal
      open={Boolean(solicitud)}
      title={`Condiciones comerciales · ${solicitud?.cargo ?? ''}`}
      okText="Guardar"
      cancelText="Cancelar"
      onOk={guardar}
      okButtonProps={{ loading: guardando }}
      onCancel={() => onCerrar(false)}
      destroyOnHidden
    >
      <Alert type="info" showIcon className="mb-4" message="Registro interno de Agento: no se muestra a la empresa cliente en el portal. Queda en la bitácora de la solicitud." />
      <Form form={form} layout="vertical">
        <Form.Item name="cobro_modalidad" label="Modalidad de cobro">
          <Select allowClear placeholder="Sin definir" options={MODALIDADES_COBRO} />
        </Form.Item>
        {modalidad && (
          <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <Form.Item
              name="cobro_monto"
              label={esPorcentaje ? 'Porcentaje (%)' : 'Monto'}
              rules={[{ required: true, message: 'Indica el valor acordado.' }]}
            >
              <InputNumber min={0} max={esPorcentaje ? 100 : undefined} className="w-full!" />
            </Form.Item>
            {!esPorcentaje && (
              <Form.Item name="cobro_moneda" label="Moneda" rules={[{ required: true }]}>
                <Select options={[{ value: 'PEN', label: 'Soles (PEN)' }, { value: 'USD', label: 'Dólares (USD)' }]} />
              </Form.Item>
            )}
          </div>
        )}
        <Form.Item name="garantia_dias" label="Garantía de reposición (días)" extra="Plazo en que Agento repone al trabajador si no continúa.">
          <InputNumber min={0} max={365} className="w-full!" />
        </Form.Item>
        <Form.Item name="condiciones_notas" label="Notas">
          <Input.TextArea rows={3} maxLength={2000} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}
