import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Avatar, Button, Popconfirm, Select, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import NuevoUsuarioModal from '../../components/NuevoUsuarioModal';
import RoleCard from '../../components/RoleCard';
import { useEmpresas } from '../../hooks/useEmpresas';
import { useRoles } from '../../hooks/useRoles';
import { useUsuarios } from '../../hooks/useUsuarios';
import { colorForName, initialsForName } from '../../utils/avatarColor';

export default function UsuariosRoles() {
  const { roles, fetchRoles } = useRoles();
  const { empresas, fetchEmpresas } = useEmpresas();
  const {
    usuarios,
    loading,
    fetchUsuarios,
    crearUsuario,
    cambiarRol,
    eliminarUsuario,
  } = useUsuarios();
  const { message } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [changingRoleId, setChangingRoleId] = useState(null);

  useEffect(() => {
    fetchRoles();
    fetchUsuarios();
    fetchEmpresas();
  }, [fetchRoles, fetchUsuarios, fetchEmpresas]);

  const handleCrear = async (values, form) => {
    setCreating(true);
    try {
      await crearUsuario(values);
      setModalOpen(false);
      fetchRoles();
      fetchUsuarios();
      message.success('Usuario creado correctamente');
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
        message.error('No se pudo crear el usuario');
      }
    } finally {
      setCreating(false);
    }
  };

  const handleCambiarRol = async (usuario, roleId) => {
    setChangingRoleId(usuario.id);
    try {
      await cambiarRol(usuario.id, roleId);
      message.success('Rol actualizado correctamente');
      fetchRoles();
    } catch (err) {
      message.error(
        err.response?.data?.message ?? 'No se pudo cambiar el rol',
      );
    } finally {
      setChangingRoleId(null);
    }
  };

  const handleEliminar = async (usuario) => {
    try {
      await eliminarUsuario(usuario.id);
      message.success('Usuario eliminado de la empresa');
      fetchRoles();
    } catch (err) {
      message.error(
        err.response?.data?.message ?? 'No se pudo eliminar el usuario',
      );
    }
  };

  const columns = [
    {
      title: 'Usuario',
      key: 'usuario',
      render: (_, usuario) => (
        <div className="flex items-center gap-2">
          <Avatar style={{ backgroundColor: colorForName(usuario.name) }}>
            {initialsForName(usuario.name)}
          </Avatar>
          <div>
            <p className="font-medium text-gray-900">{usuario.name}</p>
            {usuario.es_actual && (
              <Tag color="blue" className="mt-0.5">
                Tú
              </Tag>
            )}
          </div>
        </div>
      ),
    },
    { title: 'Email', dataIndex: 'email' },
    {
      title: 'Empresa',
      key: 'empresa',
      render: (_, usuario) => usuario.empresa?.nombre,
    },
    {
      title: 'Rol',
      key: 'rol',
      render: (_, usuario) =>
        usuario.es_actual ? (
          <span className="text-sm text-gray-400 italic">Tu propio rol</span>
        ) : (
          <Select
            value={usuario.role?.id}
            loading={changingRoleId === usuario.id}
            style={{ width: 200 }}
            options={roles.map((role) => ({
              value: role.id,
              label: role.nombre,
            }))}
            onChange={(roleId) => handleCambiarRol(usuario, roleId)}
          />
        ),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      render: (_, usuario) =>
        !usuario.es_actual && (
          <Popconfirm
            title="¿Eliminar a este usuario de la empresa?"
            onConfirm={() => handleEliminar(usuario)}
            okText="Eliminar"
            cancelText="Cancelar"
          >
            <Button type="text" danger icon={<DeleteOutlined />} />
          </Popconfirm>
        ),
    },
  ];

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">
            Usuarios y Roles
          </h2>
          <p className="text-sm text-gray-500">
            Gestiona los permisos de acceso del equipo
          </p>
        </div>
        <Button
          type="primary"
          icon={<PlusOutlined />}
          onClick={() => setModalOpen(true)}
          style={{
            background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
            border: 'none',
          }}
        >
          Nuevo Usuario
        </Button>
      </div>

      <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        {roles.map((role) => (
          <RoleCard key={role.id} role={role} />
        ))}
      </div>

      <Table
        rowKey="id"
        loading={loading}
        dataSource={usuarios}
        columns={columns}
        pagination={false}
      />

      <NuevoUsuarioModal
        open={modalOpen}
        roles={roles}
        empresas={empresas}
        onSubmit={handleCrear}
        onCancel={() => setModalOpen(false)}
        submitting={creating}
      />
    </div>
  );
}
