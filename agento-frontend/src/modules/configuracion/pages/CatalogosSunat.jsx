import { Alert, Tabs } from 'antd';
import { useEffect } from 'react';
import { useSunatCatalogos } from '../hooks/useSunatCatalogos';
import ConceptosPlameTab from '../components/sunat/ConceptosPlameTab';
import MapeoGenericoTab from '../components/sunat/MapeoGenericoTab';
import SuspensionesTab from '../components/sunat/SuspensionesTab';

const TIPO_DOCUMENTO_ETIQUETAS = { dni: 'DNI', ce: 'Carné de Extranjería', pasaporte: 'Pasaporte' };
const TIPO_TRABAJADOR_ETIQUETAS = {
  trabajador: 'Trabajador (genérico, superado por Empleado/Obrero)',
  empleado: 'Empleado',
  obrero: 'Obrero',
  practicante: 'Practicante',
  locador: 'Locador',
};
const REGIMEN_ETIQUETAS = {
  General: 'Régimen General',
  'Micro Empresa': 'Micro Empresa',
  'Pequeña Empresa': 'Pequeña Empresa',
  'Locacion de Servicios': 'Locación de Servicios',
};
const COMPROBANTE_RH_ETIQUETAS = { recibo_honorarios: 'Recibo por Honorarios' };

/**
 * Configuraciones → Nóminas → Catálogos SUNAT. Administra únicamente la
 * capa de equivalencia "valor interno de Agento → código oficial SUNAT" que
 * usará el futuro exportador PLAME — no genera ningún archivo todavía.
 */
export default function CatalogosSunat({ user }) {
  const { resumen, fetchResumen } = useSunatCatalogos();

  useEffect(() => {
    fetchResumen();
  }, [fetchResumen]);

  const items = [
    {
      key: 'tipo_documento',
      label: 'Tipos de Documento',
      children: (
        <MapeoGenericoTab
          user={user}
          tipo="tipo_documento"
          etiquetas={TIPO_DOCUMENTO_ETIQUETAS}
          tablaSunat="Tabla 3 del Anexo 2 SUNAT"
          subtitulo="Tipos de documento de identidad usados por Agento para colaboradores."
        />
      ),
    },
    {
      key: 'tipo_trabajador',
      label: 'Tipos de Trabajador',
      children: (
        <MapeoGenericoTab
          user={user}
          tipo="tipo_trabajador"
          etiquetas={TIPO_TRABAJADOR_ETIQUETAS}
          tablaSunat="Tabla 8 del Anexo 2 SUNAT"
          subtitulo="Clasificación real de colaborador usada por Agento."
        />
      ),
    },
    {
      key: 'regimen_laboral',
      label: 'Regímenes',
      children: (
        <MapeoGenericoTab
          user={user}
          tipo="regimen_laboral"
          etiquetas={REGIMEN_ETIQUETAS}
          tablaSunat="Tabla 33 del Anexo 2 SUNAT"
          subtitulo="No todos los regímenes internos tienen necesariamente un código SUNAT 1:1 — Locación de Servicios no aplica."
        />
      ),
    },
    {
      key: 'suspensiones',
      label: 'Suspensiones',
      children: <SuspensionesTab user={user} />,
    },
    {
      key: 'conceptos_plame',
      label: 'Conceptos PLAME',
      children: <ConceptosPlameTab user={user} />,
    },
    {
      key: 'comprobantes_rh',
      label: 'Comprobantes RH',
      children: (
        <MapeoGenericoTab
          user={user}
          tipo="tipo_comprobante_rh"
          etiquetas={COMPROBANTE_RH_ETIQUETAS}
          tablaSunat="Tabla 23 del Anexo 2 SUNAT"
          subtitulo="Único tipo de comprobante que Agento emite hoy para locadores (recibo por honorarios)."
        />
      ),
    },
  ];

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Catálogos SUNAT</h2>
          <p className="text-sm text-gray-500">
            Configura códigos y equivalencias requeridas para integraciones SUNAT y PLAME.
          </p>
        </div>
        {resumen && (
          <div className="flex flex-wrap gap-4 rounded-xl bg-gray-50 px-4 py-2 text-sm">
            <span><span className="font-semibold text-gray-900">{resumen.total}</span> mapeos</span>
            <span><span className="font-semibold text-green-600">{resumen.configurados}</span> configurados</span>
            <span><span className="font-semibold text-orange-500">{resumen.requiere_configuracion}</span> requieren configuración</span>
            <span><span className="font-semibold text-red-500">{resumen.bloqueados_por_modelo}</span> bloqueados</span>
            <span><span className="font-semibold text-gray-400">{resumen.no_aplica}</span> no aplican</span>
          </div>
        )}
      </div>

      {resumen && resumen.pendientes > 0 && (
        <Alert
          className="mb-4"
          type="warning"
          showIcon
          message={`Hay ${resumen.pendientes} configuración(es) que todavía pueden afectar una futura exportación PLAME: ${resumen.requiere_configuracion} requiere(n) configuración y ${resumen.bloqueados_por_modelo} necesita(n) completar información funcional en Agento.`}
        />
      )}

      <Tabs items={items} />
    </div>
  );
}
