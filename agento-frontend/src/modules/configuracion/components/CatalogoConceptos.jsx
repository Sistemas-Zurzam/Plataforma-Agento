import { App, Input, Select, Table, Tag, Tooltip } from 'antd';
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
 * Solo lectura para las banderas que alimentan directamente el motor de
 * cálculo de planilla (es_remunerativo_laboral, afecta_renta_5ta) — crear/
 * editarlas queda pendiente hasta definir las validaciones de negocio
 * correspondientes.
 *
 * El código PLAME sí es editable acá: a diferencia de esas banderas, no
 * afecta ningún monto calculado — es solo el código que el futuro
 * exportador PLAME usará para identificar este concepto ante SUNAT (Tabla
 * 22 del Anexo 3). Mientras no se cargue el catálogo oficial, queda vacío
 * y se marca "Pendiente SUNAT" en vez de inventarse un valor.
 */
export default function CatalogoConceptos({ user }) {
  const { message } = App.useApp();
  const { conceptos, loading, fetchConceptos, actualizarCodigoPlame } = useConceptosRemuneracion();
  const [busqueda, setBusqueda] = useState('');
  const [tipo, setTipo] = useState('todos');
  const [editandoId, setEditandoId] = useState(null);
  const [valorEditado, setValorEditado] = useState('');
  const [guardandoId, setGuardandoId] = useState(null);
  const puedeEditar = user?.permisos?.includes('parametros_laborales.editar');

  useEffect(() => {
    fetchConceptos();
  }, [fetchConceptos]);

  const iniciarEdicion = (concepto) => {
    setEditandoId(concepto.id);
    setValorEditado(concepto.codigo_plame ?? '');
  };

  const guardarCodigoPlame = async (conceptoId) => {
    const valor = valorEditado.trim();
    if (valor !== '' && !/^\d{1,4}$/.test(valor)) {
      message.error('El código PLAME debe tener hasta 4 dígitos numéricos (Tabla 22 de SUNAT) — se completa con ceros a la izquierda automáticamente.');
      return;
    }
    setGuardandoId(conceptoId);
    try {
      await actualizarCodigoPlame(conceptoId, valor || null);
      message.success('Código PLAME actualizado');
      setEditandoId(null);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo actualizar el código PLAME');
    } finally {
      setGuardandoId(null);
    }
  };

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
    {
      title: (
        <Tooltip title="Código de 4 dígitos que usará el futuro exportador PLAME para declarar este concepto a SUNAT (Tabla 22, Anexo 3).">
          Código PLAME
        </Tooltip>
      ),
      dataIndex: 'codigo_plame',
      render: (valor, concepto) => {
        if (editandoId === concepto.id) {
          return (
            <Input
              autoFocus
              size="small"
              className="w-24"
              maxLength={4}
              value={valorEditado}
              onChange={(e) => setValorEditado(e.target.value.replace(/\D/g, ''))}
              onPressEnter={() => guardarCodigoPlame(concepto.id)}
              onBlur={() => guardarCodigoPlame(concepto.id)}
              disabled={guardandoId === concepto.id}
            />
          );
        }
        return (
          <span
            className={puedeEditar ? 'cursor-pointer hover:underline' : ''}
            onClick={() => puedeEditar && iniciarEdicion(concepto)}
          >
            {valor ? <code className="text-xs">{valor}</code> : <Tag color="orange">Pendiente SUNAT</Tag>}
          </span>
        );
      },
    },
  ];

  return (
    <div>
      <p className="mb-4 text-sm text-gray-500">
        Conceptos de ingreso, descuento y aportación con sus banderas de afectación
        {puedeEditar ? ' — haz clic en el código PLAME para completarlo o corregirlo.' : '. Solo lectura.'}
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
