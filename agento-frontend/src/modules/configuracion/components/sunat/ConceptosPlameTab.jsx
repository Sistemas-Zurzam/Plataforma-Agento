import { App, Input, Select, Table, Tag, Tooltip } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { useConceptosRemuneracion } from '../../hooks/useConceptosRemuneracion';
import { ESTADO_COLORS, ESTADO_FILTRO_OPTIONS, ESTADO_LABELS } from '../../utils/sunatEstados';
import ConceptoPlameModal from './ConceptoPlameModal';
import DefinicionesPlameModal from './DefinicionesPlameModal';
import HistorialCodigoPlameDrawer from './HistorialCodigoPlameDrawer';

const TIPO_OPTIONS = [
  { value: 'todos', label: 'Todos los tipos' },
  { value: 'ingreso', label: 'Ingreso' },
  { value: 'egreso', label: 'Egreso' },
  { value: 'aportacion', label: 'Aportación' },
];

// Demasiado genéricos para un único código PLAME — administran varias
// "definiciones" concretas (ver DefinicionesPlameModal) en vez de un código
// simple en conceptos_remuneracion.codigo_plame.
const CONCEPTOS_CON_DEFINICIONES = ['BONIFICACION', 'BONO_NO_REMUNERATIVO'];

const TIPO_COLOR = { ingreso: 'green', egreso: 'red', aportacion: 'blue' };

/**
 * Pestaña más importante de Catálogos SUNAT — administra
 * conceptos_remuneracion.codigo_plame (Tabla 22), reutilizando la misma
 * fuente que el Catálogo de Conceptos de Parámetros Remunerativos. Cada
 * edición queda registrada con vigencia en concepto_codigos_plame (ver
 * historial), sin tocar boleta_conceptos.codigo_plame_snapshot de boletas
 * ya calculadas. El estado lo calcula el backend.
 */
