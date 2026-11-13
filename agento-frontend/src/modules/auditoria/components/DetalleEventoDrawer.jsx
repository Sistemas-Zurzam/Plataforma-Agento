import { Descriptions, Drawer, Empty, Spin, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { formatearFecha, TIPOS_EVENTO } from '../constants/auditoria';

const EVENTOS_MODELO = {
  created: { texto: 'Creado', color: 'green' },
  updated: { texto: 'Modificado', color: 'blue' },
  deleted: { texto: 'Eliminado', color: 'red' },
};

function valorTexto(valor) {
  if (valor === null || valor === undefined) return '—';
  if (typeof valor === 'object') return JSON.stringify(valor);
  return String(valor);
}

function CambiosModelo({ cambios }) {
  if (!cambios?.registros?.length) {
    return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sin cambios en base de datos" />;
  }

  return (
    <div className="flex flex-col gap-4">
      {cambios.registros.map((registro, indice) => {
        const campos = Array.from(new Set([
          ...Object.keys(registro.antes ?? {}),
          ...Object.keys(registro.despues ?? {}),
        ]));
        const evento = EVENTOS_MODELO[registro.evento] ?? { texto: registro.evento, color: 'default' };

        return (
          <div key={`${registro.modelo}-${registro.id}-${indice}`}>
            <p className="mb-2 flex items-center gap-2 text-sm font-medium">
              {registro.modelo} #{registro.id}
              <Tag color={evento.color}>{evento.texto}</Tag>
            </p>
            <Table
              size="small"
              pagination={false}
              rowKey="campo"
              dataSource={campos.map((campo) => ({
                campo,
                antes: registro.antes?.[campo],
                despues: registro.despues?.[campo],
              }))}
              columns={[
                { title: 'Campo', dataIndex: 'campo', width: 160 },
                { title: 'Antes', dataIndex: 'antes', render: valorTexto },
                { title: 'Después', dataIndex: 'despues', render: valorTexto },
              ]}
            />
          </div>
        );
      })}
      {cambios.omitidos > 0 && (
        <p className="text-xs text-gray-500">
          Se omitieron {cambios.omitidos} cambios adicionales de esta operación.
        </p>
      )}
    </div>
  );
}

export default function DetalleEventoDrawer({ eventoId, onClose, fetchDetalle }) {
  const [evento, setEvento] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!eventoId) return undefined;

    let cancelado = false;
    setLoading(true);
    setEvento(null);
    fetchDetalle(eventoId)
      .then((data) => { if (!cancelado) setEvento(data); })
      .catch(() => {})
      .finally(() => { if (!cancelado) setLoading(false); });

    return () => { cancelado = true; };
  }, [eventoId, fetchDetalle]);

  const tipo = TIPOS_EVENTO[evento?.tipo];

  return (
    <Drawer
      title={`Evento de auditoría #${eventoId ?? ''}`}
      open={Boolean(eventoId)}
      onClose={onClose}
      size="large"
    >
      {loading && <div className="flex justify-center py-10"><Spin /></div>}
      {evento && (
        <div className="flex flex-col gap-6">
          <Descriptions
            size="small"
            column={1}
            bordered
            items={[
              { key: 'fecha', label: 'Fecha', children: formatearFecha(evento.fecha) },
              { key: 'usuario', label: 'Usuario', children: `${evento.usuario.nombre ?? '—'}${evento.usuario.rol ? ` (${evento.usuario.rol})` : ''}` },
              { key: 'tipo', label: 'Tipo', children: tipo ? <Tag color={tipo.color}>{tipo.texto}</Tag> : evento.tipo },
              { key: 'accion', label: 'Acción', children: evento.accion },
              { key: 'ruta', label: 'Ruta', children: evento.ruta ?? '—' },
              { key: 'resultado', label: 'Resultado', children: evento.status_code ?? '—' },
              { key: 'recurso', label: 'Recurso', children: evento.recurso_tipo ? `${evento.recurso_tipo} #${evento.recurso_id}` : '—' },
              { key: 'ip', label: 'IP', children: evento.ip ?? '—' },
              { key: 'duracion', label: 'Duración', children: evento.duracion_ms != null ? `${evento.duracion_ms} ms` : '—' },
              { key: 'agente', label: 'Navegador', children: <span className="text-xs break-all">{evento.user_agent ?? '—'}</span> },
            ]}
          />

          <section>
            <h4 className="mb-2 text-sm font-semibold">Cambios en datos</h4>
            <CambiosModelo cambios={evento.cambios} />
          </section>

          <section>
            <h4 className="mb-2 text-sm font-semibold">Datos enviados</h4>
            {evento.payload ? (
              <pre className="max-h-96 overflow-auto rounded-lg bg-gray-50 p-3 text-xs">
                {JSON.stringify(evento.payload, null, 2)}
              </pre>
            ) : (
              <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sin datos" />
            )}
          </section>
        </div>
      )}
    </Drawer>
  );
}
