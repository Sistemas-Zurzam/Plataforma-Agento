import { Result } from 'antd';
import { tiene } from '../constants/seleccion';
import BancoTalentosPage from './BancoTalentosPage';
import CandidatosPage from './CandidatosPage';
import DashboardSeleccion from './DashboardSeleccion';
import EvaluacionesPage from './EvaluacionesPage';
import ProcesoDetallePage from './ProcesoDetallePage';
import ProcesosPage from './ProcesosPage';
import SolicitudesPage from './SolicitudesPage';

/**
 * Selección y Reclutamiento. La vista sale de la URL (/seleccion/...) que
 * resuelve AppLayout; los permisos del menú son solo experiencia de
 * usuario: el backend vuelve a validar cada acción.
 */
export default function GestionSeleccion({ user, vista, procesoId, navigate }) {
  const permisos = {
    seguimiento: tiene(user, 'seleccion.seguimiento', 'seleccion.vacantes.gestionar'),
    solicitudes: tiene(user, 'seleccion.solicitudes.crear', 'seleccion.solicitudes.decidir', 'seleccion.seguimiento', 'seleccion.vacantes.gestionar'),
    procesos: tiene(user, 'seleccion.seguimiento', 'seleccion.vacantes.gestionar', 'seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar'),
    candidatos: tiene(user, 'seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar'),
    banco: tiene(user, 'seleccion.candidatos.gestionar'),
    evaluaciones: tiene(user, 'seleccion.evaluaciones.gestionar'),
  };

  const sinAcceso = <Result status="403" title="Acceso denegado" subTitle="No tienes permiso para ver esta sección." />;
  const abrirProceso = (id) => navigate(`/seleccion/procesos/${id}`);

  if (vista === 'procesos' && procesoId) {
    return permisos.procesos
      ? <ProcesoDetallePage user={user} procesoId={procesoId} onVolver={() => navigate('/seleccion/procesos')} />
      : sinAcceso;
  }

  switch (vista) {
    case 'solicitudes':
      return permisos.solicitudes ? <SolicitudesPage user={user} onAbrirProceso={abrirProceso} /> : sinAcceso;
    case 'procesos':
      return permisos.procesos ? <ProcesosPage user={user} onAbrirProceso={abrirProceso} /> : sinAcceso;
    case 'candidatos':
      return permisos.candidatos ? <CandidatosPage user={user} onAbrirProceso={abrirProceso} /> : sinAcceso;
    case 'banco-talentos':
      return permisos.banco ? <BancoTalentosPage user={user} /> : sinAcceso;
    case 'evaluaciones':
      return permisos.evaluaciones ? <EvaluacionesPage user={user} /> : sinAcceso;
    default:
      return <DashboardSeleccion user={user} puedeVerIndicadores={permisos.seguimiento} onAbrirProceso={abrirProceso} />;
  }
}
