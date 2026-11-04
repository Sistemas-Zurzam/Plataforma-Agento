import { ReloadOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Progress, Skeleton, Typography } from 'antd';
import { formatoFecha } from '../constants/estadosPortal';
import { useInicioPortal } from '../hooks/usePortalGestion';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';

const { Text } = Typography;

function Indicador({ titulo, valor, detalle, onAbrir, etiquetaAccion = 'Ver listado' }) {
  return (
    <Card size="small" className="h-full">
      <div className="flex h-full flex-col gap-2">
        <Text type="secondary" className="text-sm">{titulo}</Text>
        <span className="text-3xl font-semibold text-gray-900">{valor}</span>
        {detalle && <Text type="secondary" className="text-xs">{detalle}</Text>}
        {onAbrir && (
          <Button type="link" className="!px-0 self-start" onClick={onAbrir}>{etiquetaAccion}</Button>
        )}
      </div>
    </Card>
  );
}

/**
 * Mesa de trabajo: solo pendientes reales del alcance, con el período
 * explícito. Cada indicador abre el listado con los mismos filtros con los
 * que el backend calculó el contador. Un indicador sin permiso no se muestra.
 */
export default function InicioGerenciaPage({ onNavegar }) {
  const { datos, cargando, error, recargar } = useInicioPortal();

  if (cargando && !datos) {
    return <Skeleton active paragraph={{ rows: 6 }} />;
  }
  if (error) {
    const estado = error?.response?.status;
    return (
      <Alert
        type={estado === 403 ? 'warning' : 'error'}
        showIcon
        message={estado === 403 ? 'No tienes acceso a la mesa de trabajo.' : mensajeDeError(error, 'No se pudo cargar la mesa de trabajo.')}
        action={estado !== 403 && <Button size="small" onClick={recargar}>Reintentar</Button>}
      />
    );
  }

  const { horas_extra: horasExtra, rotativos, personal, ceses, bonos } = datos ?? {};
  const sinIndicadores = !horasExtra && !rotativos && !personal && !ceses && !bonos;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Text type="secondary">Pendientes de tus áreas autorizadas, calculados con los mismos criterios que cada listado.</Text>
        <Button icon={<ReloadOutlined />} onClick={recargar} loading={cargando}>Actualizar</Button>
      </div>

      {sinIndicadores && <Alert type="info" showIcon message="Tu rol no tiene indicadores habilitados en el portal." />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {horasExtra && (
          <Indicador
            titulo="Horas extra por aprobar"
            valor={horasExtra.por_aprobar}
            detalle={`Del ${formatoFecha(horasExtra.fecha_desde)} al ${formatoFecha(horasExtra.fecha_hasta)} · ${horasExtra.en_validacion_rrhh} en validación de RR. HH.`}
            onAbrir={() => onNavegar('horas-extra', { fecha_desde: horasExtra.fecha_desde, fecha_hasta: horasExtra.fecha_hasta, estado: 'pendiente' })}
          />
        )}
        {rotativos && (
          <Indicador
            titulo="Rotativos sin planificación"
            valor={rotativos.colaboradores_sin_planificar}
            detalle={`Semana del ${formatoFecha(rotativos.semana.desde)} al ${formatoFecha(rotativos.semana.hasta)} · ${rotativos.dias_sin_planificar} día(s) sin planificar · ${rotativos.con_descuadre} con descansos que no cuadran`}
            onAbrir={() => onNavegar('planificacion', { semana: rotativos.semana.desde })}
            etiquetaAccion="Planificar semana"
          />
        )}
        {personal && (
          <Indicador
            titulo="Solicitudes de personal devueltas"
            valor={personal.devueltas}
            detalle={`${personal.borradores} borrador(es) · ${personal.enviadas} en revisión de RR. HH.`}
            onAbrir={() => onNavegar('solicitudes-personal', { estado: 'devuelta' })}
            etiquetaAccion="Corregir solicitudes"
          />
        )}
        {personal && (
          <Card size="small" className="h-full">
            <div className="flex flex-col gap-2">
              <Text type="secondary" className="text-sm">Vacantes en selección</Text>
              <span className="text-3xl font-semibold text-gray-900">{personal.en_proceso}</span>
              <Text type="secondary" className="text-xs">
                Plazas cubiertas: {personal.plazas_cubiertas} de {personal.plazas_vigentes}
              </Text>
              {personal.plazas_vigentes > 0 && (
                <Progress percent={Math.round((personal.plazas_cubiertas / personal.plazas_vigentes) * 100)} size="small" aria-label="Avance de plazas cubiertas" />
              )}
              <Button type="link" className="!px-0 self-start" onClick={() => onNavegar('solicitudes-personal', { estado: 'aceptada' })}>Ver seguimiento</Button>
            </div>
          </Card>
        )}
        {ceses && (
          <Indicador
            titulo="Solicitudes de cese en curso"
            valor={ceses.en_curso}
            detalle="Enviadas o aprobadas pendientes de ejecución por RR. HH."
            onAbrir={() => onNavegar('solicitudes-cese', {})}
          />
        )}
        {bonos && (
          <Indicador
            titulo="Bonos y comisiones propuestos"
            valor={bonos.propuestos}
            detalle="Esperando la decisión de RR. HH."
            onAbrir={() => onNavegar('bonos-comisiones', { estado: 'propuesto' })}
          />
        )}
      </div>
    </div>
  );
}
