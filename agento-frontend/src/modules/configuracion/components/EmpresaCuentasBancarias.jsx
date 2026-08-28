import { EditOutlined, PlusOutlined, StarFilled, StarOutlined } from '@ant-design/icons';
import { App, Button, Form, Input, Modal, Select, Switch, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { useBancos } from '../hooks/useBancos';
import { useCuentasBancariasEmpresa } from '../hooks/useCuentasBancariasEmpresa';

const TIPO_CUENTA_OPTIONS = [
  { value: 'corriente', label: 'Cuenta Corriente' },
  { value: 'maestra', label: 'Cuenta Maestra' },
];

const MONEDA_OPTIONS = [
  { value: 'PEN', label: 'Soles (PEN)' },
  { value: 'USD', label: 'Dólares (USD)' },
];

const USO_OPTIONS = [
  { value: 'haberes', label: 'Pago de haberes' },
];

/**
 * Cuentas bancarias de la empresa — usadas como cuenta de cargo para
 * Telecrédito BCP (y futuras integraciones bancarias). El número de
 * cuenta NUNCA se muestra completo (siempre enmascarado por el backend) y
 * NUNCA es editable una vez creada — si está mal, se desactiva y se
 * agrega una nueva (mismo criterio que la mayoría de sistemas bancarios).
 */
export default function EmpresaCuentasBancarias({ empresaId, empresaNombre, user }) {
  const { cuentas, fetchCuentas, crearCuenta, actualizarCuenta, actualizarEstadoCuenta } = useCuentasBancariasEmpresa(empresaId);
  const { bancos, fetchBancos } = useBancos();
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [modalOpen, setModalOpen] = useState(false);
  const [cuentaEnEdicion, setCuentaEnEdicion] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const puedeEditar = user?.permisos?.includes('empresas.editar');

  useEffect(() => {
    fetchCuentas();
    fetchBancos();
  }, [fetchCuentas, fetchBancos]);

  const abrirNueva = () => {
    setCuentaEnEdicion(null);
    form.resetFields();
    setModalOpen(true);
  };

  const abrirEdicion = (cuenta) => {
    setCuentaEnEdicion(cuenta);
    form.setFieldsValue({
      tipo_cuenta: cuenta.tipo_cuenta,
      moneda: cuenta.moneda,
      uso: cuenta.uso,
      es_predeterminada: cuenta.es_predeterminada,
    });
    setModalOpen(true);
  };

  const handleGuardar = async (values) => {
    setSubmitting(true);
    try {
      if (cuentaEnEdicion) {
        await actualizarCuenta(cuentaEnEdicion.id, values);
        message.success('Cuenta bancaria actualizada');
      } else {
        await crearCuenta(values);
        message.success('Cuenta bancaria agregada');
      }
      setModalOpen(false);
      form.resetFields();
    } catch (err) {
      const fieldErrors = err.response?.data?.errors;
      message.error(fieldErrors ? Object.values(fieldErrors)[0][0] : 'No se pudo guardar la cuenta bancaria');
    } finally {
      setSubmitting(false);
    }
  };

  const handleToggleEstado = async (cuenta) => {
    try {
      await actualizarEstadoCuenta(cuenta.id, !cuenta.activo);
      message.success(cuenta.activo ? 'Cuenta desactivada' : 'Cuenta activada');
    } catch {
      message.error('No se pudo cambiar el estado de la cuenta');
    }
  };

  return (
    <div className="rounded-xl border border-gray-100 p-4">
      <div className="mb-3 flex items-center justify-between">
        <p className="font-medium text-gray-900">Cuentas bancarias — {empresaNombre}</p>
        {puedeEditar && (
          <Button size="small" icon={<PlusOutlined />} onClick={abrirNueva}>
            Agregar cuenta
          </Button>
        )}
      </div>

      <div className="flex flex-col gap-2">
        {cuentas.map((cuenta) => (
          <div key={cuenta.id} className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2">
            <div className="flex items-center gap-2">
              {cuenta.es_predeterminada ? <StarFilled className="text-amber-400" /> : <StarOutlined className="text-gray-300" />}
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {cuenta.banco?.nombre} · {TIPO_CUENTA_OPTIONS.find((t) => t.value === cuenta.tipo_cuenta)?.label} · {cuenta.moneda}
                </p>
                <p className="text-xs text-gray-500">
                  {cuenta.numero_cuenta_enmascarado} — {USO_OPTIONS.find((u) => u.value === cuenta.uso)?.label ?? cuenta.uso}
                </p>
              </div>
              <Tag color={cuenta.activo ? 'green' : 'default'}>{cuenta.activo ? 'Activa' : 'Inactiva'}</Tag>
            </div>
            {puedeEditar && (
              <div className="flex items-center gap-2">
                <Button type="text" size="small" icon={<EditOutlined />} onClick={() => abrirEdicion(cuenta)} aria-label="Editar cuenta" />
                <Switch size="small" checked={cuenta.activo} onChange={() => handleToggleEstado(cuenta)} />
              </div>
            )}
          </div>
        ))}
        {cuentas.length === 0 && (
          <p className="text-sm text-gray-400 italic">No hay cuentas bancarias configuradas para {empresaNombre}.</p>
        )}
      </div>

      <Modal
        title={cuentaEnEdicion ? 'Editar cuenta bancaria' : 'Nueva cuenta bancaria'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={() => form.submit()}
        confirmLoading={submitting}
        okText="Guardar"
        cancelText="Cancelar"
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={handleGuardar} className="mt-4">
          {!cuentaEnEdicion && (
            <>
              <Form.Item label="Banco" name="banco_id" rules={[{ required: true, message: 'Selecciona un banco' }]}>
                <Select
                  placeholder="Selecciona banco"
                  options={bancos.map((b) => ({ value: b.id, label: b.nombre }))}
                />
              </Form.Item>
              <Form.Item
                label="Número de cuenta"
                name="numero_cuenta"
                rules={[
                  { required: true, message: 'Ingresa el número de cuenta' },
                  { pattern: /^\d+$/, message: 'Solo números, sin guiones' },
                ]}
                extra="Solo números, sin guiones — no editable después de creada."
              >
                <Input maxLength={20} placeholder="Ej: 1910695055056" />
              </Form.Item>
            </>
          )}
          <Form.Item label="Tipo de cuenta" name="tipo_cuenta" rules={[{ required: true, message: 'Selecciona un tipo' }]}>
            <Select options={TIPO_CUENTA_OPTIONS} placeholder="Selecciona un tipo" />
          </Form.Item>
          <Form.Item label="Moneda" name="moneda" rules={[{ required: true, message: 'Selecciona una moneda' }]}>
            <Select options={MONEDA_OPTIONS} placeholder="Selecciona una moneda" />
          </Form.Item>
          <Form.Item label="Uso" name="uso" initialValue="haberes" rules={[{ required: true }]}>
            <Select options={USO_OPTIONS} />
          </Form.Item>
          <Form.Item label="Cuenta predeterminada" name="es_predeterminada" valuePropName="checked">
            <Switch />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
