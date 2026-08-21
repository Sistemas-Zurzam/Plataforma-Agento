import { Tabs } from 'antd';
import CatalogoConceptos from '../components/CatalogoConceptos';
import PoliticasTardanzaGlobal from '../components/PoliticasTardanzaGlobal';
import TramosTributarios from '../components/TramosTributarios';
import ComisionesAfp from './ComisionesAfp';
import ParametrosLaborales from './ParametrosLaborales';

/**
 * Contenedor que consolida en un solo lugar lo que antes eran 2 pestañas
 * sueltas (Parámetros Laborales, Comisiones AFP) más 3 pantallas nuevas.
 * Cada pestaña se gatea por el mismo permiso que ya protege su backend —
 * no se creó ningún permiso nuevo.
 */
export default function ParametrosRemunerativos({ user }) {
  const puedeVerParametros = user?.permisos?.includes('parametros_laborales.ver');
  const puedeVerComisiones = user?.permisos?.includes('comisiones_afp.ver');
  const puedeVerCatalogo = user?.permisos?.includes('nominas.ver');
  const puedeVerPoliticas = user?.permisos?.includes('empresas.editar');

  const items = [
    puedeVerParametros && {
      key: 'nacionales',
      label: 'Parámetros Nacionales',
      children: <ParametrosLaborales user={user} />,
    },
    puedeVerComisiones && {
      key: 'afp',
      label: 'AFP y Pensiones',
      children: <ComisionesAfp user={user} />,
    },
    puedeVerParametros && {
      key: 'tramos',
      label: 'Tramos Tributarios',
      children: <TramosTributarios />,
    },
    puedeVerCatalogo && {
      key: 'conceptos',
      label: 'Catálogo de Conceptos',
      children: <CatalogoConceptos />,
    },
    puedeVerPoliticas && {
      key: 'politicas',
      label: 'Políticas por Empresa',
      children: <PoliticasTardanzaGlobal user={user} />,
    },
  ].filter(Boolean);

  return (
    <div>
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-gray-900">Parámetros Remunerativos</h2>
        <p className="text-sm text-gray-500">
          Valores legales, tasas y políticas usados en el cálculo de nómina.
        </p>
      </div>
      <Tabs items={items} />
    </div>
  );
}
