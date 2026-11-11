import { Alert, DatePicker, Form, Input, Modal, Select } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';

// Causales de extinción (TUO D.Leg. 728, art. 16). Mismas claves que Colaborador::CAUSALES_CESE.
const CAUSALES = [
  { value: 'renuncia', label: 'Renuncia o retiro voluntario' },
  { value: 'vencimiento_contrato', label: 'Vencimiento del plazo o término de la obra/servicio' },
  { value: 'mutuo_disenso', label: 'Mutuo disenso' },
  { value: 'despido_causa_justa', label: 'Despido por causa justa (conducta o capacidad)' },
  { value: 'despido_arbitrario', label: 'Despido arbitrario (sin expresión de causa o no probada)' },
  { value: 'periodo_prueba', label: 'Cese durante el período de prueba' },
  { value: 'causa_objetiva', label: 'Terminación por causa objetiva' },
  { value: 'fallecimiento', label: 'Fallecimiento' },
  { value: 'invalidez', label: 'Invalidez absoluta permanente' },
  { value: 'jubilacion', label: 'Jubilación' },
];

/**
 * El cese solo registra hasta qué día se le paga al colaborador. El sueldo de
 * ese mes sale en la boleta del ciclo; los beneficios se liquidan aparte en
 * Nóminas → Liquidaciones. La causal tipificada decide si corresponde
 * indemnización por despido; mostrarCausal=false cuando el flujo que guarda
 * el cese no la recibe (se completa luego en la liquidación).
 */
export default function CesarColaboradorModal({ open, colaborador, submitting, onGuardar, onCancel, valoresIniciales, mostrarCausal = true }) {
  const esHonorarios = colaborador?.regimen_laboral === 'Locacion de Servicios';
  const pideCausal = mostrarCausal && !esHonorarios;
  const [form] = Form.useForm();

  // valoresIniciales: precarga opcional (p. ej. desde una solicitud de cese
  // aprobada del Portal de Empresa); RR. HH. puede ajustarlos antes de guardar.
  useEffect(() => {
    if (open) {
      form.setFieldsValue({
        fecha_cese: valoresIniciales?.fecha_cese ? dayjs(valoresIniciales.fecha_cese) : dayjs(),
        motivo_cese: valoresIniciales?.motivo_cese ?? null,
        causal_cese: valoresIniciales?.causal_cese ?? undefined,
      });
    }
  }, [open, form, valoresIniciales]);

  const guardar = (values) => onGuardar({ ...values, fecha_cese: values.fecha_cese.format('YYYY-MM-DD') });

  return (
    <Modal title="Registrar cese" open={open} onCancel={onCancel} onOk={() => form.submit()} okText="Registrar cese" okButtonProps={{ danger: true }} confirmLoading={submitting} width={560} centered destroyOnHidden>
      <Alert
        className="mb-4"
        type="info"
        showIcon
        message="La fecha de cese es el último día trabajado."
        description={esHonorarios
          ? 'El recibo por honorarios del mes se calculará hasta esa fecha.'
          : 'La boleta del mes pagará hasta esa fecha. La liquidación de beneficios (CTS, gratificación y vacaciones) se calcula después en Nóminas → Liquidaciones.'}
      />
      <Form form={form} layout="vertical" onFinish={guardar}>
        <Form.Item label="Fecha de cese (último día trabajado)" name="fecha_cese" rules={[{ required: true, message: 'Selecciona la fecha de cese' }]}>
          <DatePicker className="w-full" format="DD/MM/YYYY" minDate={colaborador?.fecha_ingreso ? dayjs(colaborador.fecha_ingreso) : undefined} maxDate={dayjs()} />
        </Form.Item>
        {pideCausal && (
          <Form.Item label="Causal del cese" name="causal_cese" rules={[{ required: true, message: 'Selecciona la causal' }]}
            extra="Solo el despido arbitrario genera indemnización por despido en la liquidación.">
            <Select placeholder="Selecciona la causal" options={CAUSALES} />
          </Form.Item>
        )}
        <Form.Item label="Motivo del cese (detalle)" name="motivo_cese" rules={[{ required: true, message: 'Indica el motivo del cese' }]}>
          <Input.TextArea rows={2} maxLength={255} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}
