import {
  ClockCircleOutlined,
  EyeOutlined,
  IdcardOutlined,
  RightOutlined,
  TeamOutlined,
  UndoOutlined,
  UploadOutlined,
  UserAddOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { App, Avatar, Button, Input, Switch, Table } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import GestionHorarios from '../../modules/asistencia/pages/GestionHorarios';
import EmpresaActivaFiltro from '../../modules/configuracion/components/EmpresaActivaFiltro';
import { TIPO_CONTRATO_OPTIONS } from '../../modules/personas/constants/opciones';
import ImportarColaboradoresModal from '../../modules/personas/components/ImportarColaboradoresModal';
import NuevoColaboradorModal from '../../modules/personas/components/NuevoColaboradorModal';
import VerColaboradorModal from '../../modules/personas/components/VerColaboradorModal';
import VerCarnetModal from '../../modules/personas/components/VerCarnetModal';
import FichaColaborador from '../../modules/personas/components/FichaColaborador';
import { useColaboradores } from '../../modules/personas/hooks/useColaboradores';
import { colorForName, initialsForName } from '../../utils/avatarColor';

function etiquetaTipoContrato(valor) {
  return TIPO_CONTRATO_OPTIONS.find((o) => o.value === valor)?.label ?? valor;
}

function ListaColaboradores({ user, onUserRefresh, onVerHorarios, colaboradorId, onAbrirColaborador, onVolverColaboradores }) {
  const { colaboradores, stats, loading, pagination, fetchColaboradores, crearColaborador, restaurarColaborador, subirFotoPerfil } =
    useColaboradores();
  const { message, modal } = App.useApp();
  const [busqueda, setBusqueda] = useState('');
  const [todasEmpresas, setTodasEmpresas] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [creando, setCreando] = useState(false);
  const [colaboradorSeleccionado, setColaboradorSeleccionado] = useState(null);
  const [verColaboradorId, setVerColaboradorId] = useState(null);
  const [carnetColaborador, setCarnetColaborador] = useState(null);
  const [importarModalOpen, setImportarModalOpen] = useState(false);

  const puedeVer = user?.permisos?.includes('colaboradores.ver');
  const puedeCrear = user?.permisos?.includes('colaboradores.crear');
  const puedeVerHorarios = user?.permisos?.includes('horarios.ver');
  const isAdmin = user?.role === 'administrador';
  const [restaurandoId, setRestaurandoId] = useState(null);

  useEffect(() => {
    if (puedeVer) {
      fetchColaboradores(1, pagination.pageSize, busqueda, todasEmpresas);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busqueda, user?.empresa?.id, todasEmpresas, puedeVer]);

  const handleCrear = async (values, fotoPerfil) => {
    setCreando(true);
    try {
      const nuevoColaborador = await crearColaborador(values);

      if (fotoPerfil) {
        try {
          await subirFotoPerfil(nuevoColaborador.id, fotoPerfil);
        } catch {
          // La foto es opcional — no revertimos la creación del colaborador
          // por esto, solo avisamos que hay que subirla de nuevo después.
          message.warning('El colaborador se creó, pero no se pudo subir la foto de perfil. Puedes subirla luego desde su ficha.');
        }
      }

      setModalOpen(false);
      message.success('Colaborador creado correctamente');
      fetchColaboradores(pagination.current, pagination.pageSize, busqueda, todasEmpresas);
    } catch (err) {
      const colaboradorEliminado = err.response?.status === 409 ? err.response.data?.colaborador_eliminado : null;

      if (colaboradorEliminado) {
        modal.confirm({
          title: 'Este colaborador ya existe, pero está eliminado',
          content: `${colaboradorEliminado.nombre_completo} (legajo ${colaboradorEliminado.legajo}) fue eliminado el ${colaboradorEliminado.eliminado_at}. Puedes restaurarlo con todo su historial en vez de crear uno nuevo.`,
          okText: 'Restaurar',
          cancelText: 'Cancelar',
          onOk: async () => {
            try {
              await restaurarColaborador(colaboradorEliminado.id);
              setModalOpen(false);
              message.success('Colaborador restaurado correctamente');
              fetchColaboradores(pagination.current, pagination.pageSize, busqueda, todasEmpresas);
            } catch {
              message.error('No se pudo restaurar el colaborador');
            }
          },
        });
        return;
      }

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

  const handleRestaurar = (colaborador) => {
    modal.confirm({
      title: '¿Restaurar este colaborador?',
      content: `${colaborador.nombre_completo} (legajo ${colaborador.legajo}) volverá a estar activo, con todo su historial.`,
      okText: 'Restaurar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setRestaurandoId(colaborador.id);
        try {
          await restaurarColaborador(colaborador.id);
          message.success('Colaborador restaurado correctamente');
          fetchColaboradores(pagination.current, pagination.pageSize, busqueda, todasEmpresas);
        } catch {
          message.error('No se pudo restaurar el colaborador');
        } finally {
          setRestaurandoId(null);
        }
      },
    });
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
          fetchColaboradores(pagination.current, pagination.pageSize, busqueda, todasEmpresas);
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
          <Avatar
            style={{ backgroundColor: colaborador.eliminado ? '#d1d5db' : colorForName(colaborador.nombre_completo) }}
          >
            {initialsForName(colaborador.nombre_completo)}
          </Avatar>
          <div className="min-w-0">
            <p className={`truncate font-semibold ${colaborador.eliminado ? 'text-gray-400' : 'text-gray-900'}`}>
              {colaborador.nombre_completo}
            </p>
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
      key: 'estado',
      width: 100,
      render: (_, colaborador) => (
        <span
          className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
            colaborador.eliminado
              ? 'bg-gray-100 text-gray-400'
              : colaborador.activo
                ? 'bg-green-50 text-green-700'
                : 'bg-gray-100 text-gray-500'
          }`}
        >
          {colaborador.eliminado ? 'Eliminado' : colaborador.activo ? 'Activo' : 'Inactivo'}
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
      width: 140,
      fixed: 'right',
      render: (_, colaborador) => {
        if (colaborador.eliminado) {
          return isAdmin ? (
            <Button
              type="text"
              size="small"
              icon={<UndoOutlined />}
              loading={restaurandoId === colaborador.id}
              aria-label={`Restaurar a ${colaborador.nombre_completo}`}
              onClick={() => handleRestaurar(colaborador)}
            >
              Restaurar
            </Button>
          ) : (
            <span className="text-xs text-gray-300">—</span>
          );
        }

        return (
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
              icon={<IdcardOutlined />}
              aria-label={`Imprimir carnet de ${colaborador.nombre_completo}`}
              title="Carnet"
              onClick={() => setCarnetColaborador(colaborador)}
            />
            <Button
              type="text"
              size="small"
              icon={<RightOutlined />}
              aria-label={`Abrir ficha de ${colaborador.nombre_completo}`}
              onClick={() => abrirColaborador(colaborador)}
            />
          </div>
        );
      },
    },
  ];

  return (
    <div>
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-gray-900">Gestión de personas</h2>
        <p className="text-sm text-gray-500">
          {todasEmpresas ? 'Colaboradores de todas tus empresas' : `Colaboradores de ${user?.empresa?.nombre}`}
        </p>
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
        <div className="flex items-center gap-2">
          <Switch size="small" checked={todasEmpresas} onChange={setTodasEmpresas} />
          <span className="text-sm text-gray-600">Todas las empresas</span>
        </div>
        {puedeVerHorarios && (
          <Button icon={<ClockCircleOutlined />} onClick={onVerHorarios}>
            Gestión de horarios
          </Button>
        )}
        {puedeCrear && (
          <Button icon={<UploadOutlined />} onClick={() => setImportarModalOpen(true)}>
            Importar
          </Button>
        )}
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
          rowClassName={(colaborador) => (colaborador.eliminado ? 'bg-gray-50' : '')}
          scroll={{ x: 1160 }}
          pagination={{
            current: pagination.current,
            pageSize: pagination.pageSize,
            total: pagination.total,
            pageSizeOptions: [10, 15],
            showSizeChanger: true,
          }}
          onChange={(paginationConfig) =>
            fetchColaboradores(paginationConfig.current, paginationConfig.pageSize, busqueda, todasEmpresas)
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

      <ImportarColaboradoresModal
        open={importarModalOpen}
        onCancel={() => setImportarModalOpen(false)}
        onImportado={() => {
          setImportarModalOpen(false);
          fetchColaboradores(pagination.current, pagination.pageSize, busqueda, todasEmpresas);
        }}
      />

      <VerColaboradorModal
        open={Boolean(verColaboradorId)}
        colaboradorId={verColaboradorId}
        onClose={() => setVerColaboradorId(null)}
      />

      <VerCarnetModal colaborador={carnetColaborador} onClose={() => setCarnetColaborador(null)} />
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
