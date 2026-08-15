import {
  ArrowLeftOutlined,
  CalendarOutlined,
  ClockCircleOutlined,
  CopyOutlined,
  EditOutlined,
  PauseCircleOutlined,
  PlayCircleOutlined,
  PlusOutlined,
} from '@ant-design/icons';
import { App, Button, Input, Select, Table, Tabs, Tag, Tooltip } from 'antd';
import { useEffect, useState } from 'react';
import EmpresaActivaFiltro from '../../configuracion/components/EmpresaActivaFiltro';
import HorarioFormModal from '../components/HorarioFormModal';
import { useHorarios } from '../hooks/useHorarios';

function TarjetaStat({ icono, valor, etiqueta, color }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
      <span className={`flex h-10 w-10 items-center justify-center rounded-lg text-lg ${color}`}>
        {icono}
      </span>
      <div>
        <p className="text-lg font-semibold text-gray-900">{valor}</p>
        <p className="text-xs text-gray-500">{etiqueta}</p>
      </div>
    </div>
  );
}

function TablaHorarios({ user, onUserRefresh }) {
  const {
    horarios,
    stats,
    loading,
    pagination,
    fetchHorarios,
    crearHorario,
    actualizarHorario,
    duplicarHorario,
    cambiarEstado,
  } = useHorarios();
  const { message } = App.useApp();
  const [busqueda, setBusqueda] = useState('');
  const [estadoFiltro, setEstadoFiltro] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [editando, setEditando] = useState(null);
  const [guardando, setGuardando] = useState(false);

  const puedeCrear = user?.permisos?.includes('horarios.crear');
  const puedeEditar = user?.permisos?.includes('horarios.editar');

  useEffect(() => {
    fetchHorarios(1, pagination.pageSize, busqueda, estadoFiltro);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busqueda, estadoFiltro, user?.empresa?.id]);

  const handleGuardar = async (values) => {
    setGuardando(true);
    try {
      if (editando) {
        await actualizarHorario(editando.id, values);
        message.success('Horario actualizado correctamente');
      } else {
        await crearHorario(values);
        message.success('Horario creado correctamente');
      }
      setModalOpen(false);
      setEditando(null);
      fetchHorarios(pagination.current, pagination.pageSize, busqueda, estadoFiltro);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo guardar el horario');
    } finally {
      setGuardando(false);
    }
  };

  const handleDuplicar = async (horario) => {
    try {
      await duplicarHorario(horario.id);
      message.success('Horario duplicado correctamente');
      fetchHorarios(pagination.current, pagination.pageSize, busqueda, estadoFiltro);
    } catch {
      message.error('No se pudo duplicar el horario');
    }
  };

  const handleCambiarEstado = async (horario) => {
    try {
      await cambiarEstado(horario.id);
      message.success(horario.activo ? 'Horario deshabilitado' : 'Horario habilitado');
      fetchHorarios(pagination.current, pagination.pageSize, busqueda, estadoFiltro);
    } catch {
      message.error('No se pudo cambiar el estado del horario');
    }
  };

  const columns = [
    { title: 'Nombre', dataIndex: 'nombre' },
    { title: 'Jornada', dataIndex: 'jornada', render: (v) => v ?? '—' },
    { title: 'Refrigerio', dataIndex: 'refrigerio', render: (v) => v ?? '—' },
    {
      title: 'Horas/día',
      dataIndex: 'horas_dia',
      render: (v) => (v !== null && v !== undefined ? `${v.toFixed(2)} h` : '—'),
    },
    {
      title: 'Tolerancia',
      dataIndex: 'tolerancia_minutos',
      render: (v) => `${v} min`,
    },
    {
      title: 'Estado',
      key: 'estado',
      render: (_, horario) => (
        <div className="flex items-center gap-1">
          <Tag color={horario.activo ? 'green' : 'default'}>
            {horario.activo ? 'Activo' : 'Inactivo'}
          </Tag>
          {horario.config_pendiente && <Tag color="orange">Config. pendiente</Tag>}
        </div>
      ),
    },
    {
      title: 'Trabajadores',
      dataIndex: 'trabajadores',
      render: (v) => (
        <Tooltip title="Disponible cuando se implemente Gestión de personas">{v}</Tooltip>
      ),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 120,
      render: (_, horario) => (
        <div className="flex items-center gap-1">
          {puedeEditar && (
            <Button
              type="text"
              size="small"
              icon={<EditOutlined />}
              aria-label={`Editar ${horario.nombre}`}
              onClick={() => {
                setEditando(horario);
                setModalOpen(true);
              }}
            />
          )}
          {puedeCrear && (
            <Button
              type="text"
              size="small"
              icon={<CopyOutlined />}
              aria-label={`Duplicar ${horario.nombre}`}
              onClick={() => handleDuplicar(horario)}
            />
          )}
          {puedeEditar && (
            <Button
              type="text"
              size="small"
              icon={horario.activo ? <PauseCircleOutlined /> : <PlayCircleOutlined />}
              aria-label={horario.activo ? `Deshabilitar ${horario.nombre}` : `Habilitar ${horario.nombre}`}
              onClick={() => handleCambiarEstado(horario)}
            />
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <TarjetaStat
          icono={<ClockCircleOutlined />}
          valor={stats.total}
          etiqueta="Total horarios"
          color="bg-agento-blue-light text-agento-blue"
        />
        <TarjetaStat
          icono={<PlayCircleOutlined />}
          valor={stats.activos}
          etiqueta="Activos"
          color="bg-green-50 text-green-600"
        />
        <TarjetaStat
          icono={<PauseCircleOutlined />}
          valor={stats.pendientes}
          etiqueta="Config. pendiente"
          color="bg-orange-50 text-orange-600"
        />
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search
          placeholder="Buscar horario..."
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          className="max-w-xs"
          allowClear
        />
        <Select
          value={estadoFiltro || undefined}
          onChange={(v) => setEstadoFiltro(v ?? '')}
          placeholder="Todos los estados"
          allowClear
          className="w-44"
          options={[
            { value: 'activo', label: 'Activos' },
            { value: 'inactivo', label: 'Inactivos' },
          ]}
        />
        <EmpresaActivaFiltro user={user} onUserRefresh={onUserRefresh} />
        {puedeCrear && (
          <Button
            type="primary"
            icon={<PlusOutlined />}
            onClick={() => {
              setEditando(null);
              setModalOpen(true);
            }}
            style={{
              background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
              border: 'none',
            }}
          >
            Nuevo horario
          </Button>
        )}
      </div>

      <Table
        rowKey="id"
        loading={loading}
        dataSource={horarios}
        columns={columns}
        pagination={{
          current: pagination.current,
          pageSize: pagination.pageSize,
          total: pagination.total,
          pageSizeOptions: [10, 15],
          showSizeChanger: true,
        }}
        onChange={(paginationConfig) =>
          fetchHorarios(paginationConfig.current, paginationConfig.pageSize, busqueda, estadoFiltro)
        }
      />

      <HorarioFormModal
        open={modalOpen}
        horario={editando}
        onSubmit={handleGuardar}
        onCancel={() => {
          setModalOpen(false);
          setEditando(null);
        }}
        submitting={guardando}
      />
    </div>
  );
}

export default function GestionHorarios({ user, onVolver, onUserRefresh }) {
  return (
    <div>
      <div className="mb-4">
        {onVolver && (
          <button
            type="button"
            onClick={onVolver}
            className="mb-2 flex items-center gap-1 text-sm text-gray-500 hover:text-agento-blue"
          >
            <ArrowLeftOutlined /> Volver
          </button>
        )}
        <h2 className="text-lg font-semibold text-gray-900">Gestión de horarios</h2>
        <p className="text-sm text-gray-500">Empresa activa: {user?.empresa?.nombre}</p>
      </div>

      <Tabs
        items={[
          {
            key: 'horarios',
            label: (
              <span>
                <ClockCircleOutlined /> Horarios
              </span>
            ),
            children: <TablaHorarios user={user} onUserRefresh={onUserRefresh} />,
          },
          {
            key: 'calendario',
            label: (
              <span>
                <CalendarOutlined /> Calendario de colaboradores
              </span>
            ),
            disabled: true,
          },
        ]}
      />
    </div>
  );
}
