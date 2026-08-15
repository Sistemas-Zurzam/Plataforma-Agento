import { MoreOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Avatar, Button, Dropdown, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import EditarUsuarioModal from '../components/EditarUsuarioModal';
import NuevoUsuarioModal from '../components/NuevoUsuarioModal';
import RoleCard from '../components/RoleCard';
import VerUsuarioModal from '../components/VerUsuarioModal';
import { useEmpresas } from '../hooks/useEmpresas';
import { useRoles } from '../hooks/useRoles';
import { useUsuarios } from '../hooks/useUsuarios';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

export default function UsuariosRoles({ user }) {
  const { roles, fetchRoles } = useRoles();
  const { empresas, fetchEmpresas } = useEmpresas();
  const {
    usuarios,
    loading,
    pagination,
    fetchUsuarios,
    crearUsuario,
    actualizarUsuario,
    cambiarRol,
    eliminarUsuario,
  } = useUsuarios();
  const { message, modal } = App.useApp();
  const [modalOpen, setModalOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [viewingUsuario, setViewingUsuario] = useState(null);
  const [editingUsuario, setEditingUsuario] = useState(null);
  const [editSubmitting, setEditSubmitting] = useState(false);
  const puedeCrear = user?.permisos?.includes('usuarios.crear');
  const puedeEditar = user?.permisos?.includes('usuarios.editar');
  const puedeEliminar = user?.permisos?.includes('usuarios.eliminar');

  useEffect(() => {
    fetchRoles();
    fetchUsuarios(1, 10);
    fetchEmpresas();
  }, [fetchRoles, fetchUsuarios, fetchEmpresas]);

  const handleCrear = async (values, form) => {
    setCreating(true);
    try {
      await crearUsuario(values);
      setModalOpen(false);
      fetchRoles();
      fetchUsuarios(pagination.current, pagination.pageSize);
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

  const handleEditar = async (values, form) => {
    const { role_id: nuevoRoleId, ...datosBasicos } = values;
    const cambioDeRol = nuevoRoleId && nuevoRoleId !== editingUsuario.role?.id;

    setEditSubmitting(true);
    try {
      await actualizarUsuario(editingUsuario.id, datosBasicos);

      if (cambioDeRol) {
        try {
          await cambiarRol(editingUsuario.id, nuevoRoleId);
        } catch (err) {
          message.error(
            err.response?.data?.errors?.rol?.[0] ??
              err.response?.data?.message ??
              'Los datos se guardaron, pero no se pudo cambiar el rol',
          );
          setEditingUsuario(null);
          fetchRoles();
          fetchUsuarios(pagination.current, pagination.pageSize);
          return;
        }
      }

      setEditingUsuario(null);
      fetchRoles();
      fetchUsuarios(pagination.current, pagination.pageSize);
      message.success('Usuario actualizado correctamente');
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
        message.error('No se pudo actualizar el usuario');
      }
    } finally {
      setEditSubmitting(false);
    }
  };

  const handleEliminar = (usuario) => {
    modal.confirm({
      title: '¿Eliminar a este usuario de la empresa?',
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await eliminarUsuario(usuario.id);
          message.success('Usuario eliminado de la empresa');
          fetchRoles();
          fetchUsuarios(pagination.current, pagination.pageSize);
        } catch (err) {
          message.error(
            err.response?.data?.message ?? 'No se pudo eliminar el usuario',
          );
        }
      },
    });
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
          <span className="text-sm text-gray-700">{usuario.role?.nombre}</span>
        ),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 64,
      render: (_, usuario) => {
        if (usuario.es_actual) return null;

        const items = [{ key: 'ver', label: 'Ver' }];
        if (puedeEditar) items.push({ key: 'editar', label: 'Editar' });
        if (puedeEliminar) items.push({ key: 'eliminar', label: 'Eliminar', danger: true });

        return (
          <Dropdown
            trigger={['click']}
            menu={{
              items,
              onClick: ({ key }) => {
                if (key === 'ver') setViewingUsuario(usuario);
                if (key === 'editar') setEditingUsuario(usuario);
                if (key === 'eliminar') handleEliminar(usuario);
              },
            }}
          >
            <Button type="text" icon={<MoreOutlined />} aria-label="Acciones" />
          </Dropdown>
        );
      },
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
        {puedeCrear && (
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
        )}
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
        pagination={{
          current: pagination.current,
          pageSize: pagination.pageSize,
          total: pagination.total,
          pageSizeOptions: [10, 15],
          showSizeChanger: true,
        }}
        onChange={(paginationConfig) =>
          fetchUsuarios(paginationConfig.current, paginationConfig.pageSize)
        }
      />

      <NuevoUsuarioModal
        open={modalOpen}
        roles={roles}
        empresas={empresas}
        onSubmit={handleCrear}
        onCancel={() => setModalOpen(false)}
        submitting={creating}
        user={user}
      />

      <VerUsuarioModal
        open={!!viewingUsuario}
        usuario={viewingUsuario}
        onCancel={() => setViewingUsuario(null)}
      />

      <EditarUsuarioModal
        open={!!editingUsuario}
        usuario={editingUsuario}
        roles={roles}
        onSubmit={handleEditar}
        onCancel={() => setEditingUsuario(null)}
        submitting={editSubmitting}
        user={user}
      />
    </div>
  );
}
