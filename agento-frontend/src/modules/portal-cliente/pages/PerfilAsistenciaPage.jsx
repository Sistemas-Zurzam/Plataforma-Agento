import { ArrowLeftOutlined } from '@ant-design/icons';
import { Button, Card, Col, Descriptions, Row, Statistic, Table, Tabs, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import EstadoConsulta from '../components/EstadoConsulta';
import PortalRangoFechas from '../components/PortalRangoFechas';
import SinAcceso from '../components/SinAcceso';
import { usePortalAsistencia } from '../hooks/usePortalAsistencia';

// jornadas_* cuenta días (AsistenciaResultadoDiario) de este colaborador
// dentro del rango — no es una cuenta de personas (acá siempre es 1).
const METRICAS = [
  { key: 'jornadas_presentes', label: 'Jornadas presentes' },
  { key: 'jornadas_con_falta', label: 'Jornadas con falta' },
  { key: 'jornadas_descanso', label: 'Jornadas de descanso' },
  { key: 'jornadas_marcacion_incompleta', label: 'Marc. incompletas' },
  { key: 'incidencias_pendientes', label: 'Incidencias pend.' },
  { key: 'horas_extra_pendientes', label: 'Horas extra pend.' },
  { key: 'permisos_registrados', label: 'Permisos' },
];

const COLOR_ESTADO_INCIDENCIA = { pendiente: 'orange', resuelta: 'green', rechazada: 'red' };
const COLOR_ESTADO_HE_PERMISO = { pendiente: 'orange', aprobado: 'green', rechazado: 'red' };

function usePestania(cargando, tienePermiso, rango, pagina, ejecutar) {
  const [respuesta, setRespuesta] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!tienePermiso) {
      return;
    }
    ejecutar()
      .then((data) => {
        setError(null);
        setRespuesta(data);
      })
      .catch(setError);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tienePermiso, rango, pagina]);

  return { respuesta, error };
}

