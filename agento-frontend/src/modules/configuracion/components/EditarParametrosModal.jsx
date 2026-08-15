import { DatePicker, Form, InputNumber, Modal } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';
import { agruparParametros } from '../utils/agruparParametros';

const campoValor = (definicionId) => `valor_${definicionId}`;

export default function EditarParametrosModal({ open, regimen, onSubmit, onCancel, submitting }) {
  const [form] = Form.useForm();
  const grupos = regimen ? agruparParametros(regimen.parametros) : [];

  useEffect(() => {
    if (open && regimen) {
      const valoresIniciales = {};
      regimen.parametros.forEach((parametro) => {
        valoresIniciales[campoValor(parametro.definicion_id)] =
          parametro.valor !== null ? Number(parametro.valor) : undefined;
      });
      form.setFieldsValue({ ...valoresIniciales, vigencia_desde: dayjs() });
    }
  }, [open, regimen, form]);

  const handleFinish = (values) => {
    const { vigencia_desde: vigenciaDesde, ...campos } = values;
    const valoresPorDefinicion = {};
    regimen.parametros.forEach((parametro) => {
      const valor = campos[campoValor(parametro.definicion_id)];
      if (valor !== undefined && valor !== null) {
        valoresPorDefinicion[parametro.definicion_id] = valor;
      }
    });
    onSubmit(regimen.regimen, vigenciaDesde.format('YYYY-MM-DD'), valoresPorDefinicion);
  };

  return (
    <Modal
      title={regimen ? `Editar parámetros — ${regimen.regimen}` : ''}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText="Guardar cambios"
      cancelText="Cancelar"
      width={640}
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={handleFinish} size="small">
        <p className="mb-2 text-sm text-gray-500">
          Los valores que cambien quedan vigentes desde la fecha indicada; los anteriores se
          conservan en el historial.
        </p>



        <div className="flex flex-col gap-4">
          {grupos.map((grupo) => (
            <div key={grupo.nombre}>
              <p className="mb-2 text-xs font-semibold tracking-wide text-gray-400 uppercase">
                {grupo.nombre}
              </p>
              <div className="grid grid-cols-2 gap-x-4 sm:grid-cols-3">
                {grupo.parametros.map((parametro) => (
                  <Form.Item
                    key={parametro.definicion_id}
                    label={
                      <span className="text-xs text-gray-500">
                        {parametro.nombre} ({parametro.unidad})
                      </span>
                    }
                    name={campoValor(parametro.definicion_id)}
                    tooltip={
                      parametro.clave === 'asignacion_familiar_porcentaje'
                        ? 'Se calcula como % de la RMV'
                        : undefined
                    }
                    rules={[{ required: true, message: 'Requerido' }]}
                    style={{ marginBottom: 12 }}
                  >
                    <InputNumber min={0} step={0.01} className="w-full" />
                  </Form.Item>
                ))}
              </div>
            </div>
          ))}
        </div>

        <Form.Item
          label="Vigente desde"
          name="vigencia_desde"
          rules={[{ required: true, message: 'Selecciona la fecha de vigencia' }]}
          style={{ marginBottom: 16 }}
        >
          <DatePicker className="w-full" format="DD/MM/YYYY" />
        </Form.Item>
      </Form>
    </Modal>
  );
}
