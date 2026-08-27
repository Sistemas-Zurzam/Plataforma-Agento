import { MoreOutlined, PlusOutlined, PoweroffOutlined } from '@ant-design/icons';
import { App, Avatar, Button, Dropdown, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import EmpresaFormModal from '../components/EmpresaFormModal';
import { useEmpresas } from '../hooks/useEmpresas';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

export default function Empresas({ user }) {
  const {
    empresas,
    loading,
    fetchEmpresas,
    createEmpresa,
    updateEmpresa,
    updateEmpresaEstado,
    subirLogoEmpresa,
  } = useEmpresas();
  const { message, modal } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [editingEmpresa, setEditingEmpresa] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [togglingId, setTogglingId] = useState(null);
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
      } else if (!err.response) {
        // La petición ni siquiera llegó al servidor (servidor caído, sin
        // conexión, CORS) — no confundir esto con un error de validación.
        message.error('No se pudo conectar con el servidor. Verifica tu conexión o que el backend esté disponible.');
      } else if (err.response.status >= 500) {
        message.error('Ocurrió un error inesperado en el servidor. Intenta de nuevo en unos momentos.');
      } else {
        message.error(err.response?.data?.message || 'No se pudo guardar la empresa');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const applyToggleEstado = async (empresa) => {
    setTogglingId(empresa.id);
    try {
      await updateEmpresaEstado(empresa.id, !empresa.activa);
      message.success(`Empresa ${empresa.activa ? 'inactivada' : 'activada'} correctamente`);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo cambiar el estado de la empresa');
    } finally {
      setTogglingId(null);
    }
  };

  const handleToggleEstado = (empresa) => {
    if (!empresa.activa) {
      applyToggleEstado(empresa);
      return;
    }

    modal.confirm({
      title: '¿Inactivar esta empresa?',
      content:
        'Ya no podrá seleccionarse para registrar nuevas operaciones. La información histórica se conservará.',
      okText: 'Inactivar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => applyToggleEstado(empresa),
    });
  };

  const columns = [
    {
      title: 'Empresa',
      key: 'empresa',
      render: (_, empresa) => (
        <div className="flex items-center gap-2">
          <Avatar src={empresa.logo_url} style={{ backgroundColor: empresa.color ?? colorForName(empresa.nombre_comercial) }}>
            {initialsForName(empresa.nombre_comercial)}
          </Avatar>
          <div>
            <p className="font-medium text-gray-900">{empresa.nombre_comercial}</p>
            {empresa.grupo && <p className="text-xs text-gray-500">{empresa.grupo}</p>}
            {empresa.razon_social && <p className="text-xs text-gray-400">{empresa.razon_social}</p>}
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
      title: 'Abreviatura',
      dataIndex: 'abreviatura',
      render: (abreviatura) => abreviatura ?? '—',
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
              items: [
                { key: 'editar', label: 'Editar' },
                {
                  key: 'estado',
                  icon: <PoweroffOutlined />,
                  label: empresa.activa ? 'Inactivar' : 'Activar',
                  danger: empresa.activa,
                  disabled: togglingId === empresa.id,
                },
              ],
              onClick: ({ key }) => {
                if (key === 'editar') openEditModal(empresa);
                if (key === 'estado') handleToggleEstado(empresa);
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
        scroll={{ x: 900 }}
        pagination={{ pageSize: 10, pageSizeOptions: [10, 15], showSizeChanger: true }}
      />

      <EmpresaFormModal
        open={modalOpen}
        title={editingEmpresa ? 'Editar empresa' : 'Nueva empresa'}
        initialValues={editingEmpresa ? (empresas.find((e) => e.id === editingEmpresa.id) ?? editingEmpresa) : null}
        onSubmit={handleSubmit}
        onCancel={() => setModalOpen(false)}
        onEstadoChange={applyToggleEstado}
        onSubirLogo={subirLogoEmpresa}
        submitting={submitting}
        user={user}
      />
    </div>
  );
}
