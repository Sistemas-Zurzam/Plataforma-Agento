import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Button, Form, InputNumber, Modal, Select } from 'antd';
import { useEffect, useState } from 'react';
import { useReglasDescuentoTardanza } from '../hooks/useReglasDescuentoTardanza';

const TIPO_OPTIONS = [
  { value: 'por_minuto', label: 'Por minuto (valor × minuto de sueldo)' },
  { value: 'monto_fijo', label: 'Monto fijo' },
  { value: 'medio_dia', label: 'Medio día de sueldo' },
  { value: 'dia_completo', label: 'Día completo de sueldo' },
];

function etiquetaRango(regla) {
  return `${regla.minutos_desde}–${regla.minutos_hasta ?? '∞'} min`;
}

function etiquetaTipo(regla) {
  const base = TIPO_OPTIONS.find((t) => t.value === regla.tipo)?.label ?? regla.tipo;
  return regla.tipo === 'por_minuto' || regla.tipo === 'monto_fijo' ? `${base} (${regla.valor})` : base;
}

/**
 * Si no se configura ninguna regla acá, el motor de Nóminas sigue usando la
 * fórmula plana de siempre (valor_minuto × minutos) — esto es un
 * refinamiento opcional por empresa, nunca obligatorio.
 */
export default function EmpresaReglasTardanza({ empresaId, empresaNombre, user }) {
  const { reglas, fetchReglas, crearRegla, eliminarRegla } = useReglasDescuentoTardanza(empresaId);
  const { message, modal } = App.useApp();
  const [form] = Form.useForm();
  const [modalOpen, setModalOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const puedeEditar = user?.permisos?.includes('empresas.editar');

  useEffect(() => {
    fetchReglas();
  }, [fetchReglas]);

  const tipoSeleccionado = Form.useWatch('tipo', form);

  const handleCrear = async (values) => {
    setSubmitting(true);
    try {
      await crearRegla(values);
      message.success('Regla agregada correctamente');
      setModalOpen(false);
      form.resetFields();
    } catch (err) {
      const fieldErrors = err.response?.data?.errors;
      message.error(fieldErrors ? Object.values(fieldErrors)[0][0] : 'No se pudo agregar la regla');
    } finally {
      setSubmitting(false);
    }
  };

  const handleEliminar = (regla) => {
    modal.confirm({
      title: 'Eliminar regla',
      content: `¿Eliminar la regla de ${etiquetaRango(regla)}?`,
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await eliminarRegla(regla.id);
          message.success('Regla eliminada');
        } catch {
          message.error('No se pudo eliminar la regla');
        }
      },
    });
  };

  return (
    <div className="rounded-xl border border-gray-100 p-4">
      <div className="mb-3 flex items-center justify-between">
        <p className="font-medium text-gray-900">Configuración de asistencia — descuento por tardanza</p>
        {puedeEditar && (
          <Button size="small" icon={<PlusOutlined />} onClick={() => setModalOpen(true)}>
            Agregar regla
          </Button>
        )}
      </div>

      <div className="flex flex-col gap-2">
        {reglas.map((regla) => (
          <div key={regla.id} className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2">
            <div>
              <p className="text-sm font-medium text-gray-900">{etiquetaRango(regla)}</p>
              <p className="text-xs text-gray-500">{etiquetaTipo(regla)}</p>
            </div>
            {puedeEditar && (
              <Button type="text" size="small" danger icon={<DeleteOutlined />} onClick={() => handleEliminar(regla)} aria-label="Eliminar regla" />
            )}
          </div>
        ))}
        {reglas.length === 0 && (
          <p className="text-sm text-gray-400 italic">No hay reglas de descuento configuradas para {empresaNombre}.</p>
        )}
      </div>

      <Modal
        title="Nueva regla de descuento por tardanza"
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={() => form.submit()}
        confirmLoading={submitting}
        okText="Agregar"
        cancelText="Cancelar"
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={handleCrear} className="mt-4">
          <div className="grid grid-cols-2 gap-x-4">
            <Form.Item label="Desde (minutos)" name="minutos_desde" rules={[{ required: true, message: 'Requerido' }]}>
              <InputNumber min={0} className="w-full" />
            </Form.Item>
            <Form.Item label="Hasta (minutos, opcional)" name="minutos_hasta" extra="Vacío = sin límite superior">
              <InputNumber min={0} className="w-full" />
            </Form.Item>
          </div>
          <Form.Item label="Tipo de descuento" name="tipo" rules={[{ required: true, message: 'Selecciona un tipo' }]}>
            <Select options={TIPO_OPTIONS} placeholder="Selecciona un tipo" />
          </Form.Item>
          {(tipoSeleccionado === 'por_minuto' || tipoSeleccionado === 'monto_fijo') && (
            <Form.Item
              label={tipoSeleccionado === 'por_minuto' ? 'Multiplicador del valor-minuto' : 'Monto fijo (S/)'}
              name="valor"
              rules={[{ required: true, message: 'Requerido para este tipo' }]}
            >
              <InputNumber min={0} step={0.01} className="w-full" />
            </Form.Item>
          )}
        </Form>
      </Modal>
    </div>
  );
}
