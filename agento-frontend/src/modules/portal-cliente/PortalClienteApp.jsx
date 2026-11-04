import {
  CalendarOutlined,
  ClockCircleOutlined,
  DollarOutlined,
  FileTextOutlined,
  GiftOutlined,
  HomeOutlined,
  ScheduleOutlined,
  SolutionOutlined,
  TeamOutlined,
  UserDeleteOutlined,
  UserSwitchOutlined,
  UsergroupAddOutlined,
} from '@ant-design/icons';
import { Result, Spin } from 'antd';
import { useEffect, useState } from 'react';
import PortalClienteLayout from './components/PortalClienteLayout';
import { usePortalContexto } from './hooks/usePortalContexto';
import BonosGerenciaPage from './pages/BonosGerenciaPage';
import CesesGerenciaPage from './pages/CesesGerenciaPage';
import ColaboradoresPage from './pages/ColaboradoresPage';
import EquipoAsistenciaPage from './pages/EquipoAsistenciaPage';
import HorasExtraGerenciaPage from './pages/HorasExtraGerenciaPage';
import HorasExtraPage from './pages/HorasExtraPage';
import IncidenciasPage from './pages/IncidenciasPage';
import InicioGerenciaPage from './pages/InicioGerenciaPage';
import PerfilAsistenciaPage from './pages/PerfilAsistenciaPage';
import PermisosGerenciaPage from './pages/PermisosGerenciaPage';
import PermisosPage from './pages/PermisosPage';
import PersonalGerenciaPage from './pages/PersonalGerenciaPage';
import PlanificacionGerenciaPage from './pages/PlanificacionGerenciaPage';
import PortalPlaceholderPage from './pages/PortalPlaceholderPage';
import ResumenAsistenciaPage from './pages/ResumenAsistenciaPage';

// Navegación propia del portal (mismo patrón de window.history.pushState +
// popstate que ya usa AppLayout.jsx para el sistema administrativo) — sin
// react-router-dom y sin tocar AppLayout/Sidebar/Header admin.
//
// Dos modos sobre la misma base: el Portal Cliente original (cliente_empresa,
// /portal-cliente, solo lectura) y el Portal de Empresa de gerencia
// (/portal). Cada sección declara la capacidad que el backend devolvió en
// /portal/contexto; el backend vuelve a autorizar cada endpoint.
const SECCIONES_CLIENTE = {
  resumen: { titulo: 'Resumen', ruta: '', icono: <HomeOutlined />, permiso: 'portal.asistencia.ver' },
  colaboradores: { titulo: 'Colaboradores', ruta: '/colaboradores', icono: <TeamOutlined />, permiso: 'portal.asistencia.ver' },
  incidencias: { titulo: 'Incidencias', ruta: '/incidencias', icono: <FileTextOutlined />, permiso: 'portal.asistencia.ver' },
  'horas-extra': { titulo: 'Horas Extra', ruta: '/horas-extra', icono: <ClockCircleOutlined />, permiso: 'portal.horas_extra.ver' },
  permisos: { titulo: 'Permisos', ruta: '/permisos', icono: <UserSwitchOutlined />, permiso: 'portal.permisos.ver' },
  planilla: { titulo: 'Planilla', ruta: '/planilla', icono: <DollarOutlined />, permiso: 'portal.remuneraciones.ver' },
  bonificaciones: { titulo: 'Bonificaciones', ruta: '/bonificaciones', icono: <GiftOutlined />, permiso: 'portal.remuneraciones.ver' },
};

