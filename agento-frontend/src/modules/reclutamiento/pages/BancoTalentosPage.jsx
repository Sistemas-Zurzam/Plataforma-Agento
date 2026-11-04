import { App, Button, Descriptions, Drawer, Input, Select, Space, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import { ESTADOS_POSTULACION, ETIQUETA_ETAPA, mensajeDeError } from '../constants/seleccion';
import { useBancoTalentos, useCandidatoBanco, useProcesos } from '../hooks/useSeleccion';

const FILTRO_ABIERTOS = { estado: 'abierto', per_page: 100 };

function FichaCandidato({ candidatoId, onCerrar, onCambio }) {
  const { message, modal } = App.useApp();
  const { datos: c, cargando, error, recargar, etiquetar, quitarEtiqueta, invitar } = useCandidatoBanco(candidatoId);
  const procesos = useProcesos(FILTRO_ABIERTOS);
  const [etiqueta, setEtiqueta] = useState('');
  const [procesoId, setProcesoId] = useState();

  const ejecutar = async (accion, exito) => {
    try {
      const r = await accion();
      message.success(exito);
      recargar();
      onCambio();
      return r;
    } catch (e) {
      message.error(mensajeDeError(e));
      return null;
    }
  };

  return (
    <Drawer open={Boolean(candidatoId)} onClose={onCerrar} width={Math.min(640, window.innerWidth)} title={c?.nombre ?? 'Candidato'}>
      <EstadoSeccion cargando={cargando && !c} error={error} onReintentar={recargar}>
        {c && (
          <div className="space-y-4">
            <Descriptions size="small" column={1} bordered>
              <Descriptions.Item label="Correo">{c.email}</Descriptions.Item>
              <Descriptions.Item label="Celular">{c.celular ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Ubicación">{c.ubicacion ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Consentimiento">{c.consentimiento ? `v${c.consentimiento.version} · ${dayjs(c.consentimiento.aceptado_at).format('DD/MM/YYYY')}` : '—'}</Descriptions.Item>
            </Descriptions>
            <div>
              <p className="mb-1 text-sm font-medium">Etiquetas</p>
              <div className="mb-2 flex flex-wrap gap-1">
                {c.etiquetas.map((e) => (
                  <Tag
                    key={e.id}
                    color={e.tipo === 'automatica' ? 'blue' : 'default'}
                    closable={e.tipo === 'manual'}
                    onClose={(ev) => {
                      ev.preventDefault();
                      ejecutar(() => quitarEtiqueta(c.id, e.id), 'Etiqueta quitada.');
                    }}
                  >
                    {e.nombre}
                  </Tag>
                ))}
              </div>
              <Space.Compact>
                <Input placeholder="Nueva etiqueta" value={etiqueta} maxLength={60} onChange={(e) => setEtiqueta(e.target.value)} aria-label="Nueva etiqueta" />
                <Button disabled={etiqueta.trim().length < 2} onClick={() => ejecutar(() => etiquetar(c.id, etiqueta), 'Etiqueta agregada.').then(() => setEtiqueta(''))}>Agregar</Button>
              </Space.Compact>
            </div>
            <div className="flex flex-wrap gap-1">
              {c.habilidades.map((h) => <Tag key={h.nombre}>{h.nombre}{h.nivel ? ` · ${h.nivel}` : ''}</Tag>)}
            </div>
            <div>
              <p className="mb-1 text-sm font-medium">Invitar a un proceso abierto</p>
              <Space.Compact className="w-full">
                <Select
                  className="w-full"
                  placeholder="Proceso"
                  value={procesoId}
                  onChange={setProcesoId}
                  options={(procesos.datos?.data ?? []).map((p) => ({ value: p.id, label: `${p.codigo} · ${p.cargo}` }))}
                />
                <Button
                  type="primary"
                  disabled={!procesoId}
                  onClick={async () => {
                    const r = await ejecutar(() => invitar(procesoId, { tipo: 'banco', candidato_id: c.id }), 'Invitación registrada.');
                    if (r && !r.correo_enviado) {
                      modal.info({ title: 'El correo no se pudo enviar', content: <Input.TextArea readOnly rows={2} value={r.enlace} /> });
                    }
                  }}
                >
                  Invitar
                </Button>
              </Space.Compact>
            </div>
            <div>
              <p className="mb-1 text-sm font-medium">Historial de participación</p>
              <Table
                size="small"
                rowKey="id"
                pagination={false}
                dataSource={c.postulaciones}
                scroll={{ x: 500 }}
                columns={[
                  { title: 'Proceso', render: (_, p) => `${p.proceso?.codigo} · ${p.proceso?.cargo}` },
                  { title: 'Etapa', render: (_, p) => ETIQUETA_ETAPA[p.etapa] },
                  { title: 'Estado', render: (_, p) => <Tag color={ESTADOS_POSTULACION[p.estado]?.color}>{ESTADOS_POSTULACION[p.estado]?.label}</Tag> },
                  { title: 'Fecha', render: (_, p) => dayjs(p.created_at).format('DD/MM/YYYY') },
                ]}
              />
            </div>
          </div>
        )}
      </EstadoSeccion>
    </Drawer>
  );
}

export default function BancoTalentosPage() {
  const [filtrosForm, setFiltrosForm] = useState({});
  const [pagina, setPagina] = useState(1);
  const [abierto, setAbierto] = useState(null);
  const filtros = useMemo(() => ({ ...filtrosForm, page: pagina, per_page: 20 }), [filtrosForm, pagina]);
  const { datos, cargando, error, recargar } = useBancoTalentos(filtros);
  const filtrar = (campo) => (valor) => {
    setPagina(1);
    setFiltrosForm((f) => ({ ...f, [campo]: valor || undefined }));
  };

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Banco de talentos</h2>
        <p className="text-sm text-gray-500">Candidatos de esta empresa. Los datos vencen según la retención configurada (por defecto 24 meses desde la última postulación).</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Input.Search placeholder="Nombre o correo" allowClear className="max-w-xs" onSearch={filtrar('busqueda')} />
        <Input.Search placeholder="Cargo postulado" allowClear className="max-w-xs" onSearch={filtrar('cargo')} />
        <Input.Search placeholder="Competencia" allowClear className="max-w-xs" onSearch={filtrar('competencia')} />
        <Select allowClear placeholder="Etiqueta" className="min-w-44" onChange={filtrar('etiqueta')} options={(datos?.etiquetas ?? []).map((e) => ({ value: e, label: e }))} />
      </div>
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        <Table
          rowKey="id"
          loading={cargando}
          dataSource={datos?.data ?? []}
          scroll={{ x: 800 }}
          locale={{ emptyText: 'Sin candidatos' }}
          pagination={{ current: datos?.meta?.current_page ?? 1, pageSize: 20, total: datos?.meta?.total ?? 0, onChange: setPagina, showSizeChanger: false }}
          columns={[
            { title: 'Candidato', render: (_, c) => <Button type="link" className="px-0!" onClick={() => setAbierto(c.id)}>{c.nombre}</Button> },
            { title: 'Habilidades', render: (_, c) => c.habilidades.slice(0, 4).map((h) => <Tag key={h.nombre}>{h.nombre}</Tag>) },
            { title: 'Etiquetas', render: (_, c) => c.etiquetas.map((e) => <Tag key={e.id} color={e.tipo === 'automatica' ? 'blue' : 'default'}>{e.nombre}</Tag>) },
            { title: 'Postulaciones', dataIndex: 'postulaciones_count', align: 'center' },
            { title: 'Última postulación', dataIndex: 'ultima_postulacion_at', render: (f) => (f ? dayjs(f).format('DD/MM/YYYY') : '—') },
          ]}
        />
      </EstadoSeccion>
      <FichaCandidato candidatoId={abierto} onCerrar={() => setAbierto(null)} onCambio={recargar} />
    </div>
  );
}
