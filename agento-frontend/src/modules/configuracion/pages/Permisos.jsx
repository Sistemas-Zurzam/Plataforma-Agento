import { MoreOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Button, Checkbox, Collapse, Dropdown, Empty, Input, Modal, Spin, Tag } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { usePermisos } from '../hooks/usePermisos';
import { useRoles } from '../hooks/useRoles';

const ADMIN_CLAVE = 'administrador';

export default function Permisos() {
  const { roles, grupos, loading, fetchPermisos, guardarCambios } = usePermisos();
  const { crearRol, actualizarRol, eliminarRol } = useRoles();
  const { message, modal } = App.useApp();

  const [asignaciones, setAsignaciones] = useState({});
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [crearRolOpen, setCrearRolOpen] = useState(false);
  const [nombreNuevoRol, setNombreNuevoRol] = useState('');
  const [creandoRol, setCreandoRol] = useState(false);
  const [renombrando, setRenombrando] = useState(null);
  const [nombreRenombrar, setNombreRenombrar] = useState('');

  useEffect(() => {
    fetchPermisos();
  }, [fetchPermisos]);

  useEffect(() => {
    const inicial = {};
    roles.forEach((rol) => {
      inicial[rol.id] = new Set(rol.permission_ids ?? []);
    });
    setAsignaciones(inicial);
    setDirty(false);
  }, [roles]);

  const togglePermiso = (roleId, permisoId, rolClave) => {
    if (rolClave === ADMIN_CLAVE) return;
    setAsignaciones((prev) => {
      const siguiente = { ...prev, [roleId]: new Set(prev[roleId]) };
      if (siguiente[roleId].has(permisoId)) {
        siguiente[roleId].delete(permisoId);
      } else {
        siguiente[roleId].add(permisoId);
      }
      return siguiente;
    });
    setDirty(true);
  };

  const handleGuardar = async () => {
    setSaving(true);
    try {
      const payload = Object.fromEntries(
        Object.entries(asignaciones).map(([roleId, set]) => [roleId, Array.from(set)]),
      );
      await guardarCambios(payload);
      message.success('Permisos actualizados correctamente');
      setDirty(false);
    } catch {
      message.error('No se pudieron guardar los cambios');
    } finally {
      setSaving(false);
    }
  };

  const handleCrearRol = async () => {
    if (!nombreNuevoRol.trim()) return;
    setCreandoRol(true);
    try {
      await crearRol(nombreNuevoRol.trim());
      setCrearRolOpen(false);
      setNombreNuevoRol('');
      fetchPermisos();
      message.success('Rol creado correctamente');
    } catch (err) {
      message.error(err.response?.data?.errors?.nombre?.[0] ?? 'No se pudo crear el rol');
    } finally {
      setCreandoRol(false);
    }
  };

  const handleRenombrar = async () => {
    if (!nombreRenombrar.trim() || !renombrando) return;
    try {
      await actualizarRol(renombrando.id, nombreRenombrar.trim());
      setRenombrando(null);
      fetchPermisos();
      message.success('Rol renombrado correctamente');
    } catch (err) {
      message.error(err.response?.data?.errors?.nombre?.[0] ?? 'No se pudo renombrar el rol');
    }
  };

  const handleEliminarRol = (rol) => {
    modal.confirm({
      title: `¿Eliminar el rol "${rol.nombre}"?`,
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await eliminarRol(rol.id);
          fetchPermisos();
          message.success('Rol eliminado');
        } catch (err) {
          message.error(err.response?.data?.errors?.rol?.[0] ?? 'No se pudo eliminar el rol');
        }
      },
    });
  };

  const gruposFiltrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    if (!texto) return grupos;

    const resultado = {};
    Object.entries(grupos).forEach(([nombreGrupo, permisos]) => {
      const filtrados = permisos.filter((permiso) =>
        permiso.nombre.toLowerCase().includes(texto),
      );
      if (filtrados.length > 0) resultado[nombreGrupo] = filtrados;
    });
    return resultado;
  }, [grupos, busqueda]);

  const gridTemplate = `minmax(220px, 2fr) repeat(${roles.length}, minmax(140px, 1fr))`;

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Permisos</h2>
          <p className="text-sm text-gray-500">
            Define qué puede hacer cada rol, sin tocar código.
          </p>
        </div>
        <div className="flex gap-2">
          <Button icon={<PlusOutlined />} onClick={() => setCrearRolOpen(true)}>
            Nuevo rol
          </Button>
          <Button
            type="primary"
            disabled={!dirty}
            loading={saving}
            onClick={handleGuardar}
            style={
              dirty
                ? { background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)', border: 'none' }
                : undefined
            }
          >
            Guardar cambios
          </Button>
        </div>
      </div>

      <Input
        placeholder="Buscar un permiso..."
        value={busqueda}
        onChange={(e) => setBusqueda(e.target.value)}
        className="mb-4 max-w-xs"
        allowClear
      />

      <Spin spinning={loading}>
        <div className="overflow-x-auto rounded-2xl border border-gray-100">
          <div
            className="grid items-center gap-x-4 border-b border-gray-100 bg-gray-50 px-4 py-3"
            style={{ gridTemplateColumns: gridTemplate }}
          >
            <span className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
              Permiso
            </span>
            {roles.map((rol) => (
              <div key={rol.id} className="flex items-center justify-between gap-1">
                <span className="truncate text-xs font-semibold tracking-wide text-gray-600 uppercase">
                  {rol.nombre}
                </span>
                {rol.clave !== ADMIN_CLAVE && (
                  <Dropdown
                    trigger={['click']}
                    menu={{
                      items: [
                        { key: 'renombrar', label: 'Renombrar' },
                        { key: 'eliminar', label: 'Eliminar', danger: true },
                      ],
                      onClick: ({ key }) => {
                        if (key === 'renombrar') {
                          setRenombrando(rol);
                          setNombreRenombrar(rol.nombre);
                        }
                        if (key === 'eliminar') handleEliminarRol(rol);
                      },
                    }}
                  >
                    <Button
                      type="text"
                      size="small"
                      icon={<MoreOutlined />}
                      aria-label="Acciones del rol"
                    />
                  </Dropdown>
                )}
              </div>
            ))}
          </div>

          {Object.keys(gruposFiltrados).length === 0 ? (
            <div className="p-6">
              <Empty description="Sin resultados" />
            </div>
          ) : (
            <Collapse
              key={Object.keys(grupos).join('|')}
              bordered={false}
              defaultActiveKey={Object.keys(grupos)}
              items={Object.entries(gruposFiltrados).map(([nombreGrupo, permisos]) => ({
                key: nombreGrupo,
                label: (
                  <div className="flex items-center gap-2">
                    <span className="font-medium text-gray-900">{nombreGrupo}</span>
                    <Tag>{permisos.length}</Tag>
                  </div>
                ),
                children: (
                  <div>
                    {permisos.map((permiso) => (
                      <div
                        key={permiso.id}
                        className="grid items-center gap-x-4 border-t border-gray-50 px-4 py-2 first:border-t-0"
                        style={{ gridTemplateColumns: gridTemplate }}
                      >
                        <span className="text-sm text-gray-700">{permiso.nombre}</span>
                        {roles.map((rol) => (
                          <div key={rol.id}>
                            <Checkbox
                              checked={
                                rol.clave === ADMIN_CLAVE
                                  ? true
                                  : (asignaciones[rol.id]?.has(permiso.id) ?? false)
                              }
                              disabled={rol.clave === ADMIN_CLAVE}
                              onChange={() => togglePermiso(rol.id, permiso.id, rol.clave)}
                            />
                          </div>
                        ))}
                      </div>
                    ))}
                  </div>
                ),
              }))}
            />
          )}
        </div>
      </Spin>

      <Modal
        title="Nuevo rol"
        open={crearRolOpen}
        onCancel={() => setCrearRolOpen(false)}
        onOk={handleCrearRol}
        confirmLoading={creandoRol}
        okText="Crear"
        cancelText="Cancelar"
        centered
        destroyOnHidden
      >
        <Input
          placeholder="Ej: Contador"
          value={nombreNuevoRol}
          onChange={(e) => setNombreNuevoRol(e.target.value)}
          onPressEnter={handleCrearRol}
        />
      </Modal>

      <Modal
        title="Renombrar rol"
        open={!!renombrando}
        onCancel={() => setRenombrando(null)}
        onOk={handleRenombrar}
        okText="Guardar"
        cancelText="Cancelar"
        centered
        destroyOnHidden
      >
        <Input
          placeholder="Nombre del rol"
          value={nombreRenombrar}
          onChange={(e) => setNombreRenombrar(e.target.value)}
          onPressEnter={handleRenombrar}
        />
      </Modal>
    </div>
  );
}
