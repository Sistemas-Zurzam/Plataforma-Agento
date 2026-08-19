import { Alert, Checkbox, Form, Input, Modal, Select } from 'antd';
import { useEffect } from 'react';
import { REGIMEN_OPTIONS } from '../../configuracion/constants/regimenLaboral';

/**
 * Configuración previsional del colaborador para Remuneraciones — SOLO
 * datos propios del colaborador. Nunca edita parámetros legales nacionales
 * (tasas, UIT, RMV) — esos se gestionan en Configuración → Parámetros
 * Laborales.
 *
 * "Locacion de Servicios" es un locador (Recibos por Honorarios): no tiene
 * ONP/AFP ni asignación familiar (no hay relación laboral), así que ese
 * bloque se reemplaza por la suspensión de retenciones de renta de 4ta —
 * ver CalcularReciboHonorarios en el backend.
 */
export default function ConfiguracionNominaModal({ open, onCancel, onSubmit, loading, colaborador, afps }) {
  const [form] = Form.useForm();
  const regimenLaboral = Form.useWatch('regimen_laboral', form);
  const sistemaPrevisional = Form.useWatch('sistema_previsional', form);

  useEffect(() => {
    if (open && colaborador) {
      form.setFieldsValue({
        regimen_laboral: colaborador.regimen_laboral ?? 'General',
        sistema_previsional: colaborador.sistema_previsional ?? undefined,
        afp_id: colaborador.afp_id ?? undefined,
        tipo_comision: colaborador.tipo_comision ?? undefined,
        cuspp: colaborador.cuspp ?? '',
        tiene_hijos_asignacion_familiar: colaborador.tiene_hijos_asignacion_familiar ?? false,
        tiene_suspension_renta_4ta: colaborador.tiene_suspension_renta_4ta ?? false,
      });
    }
  }, [open, colaborador, form]);

  const handleOk = async () => {
    const values = await form.validateFields();
    await onSubmit(colaborador.id, values);
    form.resetFields();
  };

  const esHonorarios = regimenLaboral === 'Locacion de Servicios';
  const esAfp = !esHonorarios && sistemaPrevisional && sistemaPrevisional !== 'onp';

  return (
    <Modal
      title={`Configuración de planilla — ${colaborador?.nombre_completo ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      confirmLoading={loading}
      okText="Guardar configuración"
      cancelText="Cancelar"
      width={{ xs: '95%', sm: '85%', md: 560 }}
      destroyOnHidden
    >
      <Alert
        type="info"
        showIcon
        className="mb-4"
        message="Estos datos afectan cómo se calcula la próxima boleta de este colaborador. No modifican boletas ya calculadas ni las tasas legales vigentes."
      />
      <Form form={form} layout="vertical">
        <Form.Item name="regimen_laboral" label="Régimen laboral" rules={[{ required: true, message: 'Selecciona un régimen' }]}>
          <Select options={REGIMEN_OPTIONS} />
        </Form.Item>

        {esHonorarios ? (
          <Form.Item name="tiene_suspension_renta_4ta" valuePropName="checked">
            <Checkbox>Presentó constancia de suspensión de retenciones de renta de 4ta categoría</Checkbox>
          </Form.Item>
        ) : (
          <>
            <Form.Item name="sistema_previsional" label="Sistema previsional" rules={[{ required: true, message: 'Selecciona ONP o una AFP' }]}>
              <Select
                options={[
                  { value: 'onp', label: 'ONP' },
                  ...afps.map((afp) => ({ value: afp.clave, label: `AFP ${afp.nombre}` })),
                ]}
                onChange={(value) => {
                  if (value === 'onp') {
                    form.setFieldsValue({ afp_id: undefined, tipo_comision: undefined, cuspp: '' });
                  }
                }}
              />
            </Form.Item>
            {esAfp && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
                <Form.Item
                  name="afp_id"
                  label="Administradora"
                  rules={[{ required: true, message: 'Requerido' }]}
                >
                  <Select options={afps.map((afp) => ({ value: afp.id, label: afp.nombre }))} />
                </Form.Item>
                <Form.Item name="tipo_comision" label="Tipo de comisión" rules={[{ required: true, message: 'Requerido' }]}>
                  <Select
                    options={[
                      { value: 'flujo', label: 'Comisión por flujo' },
                      { value: 'mixta', label: 'Comisión mixta' },
                    ]}
                  />
                </Form.Item>
                <Form.Item
                  name="cuspp"
                  label="CUSPP"
                  className="sm:col-span-2"
                  rules={[{ required: true, message: 'El CUSPP es obligatorio para AFP' }]}
                >
                  <Input placeholder="Código único del SPP" maxLength={20} />
                </Form.Item>
              </div>
            )}
            <Form.Item name="tiene_hijos_asignacion_familiar" valuePropName="checked">
              <Checkbox>Percibe asignación familiar (tiene hijos menores a cargo)</Checkbox>
            </Form.Item>
          </>
        )}
      </Form>
    </Modal>
  );
}
