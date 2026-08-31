import { Alert, Checkbox, DatePicker, Divider, Form, Input, Modal, Spin } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';

const opciones = [
  ['incluir_remuneracion', 'Remuneración hasta el día de cese'],
  ['incluir_cts', 'CTS trunca'],
  ['incluir_gratificacion', 'Gratificación trunca y bonificación extraordinaria'],
  ['incluir_vacaciones', 'Vacaciones pendientes y truncas'],
];

const soles = (valor) => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(Number(valor ?? 0));
const valoresIniciales = {
  incluir_remuneracion: true,
  incluir_cts: true,
  incluir_gratificacion: true,
  incluir_vacaciones: true,
};

export default function CesarColaboradorModal({ open, colaborador, submitting, onPrevisualizar, onGuardar, onCancel }) {
  const [form] = Form.useForm();
  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const cargarPreview = async () => {
    const values = form.getFieldsValue();
    if (!values.fecha_cese) return;
    setLoading(true);
    setError(null);
    try {
      const seleccion = Object.fromEntries(opciones.map(([key]) => [key, Boolean(values[key])]));
      setPreview(await onPrevisualizar({ ...seleccion, fecha_cese: values.fecha_cese.format('YYYY-MM-DD') }));
    } catch (e) {
      setPreview(null);
      const errors = e.response?.data?.errors;
      setError(errors ? Object.values(errors)[0]?.[0] : 'No se pudo calcular la liquidación para esta fecha.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    form.setFieldsValue({ fecha_cese: dayjs(), motivo_cese: null, ...valoresIniciales });
    const timer = setTimeout(() => {
      setPreview(null);
      setError(null);
      cargarPreview();
    }, 0);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, colaborador?.id]);

  const guardar = (values) => onGuardar({
    ...values,
    fecha_cese: values.fecha_cese.format('YYYY-MM-DD'),
    incluir_remuneracion: Boolean(values.incluir_remuneracion),
    incluir_cts: Boolean(values.incluir_cts),
    incluir_gratificacion: Boolean(values.incluir_gratificacion),
    incluir_vacaciones: Boolean(values.incluir_vacaciones),
  });

  return (
    <Modal title="Cesar colaborador y generar liquidación" open={open} onCancel={onCancel} onOk={() => form.submit()} okText="Cesar y generar liquidación" okButtonProps={{ danger: true, disabled: loading || !preview }} confirmLoading={submitting} width={680} centered destroyOnHidden>
      <Alert className="mb-4" type="warning" showIcon message="El cese y la liquidación se registrarán juntos al confirmar." />
      <Form form={form} layout="vertical" initialValues={valoresIniciales} onFinish={guardar} onValuesChange={(changed) => {
        if (Object.keys(changed).some((key) => key === 'fecha_cese' || key.startsWith('incluir_'))) setTimeout(cargarPreview, 0);
      }}>
        <Form.Item label="Fecha efectiva de cese" name="fecha_cese" rules={[{ required: true, message: 'Selecciona la fecha de cese' }]}>
          <DatePicker className="w-full" format="DD/MM/YYYY" minDate={colaborador?.fecha_ingreso ? dayjs(colaborador.fecha_ingreso) : undefined} maxDate={dayjs()} />
        </Form.Item>
        <Form.Item label="Motivo del cese" name="motivo_cese" rules={[{ required: true, message: 'Indica el motivo del cese' }]}>
          <Input.TextArea rows={2} maxLength={255} showCount />
        </Form.Item>
        <Divider orientation="left" plain>Conceptos a incluir</Divider>
        <div className="grid gap-2 sm:grid-cols-2">
          {opciones.map(([key, label]) => <Form.Item key={key} name={key} valuePropName="checked" noStyle><Checkbox>{label}</Checkbox></Form.Item>)}
        </div>
      </Form>

      <Divider orientation="left" plain>Vista previa</Divider>
      {loading ? <div className="py-8 text-center"><Spin /></div> : error ? <Alert type="error" showIcon message={error} /> : preview ? (
        <div className="space-y-2">
          {preview.alertas?.map((alerta) => <Alert key={alerta} type="warning" showIcon message={alerta} />)}
          <div className="overflow-hidden rounded-xl border border-gray-200">
          <div className="flex justify-between bg-gray-50 px-4 py-3 text-sm"><span>Remuneración vigente</span><strong>{soles(preview.remuneracion_vigente)}</strong></div>
          <div className="divide-y divide-gray-100 px-4">
            {preview.conceptos.map((concepto) => (
              <div key={concepto.codigo} className={`flex justify-between gap-4 py-2.5 text-sm ${concepto.incluido ? '' : 'text-gray-400 line-through'}`}>
                <div><p>{concepto.nombre}</p><p className="text-xs text-gray-400">{concepto.formula_texto}</p></div>
                <strong className="shrink-0">{soles(concepto.monto)}</strong>
              </div>
            ))}
          </div>
          <div className="flex justify-between bg-blue-50 px-4 py-3 text-base text-blue-900"><strong>Neto estimado</strong><strong>{soles(preview.neto_pagar)}</strong></div>
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