const SECCIONES_GERENCIA = {
  inicio: { titulo: 'Inicio', ruta: '', icono: <HomeOutlined />, capacidad: 'inicio' },
  equipo: { titulo: 'Mi equipo y asistencia', ruta: '/equipo', icono: <TeamOutlined />, capacidad: 'equipo' },
  'horas-extra': { titulo: 'Horas extra', ruta: '/horas-extra', icono: <ClockCircleOutlined />, capacidad: 'horas_extra_ver' },
  permisos: { titulo: 'Permisos', ruta: '/permisos', icono: <UserSwitchOutlined />, capacidad: 'permisos_ver' },
  planificacion: { titulo: 'Planificación', ruta: '/planificacion', icono: <ScheduleOutlined />, capacidad: 'planificacion' },
  'bonos-comisiones': { titulo: 'Bonos y comisiones', ruta: '/bonos-comisiones', icono: <GiftOutlined />, capacidad: 'bonos' },
  'solicitudes-cese': { titulo: 'Solicitudes de cese', ruta: '/solicitudes-cese', icono: <UserDeleteOutlined />, capacidad: 'ceses' },
  'solicitudes-personal': { titulo: 'Solicitudes de personal', ruta: '/solicitudes-personal', icono: <UsergroupAddOutlined />, capacidad: 'personal' },
  vacaciones: { titulo: 'Vacaciones', ruta: '/vacaciones', icono: <CalendarOutlined />, siempreVisible: true },
};

// "Perfil de asistencia" (solo modo cliente) no es un ítem de menú aparte:
// se llega a él desde Colaboradores → fila.
function rutaDesdePath(pathname, base, secciones, inicial) {
  const normalizada = pathname.replace(/\/$/, '') || base;

  const detalleColaborador = normalizada.match(new RegExp(`^${base}/colaboradores/(\\d+)$`));
  if (detalleColaborador) {
    return { seccion: 'colaboradores', colaboradorId: Number(detalleColaborador[1]) };
  }

  const encontrada = Object.entries(secciones).find(([, s]) => `${base}${s.ruta}` === normalizada);

  return { seccion: encontrada ? encontrada[0] : inicial, colaboradorId: null };
}

