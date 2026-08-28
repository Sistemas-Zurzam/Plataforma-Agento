import { App, Button, Input, Select, Table, Tag, Tooltip } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { useSunatCatalogos } from '../../hooks/useSunatCatalogos';
import { ESTADO_COLORS, ESTADO_FILTRO_OPTIONS, ESTADO_LABELS } from '../../utils/sunatEstados';
import EditarMapeoModal from './EditarMapeoModal';

/**
 * Tabs "Tipos de Documento", "Tipos de Trabajador", "Régimenes" y
 * "Comprobantes RH" comparten exactamente la misma forma de datos
 * (clave_interna fija de Agento + equivalencia SUNAT), así que reutilizan
 * este mismo componente parametrizado por `tipo` en vez de 4 pantallas
 * casi idénticas. El estado (configurado/requiere_configuracion/bloqueado_
 * por_modelo/no_aplica) lo calcula el backend — acá solo se renderiza.
 */
export default function MapeoGenericoTab({ user, tipo, etiquetas, tablaSunat, subtitulo }) {
  const { message } = App.useApp();
  const { mapeos, mapeosLoading, fetchMapeos, actualizarMapeo } = useSunatCatalogos();
  const [busqueda, setBusqueda] = useState('');
  const [estadoFiltro, setEstadoFiltro] = useState('todos');
  const [editando, setEditando] = useState(null);
  const [guardando, setGuardando] = useState(false);
  const puedeEditar = user?.permisos?.includes('parametros_laborales.editar');

  useEffect(() => {
    fetchMapeos(tipo);
  }, [tipo, fetchMapeos]);

  const filtrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    return mapeos.filter((m) => {
      const coincideEstado = estadoFiltro === 'todos' || m.estado === estadoFiltro;
      const coincideTexto = !texto ||
        m.clave_interna.toLowerCase().includes(texto) ||
        (etiquetas[m.clave_interna] ?? '').toLowerCase().includes(texto) ||
        (m.codigo_sunat ?? '').toLowerCase().includes(texto) ||
        (m.descripcion_sunat ?? '').toLowerCase().includes(texto);
      return coincideEstado && coincideTexto;
    });
  }, [mapeos, busqueda, estadoFiltro, etiquetas]);

  const resumen = useMemo(() => {
    const contar = (estado) => mapeos.filter((m) => m.estado === estado).length;
    const partes = [
      contar('configurado') && `${contar('configurado')} configurado(s)`,
      contar('requiere_configuracion') && `${contar('requiere_configuracion')} requiere(n) configuración`,
      contar('bloqueado_por_modelo') && `${contar('bloqueado_por_modelo')} bloqueado(s) por modelo`,
      contar('no_aplica') && `${contar('no_aplica')} no aplica(n)`,
    ].filter(Boolean);
    return partes.join(' · ');
  }, [mapeos]);

  const guardar = async (mapeoId, valores) => {
    setGuardando(true);
    try {
      await actualizarMapeo(mapeoId, valores);
      message.success('Mapeo actualizado');
      setEditando(null);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo actualizar el mapeo');
    } finally {
      setGuardando(false);
    }
  };

  const columns = [
    {
      title: 'Código interno Agento',
      dataIndex: 'clave_interna',
      render: (valor) => <code className="text-xs">{valor}</code>,
    },
    {
      title: 'Nombre',
      key: 'nombre',
      render: (_, m) => etiquetas[m.clave_interna] ?? m.clave_interna,
    },
    {
      title: 'Código SUNAT',
      dataIndex: 'codigo_sunat',
      render: (valor) => (valor ? <code className="text-xs">{valor}</code> : <span className="text-gray-300">—</span>),
    },
    { title: 'Descripción SUNAT', dataIndex: 'descripcion_sunat', render: (v) => v ?? <span className="text-gray-300">—</span> },
    {
      title: 'Estado',
      key: 'estado',
      render: (_, m) => <Tag color={ESTADO_COLORS[m.estado]}>{ESTADO_LABELS[m.estado]}</Tag>,
    },
    {
      title: 'Motivo',
      dataIndex: 'motivo_estado',
      render: (v) => (v ? <Tooltip title={v}><span className="line-clamp-1 max-w-xs cursor-help text-xs text-gray-500">{v}</span></Tooltip> : <span className="text-gray-300">—</span>),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      render: (_, m) => (
        <Tooltip title={puedeEditar ? 'Editar mapeo' : 'Sin permiso para editar'}>
          <Button size="small" onClick={() => setEditando(m)} disabled={!puedeEditar}>
            Editar mapeo
          </Button>
        </Tooltip>
      ),
    },
  ];

  return (
    <div>
      <p className="mb-1 text-sm text-gray-500">
        {subtitulo} Referencia: {tablaSunat}.
      </p>
      {resumen && <p className="mb-4 text-xs text-gray-400">{resumen}</p>}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search
          allowClear
          placeholder="Buscar por código, nombre o descripción..."
          className="w-72"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
        />
        <Select value={estadoFiltro} onChange={setEstadoFiltro} className="w-56" options={ESTADO_FILTRO_OPTIONS} />
      </div>
      <Table
        rowKey="id"
        loading={mapeosLoading}
        columns={columns}
        dataSource={filtrados}
        pagination={false}
        scroll={{ x: 900 }}
      />
      <EditarMapeoModal
        open={!!editando}
        mapeo={editando}
        submitting={guardando}
        onGuardar={guardar}
        onCancel={() => setEditando(null)}
      />
    </div>
  );
}
