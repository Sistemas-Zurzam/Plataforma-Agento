import { DeleteOutlined, EditOutlined } from '@ant-design/icons';
import { Button, Form, Input, InputNumber, Modal, Select, Table, Tag, Tooltip } from 'antd';
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
export default function RegistrarConceptoModal({ open, onCancel, onSubmit, onUpdate, onDelete, loading, colaborador, conceptos, conceptosLoading, catalogo, esHonorarios }) {
  const [form] = Form.useForm();
  const [tipoSeleccionado, setTipoSeleccionado] = useState(null);
  const [editandoId, setEditandoId] = useState(null);
  const { definiciones, loading: definicionesLoading, fetchDefiniciones } = useConceptoDefinicionesPlame();

  useEffect(() => {
    if (!open) {
      form.resetFields();
      setTipoSeleccionado(null);
      setEditandoId(null);
    }
  }, [open, form]);

  const handleCambioConcepto = (value) => {
    setTipoSeleccionado(opcionesDisponibles.find((c) => c.codigo === value)?.tipo);
    form.setFieldValue('concepto_definicion_id', undefined);
    if (CONCEPTOS_CON_DEFINICION.includes(value)) {
      const concepto = catalogo.find((c) => c.codigo === value);
      if (concepto) fetchDefiniciones(concepto.id, concepto.codigo);
    }
  };

  const handleEditar = (item) => {
    setEditandoId(item.id);
    setTipoSeleccionado(item.tipo);
    form.setFieldsValue({
      codigo: item.codigo,
      concepto_definicion_id: item.concepto_definicion_id ?? undefined,
      monto: Number(item.monto),
      motivo: item.motivo ?? undefined,
    });
    if (CONCEPTOS_CON_DEFINICION.includes(item.codigo)) {
      const concepto = catalogo.find((c) => c.codigo === item.codigo);
      if (concepto) fetchDefiniciones(concepto.id, concepto.codigo);
    }
  };

  const handleCancelarEdicion = () => {
    setEditandoId(null);
    form.resetFields();
    setTipoSeleccionado(null);
  };

  const handleOk = async () => {
    const values = await form.validateFields();
    const concepto = catalogo.find((c) => c.codigo === values.codigo);
    const payload = {
      concepto_id: concepto.id,
      concepto_definicion_id: values.concepto_definicion_id,
      monto: values.monto,
      motivo: values.motivo,
    };
    if (editandoId) {
      await onUpdate(colaborador.id, editandoId, payload);
      setEditandoId(null);
    } else {
      await onSubmit(colaborador.id, payload);
    }
    form.resetFields();
    setTipoSeleccionado(null);
  };

  const opcionesDisponibles = CONCEPTOS_REGISTRABLES
    .filter((c) => catalogo.some((k) => k.codigo === c.codigo))
    .filter((c) => !esHonorarios || c.tipo === 'egreso');
  const codigoSeleccionado = Form.useWatch('codigo', form);
  const esOtroConceptoPlame = codigoSeleccionado === 'BONO_NO_REMUNERATIVO';

  return (
    <Modal
      title={`Registrar concepto — ${colaborador?.nombre_completo ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      confirmLoading={loading}
      okText={editandoId ? 'Actualizar' : 'Registrar'}
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
          <Input.TextArea rows={2} maxLength={255} placeholder="Ej. Comisión ventas julio, adelanto solicitado el 05/08..." />
        </Form.Item>
        {tipoSeleccionado === 'egreso' && (
          <p className="text-xs text-gray-400">Este concepto se descontará del neto a pagar (no se suma a los ingresos).</p>
        )}
        {editandoId && (
          <p className="text-xs text-blue-500">
            Editando un concepto ya registrado. <a onClick={handleCancelarEdicion}>Cancelar edición</a>
          </p>
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
          {
            title: 'Acciones',
            key: 'acciones',
            width: 90,
            render: (_, item) => (
              <div className="flex items-center gap-1">
                <Tooltip title="Editar">
                  <Button size="small" type="text" icon={<EditOutlined />} onClick={() => handleEditar(item)} />
                </Tooltip>
                <Tooltip title="Eliminar">
                  <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => onDelete(item)} />
                </Tooltip>
              </div>
            ),
          },
        ]}
      />
    </Modal>
  );
}