export default function PortalClienteApp({ user, onLogout, base = '/portal-cliente', modo = 'cliente' }) {
  const { contexto, loading, error, fetchContexto } = usePortalContexto();
  const secciones = modo === 'gerencia' ? SECCIONES_GERENCIA : SECCIONES_CLIENTE;
  const inicial = modo === 'gerencia' ? 'inicio' : 'resumen';
  const [ruta, setRuta] = useState(() => rutaDesdePath(window.location.pathname, base, secciones, inicial));

  useEffect(() => {
    fetchContexto().catch(() => {});
  }, [fetchContexto]);

  useEffect(() => {
    const handlePopState = () => setRuta(rutaDesdePath(window.location.pathname, base, secciones, inicial));
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [base, secciones, inicial]);

  const navigate = (path) => {
    if (`${window.location.pathname}${window.location.search}` !== path) {
      window.history.pushState({}, '', path);
    }
    setRuta(rutaDesdePath(path.split('?')[0], base, secciones, inicial));
  };

  // filtros: abre el listado ya filtrado (lo leen las páginas con useFiltrosUrl).
  const irASeccion = (key, filtros = {}) => {
    const query = new URLSearchParams(Object.entries(filtros).filter(([, v]) => v !== undefined && v !== null && v !== ''));
    const texto = query.toString();
    navigate(`${base}${secciones[key]?.ruta ?? ''}${texto ? `?${texto}` : ''}`);
  };
  const abrirColaborador = (id) => navigate(`${base}/colaboradores/${id}`);
  const volverAColaboradores = () => navigate(`${base}/colaboradores`);

  if (loading) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-gray-50">
        <Spin size="large" />
      </div>
    );
  }

  // La autorización real ya ocurrió en el backend (permiso:portal.acceder) —
  // esto solo evita que un 403/401 deje la pantalla en blanco o rota.
  if (error || !contexto) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-gray-50 px-4">
        <Result
          status="403"
          title="Sin acceso al portal"
          subTitle="Tu cuenta no tiene autorización para ingresar al portal. Contacta a RR. HH."
          extra={
            <button
              type="button"
              onClick={onLogout}
              className="rounded-lg bg-agento-blue px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-agento-blue-dark"
            >
              Cerrar sesión
            </button>
          }
        />
      </div>
    );
  }

  const permisos = contexto.permisos ?? [];
  const capacidades = contexto.capacidades ?? {};
  const disponible = (s) => (modo === 'gerencia' ? s.siempreVisible || capacidades[s.capacidad] : permisos.includes(s.permiso));
  const menuItems = Object.entries(secciones)
    .filter(([, s]) => disponible(s))
    .map(([key, s]) => ({ key, icon: s.icono, label: s.titulo }));
  const seccionActual = secciones[ruta.seccion];
  const sinAcceso = <Result status="403" title="Sin acceso" subTitle="Tu rol no tiene acceso a esta sección del portal." />;

  let contenido;
  if (modo === 'gerencia') {
    const areas = contexto.areas ?? [];
    const paginas = {
      inicio: () => <InicioGerenciaPage onNavegar={irASeccion} />,
      equipo: () => <EquipoAsistenciaPage areas={areas} />,
      'horas-extra': () => <HorasExtraGerenciaPage puedeAprobar={!!capacidades.horas_extra_aprobar} />,
      permisos: () => <PermisosGerenciaPage puedeSolicitar={!!capacidades.permisos_solicitar} />,
      planificacion: () => <PlanificacionGerenciaPage areas={areas} />,
      'bonos-comisiones': () => <BonosGerenciaPage />,
      'solicitudes-cese': () => <CesesGerenciaPage />,
      'solicitudes-personal': () => <PersonalGerenciaPage puedeCrear={!!capacidades.personal_crear} />,
      vacaciones: () => (
        <Result
          status="info"
          icon={<SolutionOutlined />}
          title="Vacaciones aún no disponible"
          subTitle={contexto.dependencias_pendientes?.vacaciones ?? 'Dependencia pendiente.'}
        />
      ),
    };
    contenido = seccionActual && disponible(seccionActual) ? paginas[ruta.seccion]() : sinAcceso;
  } else if (ruta.seccion === 'colaboradores' && ruta.colaboradorId) {
    contenido = <PerfilAsistenciaPage colaboradorId={ruta.colaboradorId} permisos={permisos} onVolver={volverAColaboradores} />;
  } else if (ruta.seccion === 'resumen') {
    contenido = <ResumenAsistenciaPage tienePermiso={permisos.includes('portal.asistencia.ver')} />;
  } else if (ruta.seccion === 'colaboradores') {
    contenido = <ColaboradoresPage tienePermiso={permisos.includes('portal.asistencia.ver')} onAbrirColaborador={abrirColaborador} />;
  } else if (ruta.seccion === 'incidencias') {
    contenido = <IncidenciasPage tienePermiso={permisos.includes('portal.asistencia.ver')} />;
  } else if (ruta.seccion === 'horas-extra') {
    contenido = <HorasExtraPage tienePermiso={permisos.includes('portal.horas_extra.ver')} />;
  } else if (ruta.seccion === 'permisos') {
    contenido = <PermisosPage tienePermiso={permisos.includes('portal.permisos.ver')} />;
  } else {
    contenido = <PortalPlaceholderPage titulo={seccionActual?.titulo ?? 'Resumen'} />;
  }

  return (
    <PortalClienteLayout
      user={user}
      contexto={contexto}
      titulo={modo === 'gerencia' ? 'Portal de Empresa' : 'Portal Cliente'}
      menuItems={menuItems}
      seccion={ruta.seccion}
      tituloSeccion={ruta.colaboradorId ? 'Perfil de asistencia' : seccionActual?.titulo}
      onSelect={(key) => irASeccion(key)}
      onLogout={onLogout}
    >
      {contenido}
    </PortalClienteLayout>
  );
}
