import { Result, Spin } from 'antd';
import { useEffect, useState } from 'react';
import PortalClienteLayout from './components/PortalClienteLayout';
import { usePortalContexto } from './hooks/usePortalContexto';
import ColaboradoresPage from './pages/ColaboradoresPage';
import HorasExtraPage from './pages/HorasExtraPage';
import IncidenciasPage from './pages/IncidenciasPage';
import PerfilAsistenciaPage from './pages/PerfilAsistenciaPage';
import PermisosPage from './pages/PermisosPage';
import PortalPlaceholderPage from './pages/PortalPlaceholderPage';
import ResumenAsistenciaPage from './pages/ResumenAsistenciaPage';

// Navegación propia del portal (mismo patrón de window.history.pushState +
// popstate que ya usa AppLayout.jsx para el sistema administrativo) — sin
// react-router-dom y sin tocar AppLayout/Sidebar/Header admin.
const SECCIONES = {
  resumen: { titulo: 'Resumen', path: '/portal-cliente' },
  colaboradores: { titulo: 'Colaboradores', path: '/portal-cliente/colaboradores' },
  incidencias: { titulo: 'Incidencias', path: '/portal-cliente/incidencias' },
  'horas-extra': { titulo: 'Horas Extra', path: '/portal-cliente/horas-extra' },
  permisos: { titulo: 'Permisos', path: '/portal-cliente/permisos' },
  planilla: { titulo: 'Planilla', path: '/portal-cliente/planilla' },
  bonificaciones: { titulo: 'Bonificaciones', path: '/portal-cliente/bonificaciones' },
};

// "Perfil de asistencia" no es un ítem de menú aparte: se llega a él desde
// Colaboradores → fila (igual que el admin no tiene un menú separado para
// el detalle de un colaborador). /portal-cliente/colaboradores/{id} es esa
// vista, con navegación directa por URL y refresh soportados.
function rutaDesdePath(pathname) {
  const normalizada = pathname.replace(/\/$/, '') || '/portal-cliente';

  const detalleColaborador = normalizada.match(/^\/portal-cliente\/colaboradores\/(\d+)$/);
  if (detalleColaborador) {
    return { seccion: 'colaboradores', colaboradorId: Number(detalleColaborador[1]) };
  }

  const encontrada = Object.entries(SECCIONES).find(([, s]) => s.path === normalizada);

  return { seccion: encontrada ? encontrada[0] : 'resumen', colaboradorId: null };
}

export default function PortalClienteApp({ user, onLogout }) {
  const { contexto, loading, error, fetchContexto } = usePortalContexto();
  const [ruta, setRuta] = useState(() => rutaDesdePath(window.location.pathname));

  useEffect(() => {
    fetchContexto().catch(() => {});
  }, [fetchContexto]);

  useEffect(() => {
    const handlePopState = () => setRuta(rutaDesdePath(window.location.pathname));
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  const navigate = (path) => {
    if (window.location.pathname !== path) {
      window.history.pushState({}, '', path);
    }
    setRuta(rutaDesdePath(path));
  };

  const irASeccion = (key) => navigate(SECCIONES[key]?.path ?? '/portal-cliente');
  const abrirColaborador = (id) => navigate(`/portal-cliente/colaboradores/${id}`);
  const volverAColaboradores = () => navigate('/portal-cliente/colaboradores');

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
          title="Sin acceso al Portal Cliente"
          subTitle="Tu cuenta no tiene autorización para ingresar al portal. Contacta a tu administrador."
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
  const tiene = (permiso) => permisos.includes(permiso);

  let contenido;
  if (ruta.seccion === 'colaboradores' && ruta.colaboradorId) {
    contenido = (
      <PerfilAsistenciaPage colaboradorId={ruta.colaboradorId} permisos={permisos} onVolver={volverAColaboradores} />
    );
  } else if (ruta.seccion === 'resumen') {
    contenido = <ResumenAsistenciaPage tienePermiso={tiene('portal.acceder')} />;
  } else if (ruta.seccion === 'colaboradores') {
    contenido = <ColaboradoresPage tienePermiso={tiene('portal.asistencia.ver')} onAbrirColaborador={abrirColaborador} />;
  } else if (ruta.seccion === 'incidencias') {
    contenido = <IncidenciasPage tienePermiso={tiene('portal.asistencia.ver')} />;
  } else if (ruta.seccion === 'horas-extra') {
    contenido = <HorasExtraPage tienePermiso={tiene('portal.horas_extra.ver')} />;
  } else if (ruta.seccion === 'permisos') {
    contenido = <PermisosPage tienePermiso={tiene('portal.permisos.ver')} />;
  } else {
    contenido = <PortalPlaceholderPage titulo={SECCIONES[ruta.seccion]?.titulo ?? 'Resumen'} />;
  }

  return (
    <PortalClienteLayout
      user={user}
      contexto={contexto}
      seccion={ruta.seccion}
      tituloSeccion={ruta.colaboradorId ? 'Perfil de asistencia' : SECCIONES[ruta.seccion]?.titulo}
      onSelect={irASeccion}
      onLogout={onLogout}
    >
      {contenido}
    </PortalClienteLayout>
  );
}
