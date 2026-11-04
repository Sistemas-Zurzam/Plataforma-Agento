import { Button, Input, Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import PostulacionDrawer from '../components/PostulacionDrawer';
import { ESTADOS_POSTULACION, ETAPAS, ETIQUETA_ETAPA, FUENTES, RESULTADOS_ATS } from '../constants/seleccion';
import { usePostulaciones } from '../hooks/useSeleccion';

/**
 * Todas las candidaturas de la empresa activa, con búsqueda, filtros y
 * paginación resueltos en el backend.
 */
export default function CandidatosPage({ user, onAbrirProceso }) {
  const [busqueda, setBusqueda] = useState('');
  const [etapa, setEtapa] = useState();
  const [estado, setEstado] = useState('activa');
  const [fuente, setFuente] = useState();
  const [pagina, setPagina] = useState(1);
  const [abierta, setAbierta] = useState(null);
  const filtros = useMemo(() => ({ busqueda: busqueda || undefined, etapa, estado, fuente, page: pagina, per_page: 20 }), [busqueda, etapa, estado, fuente, pagina]);
  const { datos, cargando, error, recargar } = usePostulaciones(filtros);
  const reiniciar = (setter) => (valor) => { setPagina(1); setter(valor); };

  const columnas = [
    {
      title: 'Candidato',
      render: (_, p) => (
        <div>
          <Button type="link" className="h-auto! px-0! font-medium" onClick={() => setAbierta(p.id)}>{p.candidato?.nombre}</Button>
          <p className="text-xs text-gray-500">{p.candidato?.email}</p>
        </div>
      ),
    },
    { title: 'Proceso', render: (_, p) => <Button type="link" className="px-0!" onClick={() => onAbrirProceso(p.proceso.id)}>{p.proceso?.codigo} · {p.proceso?.cargo}</Button> },
    { title: 'Etapa', dataIndex: 'etapa', render: (e, p) => <span>{ETIQUETA_ETAPA[e]} <span className="text-xs text-gray-400">({p.dias_en_etapa} d)</span></span> },
    { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS_POSTULACION[e]?.color}>{ESTADOS_POSTULACION[e]?.label}</Tag> },
    { title: 'ATS', render: (_, p) => (p.ats ? <Tag color={RESULTADOS_ATS[p.ats.resultado]?.color}>{p.ats.puntaje ?? '—'}</Tag> : '—') },
    { title: 'Siguiente acción', dataIndex: 'siguiente_accion' },
    { title: 'Postuló', dataIndex: 'created_at', render: (f) => dayjs(f).format('DD/MM/YYYY') },
  ];

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Candidatos</h2>
        <p className="text-sm text-gray-500">Candidaturas de todos los procesos de la empresa.</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Input.Search placeholder="Nombre, correo o documento" allowClear className="max-w-xs" onSearch={reiniciar(setBusqueda)} />
        <Select allowClear placeholder="Etapa" className="min-w-44" value={etapa} onChange={reiniciar(setEtapa)} options={ETAPAS} />
        <Select allowClear placeholder="Estado" className="min-w-44" value={estado} onChange={reiniciar(setEstado)}
          options={Object.entries(ESTADOS_POSTULACION).map(([value, { label }]) => ({ value, label }))} />
        <Select allowClear placeholder="Fuente" className="min-w-44" value={fuente} onChange={reiniciar(setFuente)}
          options={Object.entries(FUENTES).map(([value, label]) => ({ value, label }))} />
      </div>
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        <Table
          rowKey="id"
          loading={cargando}
          columns={columnas}
          dataSource={datos?.data ?? []}
          scroll={{ x: 1000 }}
          locale={{ emptyText: 'No hay candidaturas con estos filtros' }}
          pagination={{ current: datos?.meta?.current_page ?? 1, pageSize: 20, total: datos?.meta?.total ?? 0, onChange: setPagina, showSizeChanger: false }}
        />
      </EstadoSeccion>
      <PostulacionDrawer user={user} postulacionId={abierta} onCerrar={() => setAbierta(null)} onCambio={recargar} />
    </div>
  );
}
