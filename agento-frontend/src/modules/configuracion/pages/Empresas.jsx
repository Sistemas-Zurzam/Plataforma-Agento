import { MoreOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Avatar, Button, Dropdown, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import EmpresaFormModal from '../components/EmpresaFormModal';
import { useEmpresas } from '../hooks/useEmpresas';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

export default function Empresas({ user }) {
  const { empresas, loading, fetchEmpresas, createEmpresa, updateEmpresa } = useEmpresas();
  const { message } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [editingEmpresa, setEditingEmpresa] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const puedeCrear = user?.permisos?.includes('empresas.crear');
  const puedeEditar = user?.permisos?.includes('empresas.editar');

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

  const columns = [
    {
      title: 'Empresa',
      key: 'empresa',
      render: (_, empresa) => (
        <div className="flex items-center gap-2">
          <Avatar style={{ backgroundColor: empresa.color ?? colorForName(empresa.nombre) }}>
            {initialsForName(empresa.nombre)}
          </Avatar>
          <div>
            <p className="font-medium text-gray-900">{empresa.nombre}</p>
            {empresa.grupo && <p className="text-xs text-gray-500">{empresa.grupo}</p>}
          </div>
        </div>
      ),
    },
    {
      title: 'RUC',
      dataIndex: 'ruc',
      render: (ruc) => ruc ?? '—',
    },
    {
      title: 'Dirección',
      dataIndex: 'direccion',
      render: (direccion) => direccion ?? '—',
    },
    {
      title: 'Régimen Laboral',
      dataIndex: 'regimen_laboral',
      render: (regimen) => regimen ?? '—',
    },
    {
      title: 'Estado',
      key: 'estado',
      render: (_, empresa) => (
        <Tag color={empresa.activa ? 'green' : 'default'}>
          {empresa.activa ? 'Activa' : 'Inactiva'}
        </Tag>
      ),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 64,
      render: (_, empresa) =>
        puedeEditar && (
          <Dropdown
            trigger={['click']}
            menu={{
              items: [{ key: 'editar', label: 'Editar' }],
              onClick: ({ key }) => {
                if (key === 'editar') openEditModal(empresa);
              },
            }}
          >
            <Button type="text" icon={<MoreOutlined />} aria-label="Acciones" />
          </Dropdown>
        ),
    },
  ];

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">
            Empresas del Grupo
          </h2>
          <p className="text-sm text-gray-500">Gestiona las empresas de Agento</p>
        </div>
        {puedeCrear && (
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
        )}
      </div>

      <Table
        rowKey="id"
        loading={loading}
        dataSource={empresas}
        columns={columns}
        pagination={{ pageSize: 10, pageSizeOptions: [10, 15], showSizeChanger: true }}
      />

      <EmpresaFormModal
        open={modalOpen}
        title={editingEmpresa ? 'Editar empresa' : 'Nueva empresa'}
        initialValues={editingEmpresa}
        onSubmit={handleSubmit}
        onCancel={() => setModalOpen(false)}
        submitting={submitting}
        user={user}
      />
    </div>
  );
}
