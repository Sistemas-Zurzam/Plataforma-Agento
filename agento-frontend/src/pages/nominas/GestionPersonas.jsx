import {
  ClockCircleOutlined,
  EyeOutlined,
  RightOutlined,
  TeamOutlined,
  UploadOutlined,
  UserAddOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { App, Avatar, Button, Input, Table } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import GestionHorarios from '../../modules/asistencia/pages/GestionHorarios';
import EmpresaActivaFiltro from '../../modules/configuracion/components/EmpresaActivaFiltro';
import { TIPO_CONTRATO_OPTIONS } from '../../modules/personas/constants/opciones';
import NuevoColaboradorModal from '../../modules/personas/components/NuevoColaboradorModal';
import VerColaboradorModal from '../../modules/personas/components/VerColaboradorModal';
import FichaColaborador from '../../modules/personas/components/FichaColaborador';
import { useColaboradores } from '../../modules/personas/hooks/useColaboradores';
import { colorForName, initialsForName } from '../../utils/avatarColor';

function etiquetaTipoContrato(valor) {
  return TIPO_CONTRATO_OPTIONS.find((o) => o.value === valor)?.label ?? valor;
}

function ListaColaboradores({ user, onUserRefresh, onVerHorarios, colaboradorId, onAbrirColaborador, onVolverColaboradores }) {
  const { colaboradores, stats, loading, pagination, fetchColaboradores, crearColaborador } =
    useColaboradores();
  const { message } = App.useApp();
  const [busqueda, setBusqueda] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [creando, setCreando] = useState(false);
  const [colaboradorSeleccionado, setColaboradorSeleccionado] = useState(null);
  const [verColaboradorId, setVerColaboradorId] = useState(null);

  const puedeVer = user?.permisos?.includes('colaboradores.ver');
  const puedeCrear = user?.permisos?.includes('colaboradores.crear');
  const puedeVerHorarios = user?.permisos?.includes('horarios.ver');

  useEffect(() => {
    if (puedeVer) {
      fetchColaboradores(1, pagination.pageSize, busqueda);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busqueda, user?.empresa?.id, puedeVer]);

  const handleCrear = async (values) => {
    setCreando(true);
    try {
      await crearColaborador(values);
      setModalOpen(false);
      message.success('Colaborador creado correctamente');
      fetchColaboradores(pagination.current, pagination.pageSize, busqueda);
    } catch (err) {
      const fieldErrors = err.response?.data?.errors;
      if (fieldErrors) {
        message.error(Object.values(fieldErrors)[0]?.[0] ?? 'No se pudo crear el colaborador');
      } else {
        message.error('No se pudo crear el colaborador');
      }
    } finally {
      setCreando(false);
    }
  };

  const colaboradorActivo = colaboradorId
    ? { id: colaboradorId }
    : colaboradorSeleccionado;

  const abrirColaborador = (colaborador) => {
    if (onAbrirColaborador) {
      onAbrirColaborador(colaborador.id);
    } else {
      setColaboradorSeleccionado(colaborador);
    }
  };

  if (colaboradorActivo) {
    return (
      <FichaColaborador
        colaboradorId={colaboradorActivo.id}
        onVolver={() => {
          setColaboradorSeleccionado(null);
          onVolverColaboradores?.();
          fetchColaboradores(pagination.current, pagination.pageSize, busqueda);
        }}
      />
    );
  }

  const columns = [
    {
      title: 'Empleado',
      key: 'colaborador',
      width: 240,
      render: (_, colaborador) => (
        <div className="flex items-center gap-3">
          <Avatar style={{ backgroundColor: colorForName(colaborador.nombre_completo) }}>
            {initialsForName(colaborador.nombre_completo)}
          </Avatar>
          <div className="min-w-0">
            <p className="truncate font-semibold text-gray-900">{colaborador.nombre_completo}</p>
            <p className="truncate text-xs text-gray-400">{colaborador.cargo ?? '—'}</p>
          </div>
        </div>
      ),
    },
    {
      title: 'Legajo',
      dataIndex: 'legajo',
      width: 100,
      render: (legajo) => (
        <span className="font-mono text-xs font-semibold text-agento-blue-bright">{legajo}</span>
      ),
    },
    {
      title: 'Empresa',
      key: 'empresa',
      width: 190,
      render: (_, colaborador) => (
        <span className="font-semibold text-gray-900">
          {colaborador.empresa?.nombre ?? user?.empresa?.nombre ?? '—'}
        </span>
      ),
    },
    { title: 'Área', key: 'area', width: 140, render: (_, c) => c.area?.nombre ?? '—' },
    {
      title: 'Contrato',
      dataIndex: 'tipo_contrato',
      width: 160,
      render: (v) => etiquetaTipoContrato(v),
    },
    {
      title: 'Estado',
      dataIndex: 'activo',
      width: 100,
      render: (activo) => (
        <span
          className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
            activo ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'
          }`}
        >
          {activo ? 'Activo' : 'Inactivo'}
        </span>
      ),
    },
    {
      title: 'Ingreso',
      dataIndex: 'fecha_ingreso',
      width: 110,
      render: (fecha) => (fecha ? dayjs(fecha).format('DD/MM/YYYY') : '—'),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 110,
      fixed: 'right',
      render: (_, colaborador) => (
        <div className="flex items-center">
          <Button
            type="text"
            size="small"
            icon={<EyeOutlined />}
            aria-label={`Ver a ${colaborador.nombre_completo}`}
            onClick={() => setVerColaboradorId(colaborador.id)}
          />
          <Button
            type="text"
            size="small"
            icon={<RightOutlined />}
            aria-label={`Abrir ficha de ${colaborador.nombre_completo}`}
            onClick={() => abrirColaborador(colaborador)}
          />
        </div>
      ),
    },
  ];

  return (
    <div>
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-gray-900">Gestión de personas</h2>
        <p className="text-sm text-gray-500">Colaboradores de {user?.empresa?.nombre}</p>
      </div>

      <div className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-agento-blue-light text-lg text-agento-blue">
            <TeamOutlined />
          </span>
          <div>
            <p className="text-lg font-semibold text-gray-900">{stats.total}</p>
            <p className="text-xs text-gray-500">Total Colaboradores</p>
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-green-50 text-lg text-green-600">
            <UserOutlined />
          </span>
          <div>
            <p className="text-lg font-semibold text-gray-900">{stats.activos}</p>
            <p className="text-xs text-gray-500">Activos</p>
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm opacity-50">
          <div>
            <p className="text-lg font-semibold text-gray-900">0</p>
            <p className="text-xs text-gray-500">Vacaciones (próximamente)</p>
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm opacity-50">
          <div>
            <p className="text-lg font-semibold text-gray-900">0</p>
            <p className="text-xs text-gray-500">Licencia (próximamente)</p>
          </div>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search
          placeholder="Buscar colaborador..."
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          className="max-w-xs"
          allowClear
        />
        <EmpresaActivaFiltro user={user} onUserRefresh={onUserRefresh} />
        {puedeVerHorarios && (
          <Button icon={<ClockCircleOutlined />} onClick={onVerHorarios}>
            Gestión de horarios
          </Button>
        )}
        <Button icon={<UploadOutlined />} disabled>
          Importar
        </Button>
        {puedeCrear && (
          <Button
            type="primary"
            icon={<UserAddOutlined />}
            onClick={() => setModalOpen(true)}
            style={{
              background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
              border: 'none',
            }}
          >
            Nuevo Colaborador
          </Button>
        )}
      </div>

      {puedeVer && (
        <Table
          rowKey="id"
          loading={loading}
          dataSource={colaboradores}
          columns={columns}
          scroll={{ x: 1130 }}
          pagination={{
            current: pagination.current,
            pageSize: pagination.pageSize,
            total: pagination.total,
            pageSizeOptions: [10, 15],
            showSizeChanger: true,
          }}
          onChange={(paginationConfig) =>
            fetchColaboradores(paginationConfig.current, paginationConfig.pageSize, busqueda)
          }
        />
      )}

      <NuevoColaboradorModal
        open={modalOpen}
        user={user}
        onSubmit={handleCrear}
        onCancel={() => setModalOpen(false)}
        submitting={creando}
      />

      <VerColaboradorModal
        open={Boolean(verColaboradorId)}
        colaboradorId={verColaboradorId}
        onClose={() => setVerColaboradorId(null)}
      />
    </div>
  );
}

/**
 * "Gestión de horarios" no tiene su propia entrada de sidebar a propósito
 * (ver Sidebar.jsx) — es un botón dentro de Gestión de personas.
 */
export default function GestionPersonas({ user, onUserRefresh, colaboradorId, onAbrirColaborador, onVolverColaboradores }) {
  const [vista, setVista] = useState('personas');

  if (vista === 'horarios') {
    return (
      <GestionHorarios
        user={user}
        onUserRefresh={onUserRefresh}
        onVolver={() => setVista('personas')}
      />
    );
  }

  return (
    <ListaColaboradores
      user={user}
      onUserRefresh={onUserRefresh}
      onVerHorarios={() => setVista('horarios')}
      colaboradorId={colaboradorId}
      onAbrirColaborador={onAbrirColaborador}
      onVolverColaboradores={onVolverColaboradores}
    />
  );
}
