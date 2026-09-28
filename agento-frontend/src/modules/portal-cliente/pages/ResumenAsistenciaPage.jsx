import { Card, Col, Row, Statistic } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import EstadoConsulta from '../components/EstadoConsulta';
import PortalRangoFechas from '../components/PortalRangoFechas';
import SinAcceso from '../components/SinAcceso';
import { usePortalAsistencia } from '../hooks/usePortalAsistencia';

// jornadas_* cuenta días de asistencia (AsistenciaResultadoDiario) dentro
// del rango, no colaboradores distintos — un mismo colaborador con varios
// días "presente" suma varias jornadas. colaboradores_activos es la única
// métrica de personas (el roster actual), independiente del rango.
const METRICAS = [
  { key: 'colaboradores_activos', label: 'Colaboradores activos' },
  { key: 'jornadas_presentes', label: 'Jornadas presentes' },
  { key: 'jornadas_con_falta', label: 'Jornadas con falta' },
  { key: 'jornadas_descanso', label: 'Jornadas de descanso' },
  { key: 'jornadas_marcacion_incompleta', label: 'Jornadas con marcación incompleta' },
  { key: 'incidencias_pendientes', label: 'Incidencias pendientes' },
  { key: 'horas_extra_pendientes', label: 'Horas extra pendientes' },
  { key: 'permisos_registrados', label: 'Permisos registrados' },
];

export default function ResumenAsistenciaPage({ tienePermiso }) {
  const { fetchResumen, loading } = usePortalAsistencia();
  const [rango, setRango] = useState({
    fecha_desde: dayjs().subtract(29, 'day').format('YYYY-MM-DD'),
    fecha_hasta: dayjs().format('YYYY-MM-DD'),
  });
  const [resumen, setResumen] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!tienePermiso) {
      return;
    }
    fetchResumen(rango)
      .then((data) => {
        setError(null);
        setResumen(data);
      })
      .catch(setError);
  }, [tienePermiso, rango, fetchResumen]);

  if (!tienePermiso) {
    return <SinAcceso />;
  }

  return (
    <div className="space-y-4">
      <PortalRangoFechas value={rango} onChange={setRango} />
      <EstadoConsulta cargando={loading} error={error} vacio={false}>
        <Row gutter={[16, 16]}>
          {METRICAS.map((metrica) => (
            <Col xs={12} sm={8} lg={6} key={metrica.key}>
              <Card>
                <Statistic title={metrica.label} value={resumen?.[metrica.key] ?? 0} />
              </Card>
            </Col>
          ))}
        </Row>
      </EstadoConsulta>
    </div>
  );
}
