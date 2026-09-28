import { Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import EstadoConsulta from '../components/EstadoConsulta';
import PortalRangoFechas from '../components/PortalRangoFechas';
import SinAcceso from '../components/SinAcceso';
import { usePortalAsistencia } from '../hooks/usePortalAsistencia';

const ESTADOS = [
  { value: 'pendiente', label: 'Pendiente' },
  { value: 'resuelta', label: 'Resuelta' },
  { value: 'rechazada', label: 'Rechazada' },
];

const COLOR_ESTADO = { pendiente: 'orange', resuelta: 'green', rechazada: 'red' };

export default function IncidenciasPage({ tienePermiso }) {
  const { fetchIncidencias, loading } = usePortalAsistencia();
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
    fetchIncidencias({ ...rango, estado, page: pagina, per_page: 15 })
      .then((data) => {
        setError(null);
        setRespuesta(data);
      })
      .catch(setError);
  }, [tienePermiso, rango, estado, pagina, fetchIncidencias]);

  if (!tienePermiso) {
    return <SinAcceso />;
  }

  const columnas = [
    { title: 'Fecha', dataIndex: 'fecha' },
    { title: 'Colaborador', render: (_, registro) => registro.colaborador?.nombre_completo },
    { title: 'Área', render: (_, registro) => registro.colaborador?.area },
    { title: 'Tipo', dataIndex: 'tipo' },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (valor) => <Tag color={COLOR_ESTADO[valor] ?? 'default'}>{valor}</Tag>,
    },
    { title: 'Descripción', dataIndex: 'descripcion' },
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
