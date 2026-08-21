import { Input, Select, Table, Tag } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { useConceptosRemuneracion } from '../hooks/useConceptosRemuneracion';

const TIPO_OPTIONS = [
  { value: 'todos', label: 'Todos los tipos' },
  { value: 'ingreso', label: 'Ingreso' },
  { value: 'egreso', label: 'Egreso' },
  { value: 'aportacion', label: 'Aportación' },
];

const TIPO_COLOR = { ingreso: 'green', egreso: 'red', aportacion: 'blue' };

/**
 * Solo lectura por ahora: las banderas del concepto (es_remunerativo_laboral,
 * afecta_renta_5ta) alimentan directamente el motor de cálculo de planilla,
 * así que crear/editar desde acá se deja pendiente hasta definir las
 * validaciones de negocio correspondientes.
 */
export default function CatalogoConceptos() {
  const { conceptos, loading, fetchConceptos } = useConceptosRemuneracion();
  const [busqueda, setBusqueda] = useState('');
  const [tipo, setTipo] = useState('todos');

  useEffect(() => {
    fetchConceptos();
  }, [fetchConceptos]);

  const filtrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    return conceptos.filter((concepto) => {
      const coincideTexto =
        !texto ||
        concepto.codigo.toLowerCase().includes(texto) ||
        concepto.nombre.toLowerCase().includes(texto);
      const coincideTipo = tipo === 'todos' || concepto.tipo === tipo;
      return coincideTexto && coincideTipo;
    });
  }, [conceptos, busqueda, tipo]);

  const columns = [
    { title: 'Código', dataIndex: 'codigo', render: (codigo) => <code className="text-xs">{codigo}</code> },
    { title: 'Nombre', dataIndex: 'nombre' },
    {
      title: 'Tipo',
      dataIndex: 'tipo',
      render: (valor) => <Tag color={TIPO_COLOR[valor]}>{valor}</Tag>,
    },
    {
      title: 'Remunerativo',
      dataIndex: 'es_remunerativo_laboral',
      render: (valor) => (valor ? <Tag color="green">Sí</Tag> : <Tag>No</Tag>),
    },
    {
      title: 'Afecta Renta 5ta',
      dataIndex: 'afecta_renta_5ta',
      render: (valor) => (valor ? <Tag color="green">Sí</Tag> : <Tag>No</Tag>),
    },
  ];

  return (
    <div>
      <p className="mb-4 text-sm text-gray-500">
        Conceptos de ingreso, descuento y aportación con sus banderas de afectación. Solo lectura.
      </p>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search
          allowClear
          placeholder="Buscar por código o nombre..."
          className="w-64"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
        />
        <Select value={tipo} onChange={setTipo} className="w-44" options={TIPO_OPTIONS} />
        <span className="text-sm text-gray-500">{filtrados.length} concepto(s)</span>
      </div>

      <Table
        rowKey="id"
        loading={loading}
        columns={columns}
        dataSource={filtrados}
        pagination={{ pageSize: 10 }}
      />
    </div>
  );
}
