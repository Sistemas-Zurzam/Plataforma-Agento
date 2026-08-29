import { Form, Input, InputNumber, Modal, Select, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { useConceptoDefinicionesPlame } from '../../configuracion/hooks/useConceptoDefinicionesPlame';

const CONCEPTOS_REGISTRABLES = [
  { codigo: 'COMISION', nombre: 'Comisión por ventas', tipo: 'ingreso' },
  { codigo: 'BONIFICACION', nombre: 'Bonificación', tipo: 'ingreso' },
  { codigo: 'BONO_NO_REMUNERATIVO', nombre: 'Bono / liberalidad no remunerativa', tipo: 'ingreso' },
  { codigo: 'ADELANTO_SUELDO', nombre: 'Adelanto de sueldo', tipo: 'egreso' },
  { codigo: 'DESCUENTO_ERROR_OPERATIVO', nombre: 'Descuento por error operativo', tipo: 'egreso' },
  { codigo: 'DESCUENTO_COMPRA_MERCADERIA', nombre: 'Descuento por compra de mercadería', tipo: 'egreso' },
];

// Demasiado genéricos para PLAME (Tabla 22) por sí solos — exigen elegir
// una clasificación concreta ya definida en Catálogos SUNAT.
const CONCEPTOS_CON_DEFINICION = ['BONIFICACION', 'BONO_NO_REMUNERATIVO'];

/**
 * Registra un concepto manual (comisión/bono/adelanto/descuento) para UN
 * colaborador en el ciclo activo — el motor lo recoge automáticamente al
 * calcular. No permite elegir un código libre: solo los del catálogo, y el
 * tipo (ingreso/egreso) que decide dónde aparece en la boleta lo determina
 * siempre el catálogo, nunca este formulario.
 *
 * Un locador (Recibos por Honorarios) no tiene relación laboral, así que
 * solo puede recibir conceptos de descuento (egreso) — el backend
 * (CicloRemunerativoService::registrarConcepto) ya lo rechaza si se intenta
 * lo contrario, pero `esHonorarios` evita ofrecer la opción inválida.
 */
export default function RegistrarConceptoModal({ open, onCancel, onSubmit, loading, colaborador, conceptos, conceptosLoading, catalogo, esHonorarios }) {
  const [form] = Form.useForm();
  const [tipoSeleccionado, setTipoSeleccionado] = useState(null);
  const { definiciones, fetchDefiniciones } = useConceptoDefinicionesPlame();

  useEffect(() => {
    if (!open) {
      form.resetFields();
      setTipoSeleccionado(null);
    }
  }, [open, form]);

  const handleCambioConcepto = (value) => {
    setTipoSeleccionado(opcionesDisponibles.find((c) => c.codigo === value)?.tipo);
    form.setFieldValue('concepto_definicion_id', undefined);
    if (CONCEPTOS_CON_DEFINICION.includes(value)) {
      const concepto = catalogo.find((c) => c.codigo === value);
      if (concepto) fetchDefiniciones(concepto.id);
    }
  };

  const handleOk = async () => {
    const values = await form.validateFields();
    const concepto = catalogo.find((c) => c.codigo === values.codigo);
    await onSubmit(colaborador.id, {
      concepto_id: concepto.id,
      concepto_definicion_id: values.concepto_definicion_id,
      monto: values.monto,
      motivo: values.motivo,
    });
    form.resetFields();
  };

  const opcionesDisponibles = CONCEPTOS_REGISTRABLES
    .filter((c) => catalogo.some((k) => k.codigo === c.codigo))
    .filter((c) => !esHonorarios || c.tipo === 'egreso');

  return (
    <Modal
      title={`Registrar concepto — ${colaborador?.nombre_completo ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      confirmLoading={loading}
      okText="Registrar"
      cancelText="Cancelar"
      width={{ xs: '95%', sm: '85%', md: 640 }}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" className="mb-4">
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
        {CONCEPTOS_CON_DEFINICION.includes(Form.useWatch('codigo', form)) && (
          <Form.Item
            name="concepto_definicion_id"
            label="Clasificación PLAME"
            rules={[{ required: true, message: 'Selecciona la clasificación específica' }]}
            extra="Necesaria para declarar este concepto correctamente ante SUNAT — configúrala en Catálogos SUNAT si no aparece la que buscas."
          >
            <Select
              placeholder="Selecciona una clasificación"
              options={definiciones.filter((d) => d.activo).map((d) => ({ value: d.id, label: `${d.nombre} (${d.codigo_plame})` }))}
            />
          </Form.Item>
        )}
        <Form.Item name="monto" label="Monto (S/)" rules={[{ required: true, message: 'Ingresa un monto' }]}>
          <InputNumber className="w-full" min={0.01} step={0.01} precision={2} />
        </Form.Item>
        <Form.Item name="motivo" label="Motivo / referencia">
          <Input.TextArea rows={2} maxLength={255} placeholder="Ej. Comisión ventas julio, adelanto solicitado el 05/08..." />
        </Form.Item>
        {tipoSeleccionado === 'egreso' && (
          <p className="text-xs text-gray-400">Este concepto se descontará del neto a pagar (no se suma a los ingresos).</p>
        )}
      </Form>

      <p className="mb-2 text-sm font-semibold text-gray-700">Conceptos ya registrados en este período</p>
      <Table
        size="small"
        rowKey="id"
        loading={conceptosLoading}
        dataSource={conceptos}
        pagination={false}
        locale={{ emptyText: 'Sin conceptos registrados todavía' }}
        columns={[
          { title: 'Concepto', dataIndex: 'nombre' },
          { title: 'Clasificación', dataIndex: 'concepto_definicion_nombre', render: (v) => v ?? <span className="text-gray-300">—</span> },
          {
            title: 'Tipo',
            dataIndex: 'tipo',
            width: 100,
            render: (tipo) => <Tag color={tipo === 'egreso' ? 'red' : 'green'}>{tipo === 'egreso' ? 'Descuento' : 'Ingreso'}</Tag>,
          },
          { title: 'Monto', dataIndex: 'monto', width: 110, render: (m) => `S/ ${Number(m).toFixed(2)}` },
          { title: 'Motivo', dataIndex: 'motivo', ellipsis: true },
        ]}
      />
    </Modal>
  );
}
