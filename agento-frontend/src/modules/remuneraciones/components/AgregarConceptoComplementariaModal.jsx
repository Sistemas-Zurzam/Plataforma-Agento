import { Form, Input, InputNumber, Modal, Select, Tag } from 'antd';
import { useEffect } from 'react';
import { useConceptoDefinicionesPlame } from '../../configuracion/hooks/useConceptoDefinicionesPlame';

// Mismo catálogo "registrable" que RegistrarConceptoModal (conceptos de
// ciclo) — una complementaria no debería poder menos de lo que ya permite un
// ciclo normal. Exportado para que el desglose de PlanillasComplementariasModal
// pueda traducir el código a un nombre legible sin duplicar la lista.
export const CONCEPTOS_REGISTRABLES = [
  { codigo: 'COMISION', nombre: 'Comisión por ventas', tipo: 'ingreso' },
  { codigo: 'BONIFICACION', nombre: 'Bonificación', tipo: 'ingreso' },
  { codigo: 'BONO_NO_REMUNERATIVO', nombre: 'Bono / liberalidad no remunerativa', tipo: 'ingreso' },
  { codigo: 'ADELANTO_SUELDO', nombre: 'Adelanto de sueldo', tipo: 'egreso' },
  { codigo: 'DESCUENTO_ERROR_OPERATIVO', nombre: 'Descuento por error operativo', tipo: 'egreso' },
  { codigo: 'DESCUENTO_COMPRA_MERCADERIA', nombre: 'Descuento por compra de mercadería', tipo: 'egreso' },
];

// Demasiado genéricos para PLAME (Tabla 22) por sí solos — igual que en
// RegistrarConceptoModal, exigen elegir una clasificación concreta ya
// definida en Catálogos SUNAT.
const CONCEPTOS_CON_DEFINICION = ['BONIFICACION', 'BONO_NO_REMUNERATIVO'];

/**
 * Agrega un concepto manual (bono/comisión/descuento) a UN colaborador
 * dentro de una planilla complementaria — solo mientras esté "calculada"
 * (antes de aprobarla), igual que exige el backend
 * (PlanillaComplementariaService::agregarConcepto).
 */
export default function AgregarConceptoComplementariaModal({ open, onCancel, onSubmit, loading, detalle, catalogo }) {
  const [form] = Form.useForm();
  const { definiciones, loading: definicionesLoading, fetchDefiniciones } = useConceptoDefinicionesPlame();

  useEffect(() => {
    if (!open) form.resetFields();
  }, [open, form]);

  // No filtramos por régimen acá (no llega esa info a este modal) — si el
  // colaborador es un locador y se intenta un ingreso, el backend
  // (PlanillaComplementariaService::agregarConcepto) lo rechaza igual con un
  // mensaje claro, misma regla que RegistrarConceptoModal delega al backend.
  const opcionesDisponibles = CONCEPTOS_REGISTRABLES.filter((c) => catalogo.some((k) => k.codigo === c.codigo));
  const codigoSeleccionado = Form.useWatch('codigo', form);
  const esOtroConceptoPlame = codigoSeleccionado === 'BONO_NO_REMUNERATIVO';

  const handleCambioConcepto = (value) => {
    form.setFieldValue('concepto_definicion_id', undefined);
    if (CONCEPTOS_CON_DEFINICION.includes(value)) {
      const concepto = catalogo.find((c) => c.codigo === value);
      if (concepto) fetchDefiniciones(concepto.id, concepto.codigo);
    }
  };

  const handleOk = async () => {
    const values = await form.validateFields();
    const concepto = catalogo.find((c) => c.codigo === values.codigo);
    await onSubmit(detalle.detalleId, concepto.id, values.concepto_definicion_id, values.monto, values.motivo);
    form.resetFields();
  };

  return (
    <Modal
      title={`Agregar concepto — ${detalle?.colaboradorNombre ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      confirmLoading={loading}
      okText="Agregar"
      cancelText="Cancelar"
      width={480}
      destroyOnHidden
    >
      <p className="mb-3 text-xs text-gray-500">
        Se suma a la diferencia de esta complementaria — la boleta ya pagada no se modifica.
      </p>
      <Form form={form} layout="vertical">
        <Form.Item name="codigo" label="Concepto" rules={[{ required: true, message: 'Selecciona un concepto' }]}>
          <Select
            placeholder="Selecciona un concepto"
            options={opcionesDisponibles.map((c) => ({
              value: c.codigo,
              label: (
                <span>
                  {c.nombre} <Tag color={c.tipo === 'egreso' ? 'red' : 'green'} className="ml-1">{c.tipo === 'egreso' ? 'Descuento' : 'Ingreso'}</Tag>
                </span>
              ),
            }))}
            onChange={handleCambioConcepto}
          />
        </Form.Item>
        {CONCEPTOS_CON_DEFINICION.includes(codigoSeleccionado) && (
          <Form.Item
            name="concepto_definicion_id"
            label="Clasificación PLAME"
            rules={[{ required: true, message: 'Selecciona la clasificación específica' }]}
            extra={esOtroConceptoPlame
              ? 'Los códigos 1001–1020 son definiciones propias del empleador. Registra primero el nombre y código que usará la empresa en Configuración → Catálogos SUNAT → Conceptos PLAME.'
              : 'Selecciona la bonificación oficial que corresponda según la Tabla 22 de SUNAT.'}
          >
            <Select
              placeholder="Selecciona una clasificación"
              loading={definicionesLoading}
              disabled={definicionesLoading}
              notFoundContent={definicionesLoading
                ? 'Cargando clasificaciones...'
                : esOtroConceptoPlame
                  ? 'Configura una definición 1001–1020 en Catálogos SUNAT'
                  : 'No hay clasificaciones PLAME cargadas; verifica las migraciones'}
              options={definiciones.filter((d) => d.activo).map((d) => ({ value: d.id, label: `${d.nombre} (${d.codigo_plame})` }))}
            />
          </Form.Item>
        )}
        <Form.Item name="monto" label="Monto (S/)" rules={[{ required: true, message: 'Ingresa un monto' }]}>
          <InputNumber className="w-full" min={0.01} step={0.01} precision={2} />
        </Form.Item>
        <Form.Item name="motivo" label="Motivo / referencia">
          <Input.TextArea rows={2} maxLength={255} placeholder="Ej. Comisión de ventas de agosto no incluida al pagar..." />
        </Form.Item>
      </Form>
    </Modal>
  );
}