export default function PerfilAsistenciaPage({ colaboradorId, permisos, onVolver }) {
  const asistencia = usePortalAsistencia();
  const [rango, setRango] = useState({
    fecha_desde: dayjs().subtract(29, 'day').format('YYYY-MM-DD'),
    fecha_hasta: dayjs().format('YYYY-MM-DD'),
  });
  const [tabActiva, setTabActiva] = useState('resumen');
  const [paginas, setPaginas] = useState({ incidencias: 1, horasExtra: 1, permisos: 1, historial: 1 });

  const puedeVerAsistencia = permisos?.includes('portal.asistencia.ver');
  const puedeVerHorasExtra = permisos?.includes('portal.horas_extra.ver');
  const puedeVerPermisos = permisos?.includes('portal.permisos.ver');

  const resumen = usePestania(tabActiva === 'resumen', puedeVerAsistencia, rango, null,
    () => asistencia.fetchColaborador(colaboradorId, rango));
  const calendario = usePestania(tabActiva === 'calendario', puedeVerAsistencia, rango, null,
    () => asistencia.fetchCalendario(colaboradorId, rango));
  const marcaciones = usePestania(tabActiva === 'marcaciones', puedeVerAsistencia, rango, null,
    () => asistencia.fetchMarcaciones(colaboradorId, rango));
  const incidencias = usePestania(tabActiva === 'incidencias', puedeVerAsistencia, rango, paginas.incidencias,
    () => asistencia.fetchIncidenciasDeColaborador(colaboradorId, { ...rango, page: paginas.incidencias, per_page: 15 }));
  const horasExtra = usePestania(tabActiva === 'horas-extra', puedeVerHorasExtra, rango, paginas.horasExtra,
    () => asistencia.fetchHorasExtraDeColaborador(colaboradorId, { ...rango, page: paginas.horasExtra, per_page: 15 }));
  const permisosDelColaborador = usePestania(tabActiva === 'permisos', puedeVerPermisos, rango, paginas.permisos,
    () => asistencia.fetchPermisosDeColaborador(colaboradorId, { ...rango, page: paginas.permisos, per_page: 15 }));
  const historial = usePestania(tabActiva === 'historial', puedeVerAsistencia, rango, paginas.historial,
    () => asistencia.fetchHistorial(colaboradorId, { ...rango, page: paginas.historial, per_page: 15 }));

  const items = useMemo(() => {
    const lista = [];

    if (puedeVerAsistencia) {
      lista.push({
        key: 'resumen',
        label: 'Resumen',
        children: (
          <EstadoConsulta cargando={asistencia.loading && tabActiva === 'resumen'} error={resumen.error} vacio={false}>
            <div className="space-y-4">
              <Descriptions size="small" column={2} bordered>
                <Descriptions.Item label="Nombre">{resumen.respuesta?.data?.nombre_completo}</Descriptions.Item>
                <Descriptions.Item label="Código">{resumen.respuesta?.data?.legajo}</Descriptions.Item>
                <Descriptions.Item label="Área">{resumen.respuesta?.data?.area}</Descriptions.Item>
                <Descriptions.Item label="Sede">{resumen.respuesta?.data?.sede}</Descriptions.Item>
                <Descriptions.Item label="Cargo">{resumen.respuesta?.data?.cargo}</Descriptions.Item>
                <Descriptions.Item label="Estado">{resumen.respuesta?.data?.estado_laboral}</Descriptions.Item>
              </Descriptions>
              <Row gutter={[16, 16]}>
                {METRICAS.map((metrica) => (
                  <Col xs={12} sm={8} lg={6} key={metrica.key}>
                    <Card>
                      <Statistic title={metrica.label} value={resumen.respuesta?.metricas?.[metrica.key] ?? 0} />
                    </Card>
                  </Col>
                ))}
              </Row>
            </div>
          </EstadoConsulta>
        ),
      });

      lista.push({
        key: 'calendario',
        label: 'Calendario',
        children: (
          <EstadoConsulta
            cargando={asistencia.loading && tabActiva === 'calendario'}
            error={calendario.error}
            vacio={calendario.respuesta?.length === 0}
          >
            <Table
              rowKey="fecha"
              size="small"
              dataSource={calendario.respuesta ?? []}
              pagination={false}
              scroll={{ x: true, y: 420 }}
              columns={[
                { title: 'Fecha', dataIndex: 'fecha' },
                { title: 'Planificado', dataIndex: 'tipo_dia_planificado', render: (v) => v ?? '—' },
                { title: 'Estado', dataIndex: 'estado', render: (v) => v ?? '—' },
                { title: 'Entrada', dataIndex: 'entrada', render: (v) => v ?? '—' },
                { title: 'Salida', dataIndex: 'salida', render: (v) => v ?? '—' },
                { title: 'Min. trabajados', dataIndex: 'minutos_trabajados', render: (v) => v ?? '—' },
                { title: 'Incidencias', dataIndex: 'incidencias_pendientes' },
              ]}
            />
          </EstadoConsulta>
        ),
      });

      lista.push({
        key: 'marcaciones',
        label: 'Marcaciones',
        children: (
          <EstadoConsulta
            cargando={asistencia.loading && tabActiva === 'marcaciones'}
            error={marcaciones.error}
            vacio={marcaciones.respuesta?.length === 0}
          >
            <Table
              rowKey="id"
              size="small"
              dataSource={marcaciones.respuesta ?? []}
              pagination={false}
              scroll={{ x: true, y: 420 }}
              columns={[
                { title: 'Fecha y hora', dataIndex: 'marcado_at' },
                { title: 'Origen', dataIndex: 'origen' },
                {
                  title: 'Estado',
                  dataIndex: 'estado',
                  render: (v) => <Tag color={v === 'anulada' ? 'default' : 'green'}>{v}</Tag>,
                },
              ]}
            />
          </EstadoConsulta>
        ),
      });

      lista.push({
        key: 'incidencias',
        label: 'Incidencias',
        children: (
          <EstadoConsulta
            cargando={asistencia.loading && tabActiva === 'incidencias'}
            error={incidencias.error}
            vacio={incidencias.respuesta?.data?.length === 0}
          >
            <Table
              rowKey="id"
              size="small"
              dataSource={incidencias.respuesta?.data ?? []}
              pagination={{
                current: incidencias.respuesta?.meta?.current_page ?? 1,
                pageSize: incidencias.respuesta?.meta?.per_page ?? 15,
                total: incidencias.respuesta?.meta?.total ?? 0,
                onChange: (p) => setPaginas((prev) => ({ ...prev, incidencias: p })),
              }}
              scroll={{ x: true }}
              columns={[
                { title: 'Fecha', dataIndex: 'fecha' },
                { title: 'Tipo', dataIndex: 'tipo' },
                {
                  title: 'Estado',
                  dataIndex: 'estado',
                  render: (v) => <Tag color={COLOR_ESTADO_INCIDENCIA[v] ?? 'default'}>{v}</Tag>,
                },
                { title: 'Descripción', dataIndex: 'descripcion' },
              ]}
            />
          </EstadoConsulta>
        ),
      });

      lista.push({
        key: 'historial',
        label: 'Historial de asistencia',
        children: (
          <EstadoConsulta
            cargando={asistencia.loading && tabActiva === 'historial'}
            error={historial.error}
            vacio={historial.respuesta?.data?.length === 0}
          >
            <Typography.Paragraph type="secondary" className="text-xs">
              Muestra los resultados diarios de asistencia ya calculados (uno
              por día), no un registro de auditoría interna.
            </Typography.Paragraph>
            <Table
              rowKey="id"
              size="small"
              dataSource={historial.respuesta?.data ?? []}
              pagination={{
                current: historial.respuesta?.meta?.current_page ?? 1,
                pageSize: historial.respuesta?.meta?.per_page ?? 15,
                total: historial.respuesta?.meta?.total ?? 0,
                onChange: (p) => setPaginas((prev) => ({ ...prev, historial: p })),
              }}
              scroll={{ x: true }}
              columns={[
                { title: 'Fecha', dataIndex: 'fecha' },
                { title: 'Tipo de día', dataIndex: 'tipo_dia' },
                { title: 'Estado', dataIndex: 'estado' },
                { title: 'Entrada', dataIndex: 'entrada', render: (v) => v ?? '—' },
                { title: 'Salida', dataIndex: 'salida', render: (v) => v ?? '—' },
                { title: 'Min. trabajados', dataIndex: 'minutos_trabajados' },
              ]}
            />
          </EstadoConsulta>
        ),
      });
    }

    if (puedeVerHorasExtra) {
      lista.splice(4, 0, {
        key: 'horas-extra',
        label: 'Horas Extra',
        children: (
          <EstadoConsulta
            cargando={asistencia.loading && tabActiva === 'horas-extra'}
            error={horasExtra.error}
            vacio={horasExtra.respuesta?.data?.length === 0}
          >
            <Table
              rowKey="id"
              size="small"
              dataSource={horasExtra.respuesta?.data ?? []}
              pagination={{
                current: horasExtra.respuesta?.meta?.current_page ?? 1,
                pageSize: horasExtra.respuesta?.meta?.per_page ?? 15,
                total: horasExtra.respuesta?.meta?.total ?? 0,
                onChange: (p) => setPaginas((prev) => ({ ...prev, horasExtra: p })),
              }}
              scroll={{ x: true }}
              columns={[
                { title: 'Fecha', dataIndex: 'fecha' },
                { title: 'Tasa', dataIndex: 'tasa', render: (v) => `${v}%` },
                { title: 'Min. observados', dataIndex: 'minutos_observados' },
                { title: 'Min. aprobados', dataIndex: 'minutos_aprobados' },
                {
                  title: 'Estado',
                  dataIndex: 'estado',
                  render: (v) => <Tag color={COLOR_ESTADO_HE_PERMISO[v] ?? 'default'}>{v}</Tag>,
                },
              ]}
            />
          </EstadoConsulta>
        ),
      });
    }

    if (puedeVerPermisos) {
      lista.splice(puedeVerHorasExtra ? 5 : 4, 0, {
        key: 'permisos',
        label: 'Permisos',
        children: (
          <EstadoConsulta
            cargando={asistencia.loading && tabActiva === 'permisos'}
            error={permisosDelColaborador.error}
            vacio={permisosDelColaborador.respuesta?.data?.length === 0}
          >
            <Table
              rowKey="id"
              size="small"
              dataSource={permisosDelColaborador.respuesta?.data ?? []}
              pagination={{
                current: permisosDelColaborador.respuesta?.meta?.current_page ?? 1,
                pageSize: permisosDelColaborador.respuesta?.meta?.per_page ?? 15,
                total: permisosDelColaborador.respuesta?.meta?.total ?? 0,
                onChange: (p) => setPaginas((prev) => ({ ...prev, permisos: p })),
              }}
              scroll={{ x: true }}
              columns={[
                { title: 'Tipo', dataIndex: 'tipo' },
                { title: 'Desde', dataIndex: 'fecha_inicio' },
                { title: 'Hasta', dataIndex: 'fecha_fin' },
                {
                  title: 'Motivo',
                  dataIndex: 'motivo',
                  render: (v, registro) => (registro.motivo_oculto_por_privacidad
                    ? <Typography.Text type="secondary" italic>Información médica reservada</Typography.Text>
                    : (v ?? '—')),
                },
                { title: 'Con goce', dataIndex: 'con_goce', render: (v) => (v ? 'Sí' : 'No') },
                {
                  title: 'Estado',
                  dataIndex: 'estado',
                  render: (v) => <Tag color={COLOR_ESTADO_HE_PERMISO[v] ?? 'default'}>{v}</Tag>,
                },
              ]}
            />
          </EstadoConsulta>
        ),
      });
    }

    return lista;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tabActiva, resumen, calendario, marcaciones, incidencias, horasExtra, permisosDelColaborador, historial, paginas]);

  if (!puedeVerAsistencia) {
    return <SinAcceso />;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button icon={<ArrowLeftOutlined />} onClick={onVolver}>
          Volver a colaboradores
        </Button>
        <PortalRangoFechas value={rango} onChange={setRango} />
      </div>
      <Tabs activeKey={tabActiva} onChange={setTabActiva} items={items} />
    </div>
  );
}
