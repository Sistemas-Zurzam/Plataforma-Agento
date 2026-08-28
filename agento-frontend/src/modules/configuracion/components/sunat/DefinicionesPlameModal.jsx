import { App, Button, Form, Input, List, Modal, Switch, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { useConceptoDefinicionesPlame } from '../../hooks/useConceptoDefinicionesPlame';

const RANGOS = {
  BONIFICACION: [301, 314],
  BONO_NO_REMUNERATIVO: [1001, 1020],
};

/**
 * BONIFICACION/BONO_NO_REMUNERATIVO son demasiado genéricos para un único
 * código PLAME (Tabla 22) — acá se administran las "definiciones" concretas
 * y reutilizables (ej. "Bono de productividad") que sí tienen un código
 * explícito, elegido por un administrador dentro del rango oficial
 * confirmado. El concepto motor nunca recibe un código por defecto.
 */
export default function DefinicionesPlameModal({ open, concepto, onCancel }) {
  const { message } = App.useApp();
  const { definiciones, loading, fetchDefiniciones, crearDefinicion, actualizarDefinicion } = useConceptoDefinicionesPlame();
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const rango = concepto ? RANGOS[concepto.codigo] : null;

  useEffect(() => {
    if (open && concepto) fetchDefiniciones(concepto.id);
  }, [open, concepto, fetchDefiniciones]);

  const crear = async (values) => {
    setGuardando(true);
    try {
      await crearDefinicion(concepto.id, values);
      message.success('Definición creada');
      form.resetFields();
    } catch (err) {
      message.error(err.response?.data?.errors?.codigo_plame?.[0] ?? err.response?.data?.message ?? 'No se pudo crear la definición');
    } finally {
      setGuardando(false);
    }
  };

  const toggleActivo = async (definicion) => {
    try {
      await actualizarDefinicion(definicion.id, { activo: !definicion.activo });
    } catch {
      message.error('No se pudo actualizar la definición');
    }
  };

  return (
    <Modal
      title={`Definiciones PLAME — ${concepto?.nombre ?? ''}`}
      open={open}
      onCancel={onCancel}
      footer={null}
      destroyOnHidden
      width={560}
    >
      <p className="mb-4 text-sm text-gray-500">
        Cada clasificación concreta de este concepto necesita su propio código PLAME (Tabla 22: {rango ? `${rango[0]}-${rango[1]}` : 'rango no confirmado'}).
        Nunca se asigna uno por defecto.
      </p>

      <List
        loading={loading}
        dataSource={definiciones}
        locale={{ emptyText: 'Todavía no hay definiciones para este concepto' }}
        className="mb-4"
        renderItem={(d) => (
          <List.Item actions={[<Switch key="activo" size="small" checked={d.activo} onChange={() => toggleActivo(d)} />]}>
            <List.Item.Meta
              title={<span>{d.nombre} <code className="ml-1 text-xs">{d.codigo_plame}</code></span>}
              description={d.descripcion_sunat || <span className="text-gray-300">Sin descripción</span>}
            />
            {!d.activo && <Tag>Inactiva</Tag>}
          </List.Item>
        )}
      />

      <Form form={form} layout="inline" onFinish={crear} className="flex flex-wrap gap-2">
        <Form.Item name="nombre" rules={[{ required: true, message: 'Requerido' }]} className="grow">
          <Input placeholder="Ej: Bono de productividad" />
        </Form.Item>
        <Form.Item
          name="codigo_plame"
          rules={[{ required: true, message: 'Requerido' }, { pattern: /^\d{1,4}$/, message: 'Solo dígitos (hasta 4)' }]}
        >
          <Input placeholder="Código" className="w-24" maxLength={4} />
        </Form.Item>
        <Form.Item name="descripcion_sunat" className="grow">
          <Input placeholder="Descripción (opcional)" />
        </Form.Item>
        <Form.Item>
          <Button type="primary" htmlType="submit" loading={guardando}>Agregar</Button>
        </Form.Item>
      </Form>
    </Modal>
  );
}