export default function ConceptosPlameTab({ user }) {
  const { message } = App.useApp();
  const { conceptos, loading, fetchConceptos, actualizarCodigoPlame, fetchHistorialCodigoPlame } = useConceptosRemuneracion();
  const [busqueda, setBusqueda] = useState('');
  const [tipo, setTipo] = useState('todos');
  const [estadoFiltro, setEstadoFiltro] = useState('todos');
  const [editando, setEditando] = useState(null);
  const [definicionesDe, setDefinicionesDe] = useState(null);
  const [historialDe, setHistorialDe] = useState(null);
  const [guardando, setGuardando] = useState(false);
  const puedeEditar = user?.permisos?.includes('parametros_laborales.editar');

  useEffect(() => {
    fetchConceptos();
  }, [fetchConceptos]);

  const filtrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    return conceptos.filter((c) => {
      const coincideTexto = !texto ||
        c.codigo.toLowerCase().includes(texto) ||
        c.nombre.toLowerCase().includes(texto) ||
        (c.codigo_plame ?? '').toLowerCase().includes(texto) ||
        (c.sunat_motivo_estado ?? '').toLowerCase().includes(texto);
      const coincideTipo = tipo === 'todos' || c.tipo === tipo;
      const coincideEstado = estadoFiltro === 'todos' || c.estado === estadoFiltro;
      return coincideTexto && coincideTipo && coincideEstado;
    });
  }, [conceptos, busqueda, tipo, estadoFiltro]);

  const resumen = useMemo(() => {
    const contar = (estado) => conceptos.filter((c) => c.estado === estado).length;
    return [
      contar('configurado') && `${contar('configurado')} configurado(s)`,
      contar('requiere_configuracion') && `${contar('requiere_configuracion')} requiere(n) configuración`,
      contar('bloqueado_por_modelo') && `${contar('bloqueado_por_modelo')} bloqueado(s) por modelo`,
      contar('no_aplica') && `${contar('no_aplica')} no aplica(n)`,
    ].filter(Boolean).join(' · ');
  }, [conceptos]);

  const guardar = async (conceptoId, valores) => {
    setGuardando(true);
    try {
      await actualizarCodigoPlame(conceptoId, valores);
      message.success('Código PLAME actualizado');
      setEditando(null);
    } catch (err) {
      message.error(err.response?.data?.errors?.vigencia_desde?.[0] ?? err.response?.data?.message ?? 'No se pudo actualizar el código PLAME');
    } finally {
      setGuardando(false);
    }
  };

  const columns = [
    { title: 'Concepto', dataIndex: 'nombre' },
    { title: 'Código interno', dataIndex: 'codigo', render: (v) => <code className="text-xs">{v}</code> },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <Tag color={TIPO_COLOR[v]}>{v}</Tag> },
    {
      title: 'Código PLAME',
      dataIndex: 'codigo_plame',
      render: (v) => (v ? <code className="text-xs">{v}</code> : <span className="text-gray-300">—</span>),
    },
    {
      title: 'Estado',
      key: 'estado',
      render: (_, c) => <Tag color={ESTADO_COLORS[c.estado]}>{ESTADO_LABELS[c.estado]}</Tag>,
    },
    {
      title: 'Motivo',
      dataIndex: 'sunat_motivo_estado',
      render: (v) => (v ? <Tooltip title={v}><span className="line-clamp-1 max-w-xs cursor-help text-xs text-gray-500">{v}</span></Tooltip> : <span className="text-gray-300">—</span>),
    },
    {
      title: 'Acción',
      key: 'acciones',
      render: (_, c) => (
        <div className="flex gap-2">
          {c.estado !== 'no_aplica' && CONCEPTOS_CON_DEFINICIONES.includes(c.codigo) && (
            <Tooltip title={puedeEditar ? 'Administrar definiciones PLAME' : 'Sin permiso para editar'}>
              <a onClick={() => puedeEditar && setDefinicionesDe(c)} className={!puedeEditar ? 'pointer-events-none text-gray-300' : ''}>
                Configurar clasificación
              </a>
            </Tooltip>
          )}
          {c.estado !== 'no_aplica' && !CONCEPTOS_CON_DEFINICIONES.includes(c.codigo) && (
            <Tooltip title={puedeEditar ? 'Configurar código PLAME' : 'Sin permiso para editar'}>
              <a onClick={() => puedeEditar && setEditando(c)} className={!puedeEditar ? 'pointer-events-none text-gray-300' : ''}>
                Configurar
              </a>
            </Tooltip>
          )}
          <a onClick={() => setHistorialDe(c)}>Historial</a>
        </div>
      ),
    },
  ];

  return (
    <div>
      <p className="mb-1 text-sm text-gray-500">
        Conceptos de ingreso, egreso y aportación con su código PLAME (Tabla 22, Anexo 2 SUNAT). Las boletas ya calculadas conservan su propio código histórico.
      </p>
      {resumen && <p className="mb-4 text-xs text-gray-400">{resumen}</p>}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search allowClear placeholder="Buscar por código, nombre o descripción..." className="w-72" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} />
        <Select value={tipo} onChange={setTipo} className="w-44" options={TIPO_OPTIONS} />
        <Select value={estadoFiltro} onChange={setEstadoFiltro} className="w-56" options={ESTADO_FILTRO_OPTIONS} />
        <span className="text-sm text-gray-500">{filtrados.length} concepto(s)</span>
      </div>
      <Table rowKey="id" loading={loading} columns={columns} dataSource={filtrados} pagination={{ pageSize: 10 }} scroll={{ x: 900 }} />

      <ConceptoPlameModal open={!!editando} concepto={editando} submitting={guardando} onGuardar={guardar} onCancel={() => setEditando(null)} />
      <DefinicionesPlameModal open={!!definicionesDe} concepto={definicionesDe} onCancel={() => setDefinicionesDe(null)} />
      <HistorialCodigoPlameDrawer
        open={!!historialDe}
        concepto={historialDe}
        fetchHistorial={fetchHistorialCodigoPlame}
        onClose={() => setHistorialDe(null)}
      />
    </div>
  );
}
