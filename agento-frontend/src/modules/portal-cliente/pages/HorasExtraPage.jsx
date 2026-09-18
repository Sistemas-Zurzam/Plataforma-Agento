import { Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import EstadoConsulta from '../components/EstadoConsulta';
import PortalRangoFechas from '../components/PortalRangoFechas';
import SinAcceso from '../components/SinAcceso';
import { usePortalAsistencia } from '../hooks/usePortalAsistencia';

const ESTADOS = [
  { value: 'pendiente', label: 'Pendiente' },
  { value: 'aprobado', label: 'Aprobado' },
  { value: 'rechazado', label: 'Rechazado' },
];

const COLOR_ESTADO = { pendiente: 'orange', aprobado: 'green', rechazado: 'red' };

export default function HorasExtraPage({ tienePermiso }) {
  const { fetchHorasExtra, loading } = usePortalAsistencia();
  const [rango, setRango] = useState({
    fecha_desde: dayjs().subtract(29, 'day').format('YYYY-MM-DD'),
    fecha_hasta: dayjs().format('YYYY-MM-DD'),
  });
  const [estado, setEstado] = useState(undefined);
  const [pagina, setPagina] = useState(1);
  const [respuesta, setRespuesta] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!tienePermiso) {
      return;
    }
    fetchHorasExtra({ ...rango, estado, page: pagina, per_page: 15 })
      .then((data) => {
        setError(null);
        setRespuesta(data);
      })
      .catch(setError);
  }, [tienePermiso, rango, estado, pagina, fetchHorasExtra]);

  if (!tienePermiso) {
    return <SinAcceso />;
  }

  const columnas = [
    { title: 'Fecha', dataIndex: 'fecha' },
    { title: 'Colaborador', render: (_, registro) => registro.colaborador?.nombre_completo },
    { title: 'Área', render: (_, registro) => registro.colaborador?.area },
    { title: 'Tasa', dataIndex: 'tasa', render: (valor) => `${valor}%` },
    { title: 'Min. observados', dataIndex: 'minutos_observados' },
    { title: 'Min. aprobados', dataIndex: 'minutos_aprobados' },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (valor) => <Tag color={COLOR_ESTADO[valor] ?? 'default'}>{valor}</Tag>,
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <PortalRangoFechas
          value={rango}
          onChange={(valor) => {
            setPagina(1);
            setRango(valor);
          }}
        />
        <Select
          placeholder="Estado"
          allowClear
          className="w-40"
          options={ESTADOS}
          onChange={(valor) => {
            setPagina(1);
            setEstado(valor);
          }}
        />
      </div>
      <EstadoConsulta cargando={loading} error={error} vacio={respuesta?.data?.length === 0}>
        <Table
          rowKey="id"
          size="small"
          dataSource={respuesta?.data ?? []}
          columns={columnas}
          pagination={{
            current: respuesta?.meta?.current_page ?? 1,
            pageSize: respuesta?.meta?.per_page ?? 15,
            total: respuesta?.meta?.total ?? 0,
            onChange: setPagina,
          }}
          scroll={{ x: true }}
        />
      </EstadoConsulta>
    </div>
  );
}
