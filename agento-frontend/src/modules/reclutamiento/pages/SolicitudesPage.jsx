import { PlusOutlined } from '@ant-design/icons';
import { App, Button, Descriptions, Dropdown, Input, Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import MotivoModal from '../components/MotivoModal';
import SolicitudFormModal from '../components/SolicitudFormModal';
import { ESTADOS_SOLICITUD, MODALIDADES, MOTIVOS_SOLICITUD, TIPOS_CONTRATO, etiquetaDe, mensajeDeError, tiene } from '../constants/seleccion';
import { useReferenciasSolicitud, useSolicitudes, useUsuariosSeleccion } from '../hooks/useSeleccion';

export default function SolicitudesPage({ user, onAbrirProceso }) {
  const { message } = App.useApp();
  const [estado, setEstado] = useState();
  const [busqueda, setBusqueda] = useState('');
  const [pagina, setPagina] = useState(1);
  const filtros = useMemo(() => ({ estado, busqueda: busqueda || undefined, page: pagina, per_page: 15 }), [estado, busqueda, pagina]);
  const { datos, cargando, error, recargar, acciones } = useSolicitudes(filtros);
  const puedeCrear = tiene(user, 'seleccion.solicitudes.crear');
  const puedeDecidir = tiene(user, 'seleccion.solicitudes.decidir');
  const puedeGestionar = puedeDecidir || tiene(user, 'seleccion.vacantes.gestionar');
  const referencias = useReferenciasSolicitud(true);
  const usuarios = useUsuariosSeleccion(puedeDecidir);

  const [formulario, setFormulario] = useState({ abierto: false, solicitud: null });
  const [decision, setDecision] = useState(null);
  const [cancelacion, setCancelacion] = useState(null);

  const ejecutar = async (accion, exito) => {
    try {
      await accion();
      message.success(exito);
      recargar();
      return true;
    } catch (e) {
      message.error(mensajeDeError(e));
      throw e;
    }
  };

  const abrirCancelacion = async (solicitud) => {
    try {
      const impacto = await acciones.impactoCancelacion(solicitud.id);
      setCancelacion({ solicitud, impacto });
    } catch (e) {
      message.error(mensajeDeError(e));
    }
  };

  const menuAcciones = (s) => {
    const propia = s.solicitante_user_id === user.id;
    const items = [];
    if (s.editable && (propia || puedeDecidir)) {
      items.push({ key: 'editar', label: 'Editar' });
      items.push({ key: 'enviar', label: 'Enviar a RR. HH.' });
    }
    if (s.estado === 'enviada' && puedeDecidir) {
      items.push({ key: 'aceptar', label: 'Aceptar' }, { key: 'devolver', label: 'Devolver' }, { key: 'rechazar', label: 'Rechazar', danger: true });
    }
    if (['borrador', 'devuelta', 'enviada'].includes(s.estado) && (propia || puedeGestionar)) {
      items.push({ key: 'cancelar', label: 'Cancelar', danger: true });
    }
    if (s.estado === 'aceptada' && puedeGestionar) {
      items.push({ key: 'cancelar', label: 'Cancelar solicitud aceptada', danger: true });
    }
    return items;
  };

  const alAccion = (s, key) => {
    if (key === 'editar') setFormulario({ abierto: true, solicitud: s });
    if (key === 'enviar') ejecutar(() => acciones.enviar(s.id), 'Solicitud enviada.').catch(() => {});
    if (['aceptar', 'devolver', 'rechazar'].includes(key)) setDecision({ solicitud: s, accion: key });
    if (key === 'cancelar') abrirCancelacion(s);
  };

  const columnas = [
    {
      title: 'Cargo',
      dataIndex: 'cargo',
      render: (cargo, s) => (
        <div>
          <span className="font-medium text-gray-900">{cargo}</span>
          {s.urgente && <Tag color="red" className="ml-2">Urgente</Tag>}
          <p className="text-xs text-gray-500">{s.area?.nombre} · {s.solicitante?.nombre}</p>
        </div>
      ),
    },
    { title: 'Motivo', dataIndex: 'motivo', render: (m) => etiquetaDe(MOTIVOS_SOLICITUD, m) },
    { title: 'Plazas', dataIndex: 'plazas', align: 'center' },
    { title: 'Fecha requerida', dataIndex: 'fecha_requerida', render: (f) => dayjs(f).format('DD/MM/YYYY') },
    { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS_SOLICITUD[e]?.color}>{ESTADOS_SOLICITUD[e]?.label ?? e}</Tag> },
    {
      title: 'Proceso',
      render: (_, s) => (s.proceso
        ? <Button type="link" className="px-0!" onClick={() => onAbrirProceso(s.proceso.id)}>{s.proceso.codigo}</Button>
        : '—'),
    },
    {
      title: '',
      width: 110,
      render: (_, s) => {
        const items = menuAcciones(s);
        return items.length ? (
          <Dropdown menu={{ items, onClick: ({ key }) => alAccion(s, key) }} trigger={['click']}>
            <Button size="small">Acciones</Button>
          </Dropdown>
        ) : null;
      },
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Solicitudes de personal</h2>
          <p className="text-sm text-gray-500">Borradores, envíos a RR. HH. y decisiones.</p>
        </div>
        {puedeCrear && (
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setFormulario({ abierto: true, solicitud: null })}>Nueva solicitud</Button>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        <Input.Search placeholder="Buscar por cargo" allowClear className="max-w-xs" onSearch={(v) => { setPagina(1); setBusqueda(v); }} />
        <Select
          allowClear
          placeholder="Estado"
          className="min-w-40"
          value={estado}
          onChange={(v) => { setPagina(1); setEstado(v); }}
          options={Object.entries(ESTADOS_SOLICITUD).map(([value, { label }]) => ({ value, label }))}
        />
      </div>

      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        <Table
          rowKey="id"
          loading={cargando}
          columns={columnas}
          dataSource={datos?.data ?? []}
          scroll={{ x: 900 }}
          locale={{ emptyText: 'No hay solicitudes' }}
          pagination={{ current: datos?.meta?.current_page ?? 1, pageSize: 15, total: datos?.meta?.total ?? 0, onChange: setPagina, showSizeChanger: false }}
          expandable={{
            expandedRowRender: (s) => (
              <Descriptions size="small" column={{ xs: 1, md: 2 }}>
                <Descriptions.Item label="Salario">S/ {s.salario_min} – {s.salario_max}</Descriptions.Item>
                <Descriptions.Item label="Contrato">{etiquetaDe(TIPOS_CONTRATO, s.tipo_contrato)} · {etiquetaDe(MODALIDADES, s.modalidad)}</Descriptions.Item>
                <Descriptions.Item label="Descripción" span={2}>{s.descripcion}</Descriptions.Item>
                {s.habilidades?.length > 0 && (
                  <Descriptions.Item label="Habilidades" span={2}>{s.habilidades.map((h) => `${h.nombre} (${h.nivel})`).join(', ')}</Descriptions.Item>
                )}
                {s.decision_motivo && <Descriptions.Item label="Motivo de la decisión" span={2}>{s.decision_motivo}</Descriptions.Item>}
                {s.cancelacion_motivo && <Descriptions.Item label="Motivo de cancelación" span={2}>{s.cancelacion_motivo}</Descriptions.Item>}
              </Descriptions>
            ),
          }}
        />
      </EstadoSeccion>

      {formulario.abierto && <SolicitudFormModal
        abierto={formulario.abierto}
        solicitud={formulario.solicitud}
        referencias={referencias.datos}
        onCerrar={() => setFormulario({ abierto: false, solicitud: null })}
        onGuardar={async (valores) => {
          if (formulario.solicitud) {
            await acciones.actualizar(formulario.solicitud.id, valores);
          } else {
            await acciones.crear(valores);
          }
          message.success(valores.enviar ? 'Solicitud enviada a RR. HH.' : 'Borrador guardado.');
          setFormulario({ abierto: false, solicitud: null });
          recargar();
        }}
      />}

      <MotivoModal
        abierto={Boolean(decision)}
        titulo={decision ? `${decision.accion === 'aceptar' ? 'Aceptar' : decision.accion === 'devolver' ? 'Devolver' : 'Rechazar'} solicitud` : ''}
        efecto={decision?.accion === 'aceptar'
          ? `Se creará el proceso de selección con ${decision.solicitud.plazas} plaza(s). Esta acción no se repite.`
          : decision?.accion === 'devolver'
            ? 'La solicitud vuelve al solicitante para que la corrija.'
            : 'La solicitud queda rechazada de forma definitiva.'}
        motivoObligatorio={decision?.accion !== 'aceptar'}
        etiquetaMotivo={decision?.accion === 'aceptar' ? 'Comentario (opcional)' : 'Motivo'}
        peligroso={decision?.accion === 'rechazar'}
        campoExtra={decision?.accion === 'aceptar' && usuarios.datos ? (
          <Select
            className="mb-4 w-full"
            placeholder="Reclutador responsable (por defecto, tú)"
            allowClear
            onChange={(v) => setDecision((d) => ({ ...d, reclutador: v }))}
            options={usuarios.datos.map((u) => ({ value: u.id, label: u.nombre }))}
          />
        ) : null}
        onCerrar={() => setDecision(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.decidir(decision.solicitud.id, {
            accion: decision.accion, motivo: motivo || undefined, reclutador_user_id: decision.reclutador,
          }), 'Decisión registrada.');
          setDecision(null);
        }}
      />

      <MotivoModal
        abierto={Boolean(cancelacion)}
        titulo="Cancelar solicitud"
        peligroso
        motivoObligatorio={cancelacion?.solicitud.estado === 'aceptada'}
        efecto={cancelacion?.solicitud.estado === 'aceptada'
          ? `Se cancelarán ${cancelacion.impacto.plazas_pendientes} plaza(s) pendiente(s), se retirará la publicación y ${cancelacion.impacto.postulaciones_activas} candidatura(s) activa(s) finalizarán por cancelación. Las ${cancelacion.impacto.plazas_cubiertas} contratación(es) ya realizadas no se revierten.`
          : 'La solicitud quedará cancelada.'}
        onCerrar={() => setCancelacion(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.cancelar(cancelacion.solicitud.id, motivo || undefined), 'Solicitud cancelada.');
          setCancelacion(null);
        }}
      />
    </div>
  );
}
