import { PlusOutlined } from '@ant-design/icons';
import { App, Button } from 'antd';
import { useEffect, useState } from 'react';
import EmpresaCard from '../../components/EmpresaCard';
import EmpresaFormModal from '../../components/EmpresaFormModal';
import { useEmpresas } from '../../hooks/useEmpresas';

export default function Empresas() {
  const { empresas, loading, fetchEmpresas, createEmpresa, updateEmpresa } =
    useEmpresas();
  const { message } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [editingEmpresa, setEditingEmpresa] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    fetchEmpresas();
  }, [fetchEmpresas]);

  const openCreateModal = () => {
    setEditingEmpresa(null);
    setModalOpen(true);
  };

  const openEditModal = (empresa) => {
    setEditingEmpresa(empresa);
    setModalOpen(true);
  };

  const handleSubmit = async (values, form) => {
    setSubmitting(true);
    try {
      if (editingEmpresa) {
        await updateEmpresa(editingEmpresa.id, values);
        message.success('Empresa actualizada correctamente');
      } else {
        await createEmpresa(values);
        message.success('Empresa creada correctamente');
      }
      setModalOpen(false);
    } catch (err) {
      const fieldErrors = err.response?.data?.errors;
      if (fieldErrors) {
        form.setFields(
          Object.entries(fieldErrors).map(([field, errors]) => ({
            name: field,
            errors,
          })),
        );
      } else {
        message.error('No se pudo guardar la empresa');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">
            Empresas del Grupo
          </h2>
          <p className="text-sm text-gray-500">Gestiona las empresas de Agento</p>
        </div>
        <Button
          type="primary"
          icon={<PlusOutlined />}
          onClick={openCreateModal}
          style={{
            background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
            border: 'none',
          }}
        >
          Nueva Empresa
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        {empresas.map((empresa) => (
          <EmpresaCard key={empresa.id} empresa={empresa} onEdit={openEditModal} />
        ))}
        {!loading && empresas.length === 0 && (
          <p className="text-sm text-gray-500">No tienes empresas todavía.</p>
        )}
      </div>

      <EmpresaFormModal
        open={modalOpen}
        title={editingEmpresa ? 'Editar empresa' : 'Nueva empresa'}
        initialValues={editingEmpresa}
        onSubmit={handleSubmit}
        onCancel={() => setModalOpen(false)}
        submitting={submitting}
      />
    </div>
  );
}
