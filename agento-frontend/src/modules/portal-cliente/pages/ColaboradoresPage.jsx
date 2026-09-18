import { Input, Select, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import EstadoConsulta from '../components/EstadoConsulta';
import SinAcceso from '../components/SinAcceso';
import { usePortalAsistencia } from '../hooks/usePortalAsistencia';

const ESTADOS = [
  { value: 'activo', label: 'Activo' },
  { value: 'inactivo', label: 'Inactivo' },
];

export default function ColaboradoresPage({ tienePermiso, onAbrirColaborador }) {
  const { fetchColaboradores, loading } = usePortalAsistencia();
  const [busqueda, setBusqueda] = useState('');
  const [estado, setEstado] = useState(undefined);
  const [pagina, setPagina] = useState(1);
  const [respuesta, setRespuesta] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!tienePermiso) {
      return;
    }
    fetchColaboradores({ busqueda: busqueda || undefined, estado, page: pagina, per_page: 15 })
      .then((data) => {
        setError(null);
        setRespuesta(data);
      })
      .catch(setError);
  }, [tienePermiso, busqueda, estado, pagina, fetchColaboradores]);

  if (!tienePermiso) {
    return <SinAcceso />;
  }

  const columnas = [
    { title: 'Código', dataIndex: 'legajo' },
    { title: 'Nombre', dataIndex: 'nombre_completo' },
    { title: 'Documento', dataIndex: 'documento_enmascarado' },
    { title: 'Área', dataIndex: 'area' },
    { title: 'Sede', dataIndex: 'sede' },
    { title: 'Cargo', dataIndex: 'cargo' },
    {
      title: 'Estado',
      dataIndex: 'estado_laboral',
      render: (valor) => <Tag color={valor === 'activo' ? 'green' : 'default'}>{valor}</Tag>,
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <Input.Search
          placeholder="Buscar por nombre, código o documento"
          allowClear
          className="max-w-xs"
          onSearch={(valor) => {
            setPagina(1);
            setBusqueda(valor);
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
          onRow={(record) => ({ onClick: () => onAbrirColaborador(record.id) })}
          rowClassName={() => 'cursor-pointer'}
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
