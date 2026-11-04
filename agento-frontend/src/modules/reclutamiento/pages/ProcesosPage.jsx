import { Input, Progress, Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import { ESTADOS_PROCESO } from '../constants/seleccion';
import { useProcesos } from '../hooks/useSeleccion';

export function ProgresoPlazas({ cubiertas = 0, total = 0 }) {
  return (
    <div className="min-w-32">
      <Progress percent={total ? Math.round((cubiertas / total) * 100) : 0} size="small" showInfo={false} className="mb-0!" />
      <span className="text-xs text-gray-600">{cubiertas} de {total} cubiertas</span>
    </div>
  );
}

export default function ProcesosPage({ onAbrirProceso }) {
  const [estado, setEstado] = useState();
  const [busqueda, setBusqueda] = useState('');
  const [pagina, setPagina] = useState(1);
  const filtros = useMemo(() => ({ estado, busqueda: busqueda || undefined, page: pagina, per_page: 15 }), [estado, busqueda, pagina]);
  const { datos, cargando, error, recargar } = useProcesos(filtros);

  const columnas = [
    { title: 'Código', dataIndex: 'codigo', render: (c) => <span className="font-mono text-xs font-semibold text-agento-blue-bright">{c}</span> },
    {
      title: 'Cargo',
      dataIndex: 'cargo',
      render: (cargo, p) => (
        <div>
          <span className="font-medium text-gray-900">{cargo}</span>
          <p className="text-xs text-gray-500">{p.area?.nombre} · {p.reclutador?.nombre ?? 'Sin reclutador'}</p>
        </div>
      ),
    },
    { title: 'Plazas', render: (_, p) => <ProgresoPlazas cubiertas={p.plazas_cubiertas} total={p.plazas_total} /> },
    { title: 'Candidatos activos', dataIndex: 'postulaciones_activas', align: 'center' },
    {
      title: 'Estado',
      render: (_, p) => (
        <div className="flex flex-wrap gap-1">
          <Tag color={ESTADOS_PROCESO[p.estado]?.color}>{ESTADOS_PROCESO[p.estado]?.label}</Tag>
          {p.publicacion.externa && <Tag>Pública</Tag>}
          {p.publicacion.interna && <Tag>Interna</Tag>}
          {p.atrasado && <Tag color="red">Atrasado</Tag>}
        </div>
      ),
    },
    { title: 'Fecha requerida', dataIndex: 'fecha_requerida', render: (f) => dayjs(f).format('DD/MM/YYYY') },
  ];

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Procesos de selección</h2>
        <p className="text-sm text-gray-500">Cada solicitud aceptada genera un proceso con sus plazas.</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Input.Search placeholder="Buscar por cargo o código" allowClear className="max-w-xs" onSearch={(v) => { setPagina(1); setBusqueda(v); }} />
        <Select
          allowClear
          placeholder="Estado"
          className="min-w-40"
          value={estado}
          onChange={(v) => { setPagina(1); setEstado(v); }}
          options={Object.entries(ESTADOS_PROCESO).map(([value, { label }]) => ({ value, label }))}
        />
      </div>
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        <Table
          rowKey="id"
          loading={cargando}
          columns={columnas}
          dataSource={datos?.data ?? []}
          scroll={{ x: 900 }}
          locale={{ emptyText: 'Aún no hay procesos. Se crean al aceptar una solicitud.' }}
          onRow={(p) => ({
            onClick: () => onAbrirProceso(p.id),
            onKeyDown: (e) => { if (e.key === 'Enter') onAbrirProceso(p.id); },
            tabIndex: 0,
            className: 'cursor-pointer',
          })}
          pagination={{ current: datos?.meta?.current_page ?? 1, pageSize: 15, total: datos?.meta?.total ?? 0, onChange: setPagina, showSizeChanger: false }}
        />
      </EstadoSeccion>
    </div>
  );
}
