import { Alert, DatePicker, Form, Input, InputNumber, Modal, Select } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';

/**
 * Datos del comprobante de honorarios (estructura E20/.4ta de PLAME) — se
 * completa manualmente cuando RR.HH. recibe el recibo real del locador, no
 * se genera solo al calcular la boleta. El indicador de retención de renta
 * de 4ta NO se pide acá: el backend lo deriva del cálculo ya existente
 * (CalcularReciboHonorarios), nunca se vuelve a calcular en el frontend.
 *
 * Se pide fresco vía verBoleta() al abrir (igual que BoletaImprimibleModal)
 * en vez de confiar en la fila de la tabla, porque el listado de boletas no
 * trae comprobante_rh cargado.
 */
export default function ComprobanteRhModal({ open, boletaId, verBoleta, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();
  const [boleta, setBoleta] = useState(null);
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    if (!open || !boletaId) return;
    let activo = true;
    setCargando(true);
    verBoleta(boletaId).then((data) => {
      if (!activo) return;
      setBoleta(data);
      const comprobante = data.comprobante_rh;
      form.setFieldsValue({
        tipo_comprobante: comprobante?.tipo_comprobante ?? null,
        serie: comprobante?.serie ?? '',
        numero: comprobante?.numero ?? '',
        fecha_emision: comprobante?.fecha_emision ? dayjs(comprobante.fecha_emision) : null,
        fecha_pago: comprobante?.fecha_pago ? dayjs(comprobante.fecha_pago) : null,
        indicador_retencion_regimen_pensionario: comprobante?.indicador_retencion_regimen_pensionario ?? null,
        importe_aporte_regimen_pensionario: comprobante?.importe_aporte_regimen_pensionario ?? null,
      });
    }).finally(() => activo && setCargando(false));
    return () => { activo = false; };
  }, [open, boletaId, verBoleta, form]);

  const indicadorPensionario = Form.useWatch('indicador_retencion_regimen_pensionario', form);

  const guardar = (values) => {
    onGuardar(boletaId, {
      ...values,
      fecha_emision: values.fecha_emision?.format('YYYY-MM-DD') ?? null,
      fecha_pago: values.fecha_pago?.format('YYYY-MM-DD') ?? null,
    });
  };

  return (
    <Modal
      title={`Comprobante de honorarios — ${boleta?.colaborador?.nombre_completo ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      okText="Guardar"
      cancelText="Cancelar"
      confirmLoading={submitting || cargando}
      destroyOnHidden
    >
      <Alert
        type="info"
        showIcon
        className="mb-4"
        message="El tipo de comprobante (Tabla 23) se configura en Configuraciones → Catálogos SUNAT → Comprobantes RH."
      />
      <div className="mb-4 rounded-lg bg-gray-50 px-3 py-2 text-sm">
        <span className="text-gray-500">Monto total del servicio (E20): </span>
        <span className="font-semibold text-gray-900">S/ {(boleta?.comprobante_rh?.monto_total_servicio ?? 0).toFixed(2)}</span>
        <span className="ml-1 text-xs text-gray-400">(calculado del honorario bruto, no editable acá)</span>
      </div>
      {/* V3 P5 — resultado derivado de CalcularReciboHonorarios, nunca un
          valor que el usuario pueda fijar manualmente (Sección 25 del
          encargo): solo lectura, distinto de tiene_suspension_renta_4ta
          (la configuración de entrada, que se gestiona en el formulario del
          colaborador). */}
      <div className="mb-4 rounded-lg bg-gray-50 px-3 py-2 text-sm">
        <span className="text-gray-500">Retención de 4ta categoría aplicada: </span>
        <span className={`font-semibold ${boleta?.comprobante_rh?.indicador_retencion_4ta ? 'text-amber-700' : 'text-gray-900'}`}>
          {boleta?.comprobante_rh?.indicador_retencion_4ta ? 'Sí' : 'No'}
        </span>
        <span className="ml-1 text-xs text-gray-400">(calculado automáticamente, no editable acá)</span>
      </div>
      <Form form={form} layout="vertical" onFinish={guardar}>
        <div className="grid grid-cols-2 gap-x-3">
          <Form.Item label="Serie" name="serie"><Input maxLength={4} placeholder="Ej: E001" /></Form.Item>
          <Form.Item label="Número" name="numero"><Input maxLength={8} placeholder="Ej: 00000123" /></Form.Item>
          <Form.Item label="Fecha de emisión" name="fecha_emision"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
          <Form.Item label="Fecha de pago" name="fecha_pago"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
        </div>
        {/* Valores 1/2/3 confirmados por el Anexo 3 (E20, campo 10:
            "Indicador de Retención a Régimen Pensionario" — 1: ONP,
            2: Sistema Privado de Pensiones, 3: Sin retención/No aplica). */}
        <Form.Item
          label="Retención a régimen pensionario"
          name="indicador_retencion_regimen_pensionario"
          extra="Si el locador pidió que se le retenga aporte previsional de este recibo."
        >
          <Select
            allowClear
            placeholder="Sin retención"
            options={[
              { value: '1', label: 'Sistema Nacional de Pensiones (ONP)' },
              { value: '2', label: 'Sistema Privado de Pensiones (AFP)' },
              { value: '3', label: 'Sin retención / no aplica' },
            ]}
          />
        </Form.Item>
        {(indicadorPensionario === '1' || indicadorPensionario === '2') && (
          <Form.Item
            label="Importe del aporte"
            name="importe_aporte_regimen_pensionario"
            rules={[{ required: true, message: 'Requerido cuando hay retención pensionaria' }]}
          >
            <InputNumber min={0} step={0.01} className="w-full" />
          </Form.Item>
        )}
      </Form>
    </Modal>
  );
}
