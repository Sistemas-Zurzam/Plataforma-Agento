import { ArrowLeftOutlined, CopyOutlined } from '@ant-design/icons';
import {
  App, Button, Card, Checkbox, DatePicker, Descriptions, Dropdown, Form, Input, Modal, Segmented, Select, Space, Switch, Table, Tabs, Tag, Timeline,
} from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import MotivoModal from '../components/MotivoModal';
import PerfilProcesoForm from '../components/PerfilProcesoForm';
import PostulacionDrawer from '../components/PostulacionDrawer';
import TableroPostulaciones from '../components/TableroPostulaciones';
import {
  CAUSAS_DESCARTE, ESTADOS_PLAZA, ESTADOS_POSTULACION, ESTADOS_PROCESO, ETAPAS, ETIQUETA_ETAPA, MODALIDADES, RESULTADOS_ATS, TIPOS_CONTRATO, etiquetaDe, mensajeDeError, tiene,
} from '../constants/seleccion';
import {
  useActividadProceso, useCoincidencias, usePostulaciones, useProcesoDetalle, useTablero, useUsuariosSeleccion,
} from '../hooks/useSeleccion';
import { ProgresoPlazas } from './ProcesosPage';

function Resumen({ proceso, porEtapa, editable, usuarios, onActualizar }) {
  const [form] = Form.useForm();
  const [editando, setEditando] = useState(false);

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card size="small" title="Progreso de plazas">
          <ProgresoPlazas cubiertas={proceso.plazas_cubiertas} total={proceso.plazas_total} />
        </Card>
        <Card size="small" title="Candidatos vigentes por etapa" className="lg:col-span-2">
          <div className="flex flex-wrap gap-2">
            {ETAPAS.map((e) => <Tag key={e.value}>{e.label}: {porEtapa?.[e.value] ?? 0}</Tag>)}
          </div>
        </Card>
      </div>
      <Card
        size="small"
        title="Datos del proceso"
        extra={editable && !editando && <Button size="small" onClick={() => {
          form.setFieldsValue({ ...proceso, reclutador_user_id: proceso.reclutador?.id, fecha_cierre_postulacion: proceso.fecha_cierre_postulacion ? dayjs(proceso.fecha_cierre_postulacion) : null });
          setEditando(true);
        }}>Editar</Button>}
      >
        {editando ? (
          <Form form={form} layout="vertical" onFinish={async (v) => {
            await onActualizar({ ...v, fecha_cierre_postulacion: v.fecha_cierre_postulacion?.format('YYYY-MM-DD') ?? null });
            setEditando(false);
          }}>
            <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
              <Form.Item name="reclutador_user_id" label="Reclutador responsable">
                <Select showSearch optionFilterProp="label" options={(usuarios ?? []).map((u) => ({ value: u.id, label: u.nombre }))} />
              </Form.Item>
              <Form.Item name="ubicacion" label="Ubicación">
                <Input maxLength={150} />
              </Form.Item>
              <Form.Item name="modalidad" label="Modalidad">
                <Select options={MODALIDADES} />
              </Form.Item>
              <Form.Item name="fecha_cierre_postulacion" label="Cierre de postulaciones">
                <DatePicker format="DD/MM/YYYY" className="w-full!" />
              </Form.Item>
              <Form.Item name="mostrar_salario" label="Mostrar salario en la publicación" valuePropName="checked">
                <Switch />
              </Form.Item>
              <Form.Item name="empresa_confidencial" label="Ocultar empresa (confidencial)" valuePropName="checked">
                <Switch />
              </Form.Item>
            </div>
            <Form.Item name="descripcion" label="Descripción pública" rules={[{ required: true, min: 30 }]}>
              <Input.TextArea rows={4} maxLength={4000} />
            </Form.Item>
            <Space>
              <Button type="primary" htmlType="submit">Guardar</Button>
              <Button onClick={() => setEditando(false)}>Cancelar</Button>
            </Space>
          </Form>
        ) : (
          <Descriptions size="small" column={{ xs: 1, md: 2 }}>
            <Descriptions.Item label="Área">{proceso.area?.nombre}</Descriptions.Item>
            <Descriptions.Item label="Reclutador">{proceso.reclutador?.nombre ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Contrato">{etiquetaDe(TIPOS_CONTRATO, proceso.tipo_contrato)} · {etiquetaDe(MODALIDADES, proceso.modalidad)}</Descriptions.Item>
            <Descriptions.Item label="Ubicación">{proceso.ubicacion ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Salario">S/ {proceso.salario_min} – {proceso.salario_max} {proceso.mostrar_salario ? '(visible)' : '(oculto)'}</Descriptions.Item>
            <Descriptions.Item label="Fecha requerida">{dayjs(proceso.fecha_requerida).format('DD/MM/YYYY')} {proceso.atrasado && <Tag color="red">Atrasado</Tag>}</Descriptions.Item>
            <Descriptions.Item label="Confidencial">{proceso.empresa_confidencial ? 'Sí' : 'No'}</Descriptions.Item>
            {proceso.motivo_estado && <Descriptions.Item label="Motivo interno">{proceso.motivo_estado}</Descriptions.Item>}
            {proceso.mensaje_publico && <Descriptions.Item label="Mensaje público">{proceso.mensaje_publico}</Descriptions.Item>}
            <Descriptions.Item label="Descripción" span={2}>{proceso.descripcion}</Descriptions.Item>
          </Descriptions>
        )}
      </Card>
      <Card size="small" title="Plazas">
        <Table
          size="small"
          rowKey="id"
          pagination={false}
          dataSource={proceso.plazas ?? []}
          scroll={{ x: 500 }}
          columns={[
            { title: 'N.°', dataIndex: 'numero', width: 60 },
            { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS_PLAZA[e]?.color}>{ESTADOS_PLAZA[e]?.label}</Tag> },
            { title: 'Contratado', render: (_, p) => p.colaborador ? `${p.colaborador.nombre} (${p.colaborador.legajo})` : p.candidato ?? '—' },
            { title: 'Fecha', render: (_, p) => (p.cubierta_at ? dayjs(p.cubierta_at).format('DD/MM/YYYY') : p.cancelada_at ? dayjs(p.cancelada_at).format('DD/MM/YYYY') : '—') },
          ]}
        />
      </Card>
    </div>
  );
}

function Candidatos({ user, proceso, onAbrir, version }) {
  const { message } = App.useApp();
  const [vista, setVista] = useState('tablero');
  const [busqueda, setBusqueda] = useState('');
  const [estado, setEstado] = useState();
  const [pagina, setPagina] = useState(1);
  const [seleccion, setSeleccion] = useState([]);
  const [masiva, setMasiva] = useState(null);
  const [resultadosMasivos, setResultadosMasivos] = useState(null);
  const filtrosTablero = useMemo(() => ({ busqueda: busqueda || undefined, v: version }), [busqueda, version]);
  const filtrosTabla = useMemo(() => ({ proceso_id: proceso.id, busqueda: busqueda || undefined, estado, page: pagina, per_page: 20, v: version }), [proceso.id, busqueda, estado, pagina, version]);
  const tablero = useTablero(proceso.id, filtrosTablero, vista === 'tablero');
  const tabla = usePostulaciones(filtrosTabla, vista === 'tabla');
  const gestiona = tiene(user, 'seleccion.candidatos.gestionar');

  const recargar = () => (vista === 'tablero' ? tablero.recargar() : tabla.recargar());

  const mover = async (postulacion, etapa) => {
    try {
      await tabla.acciones.mover(postulacion.id, { etapa });
      message.success(`${postulacion.candidato?.nombre} movido a ${ETIQUETA_ETAPA[etapa]}.`);
      recargar();
    } catch (e) {
      if (e?.response?.data?.errors?.estado?.[0]?.includes('motivo')) {
        onAbrir(postulacion.id);
      }
      message.error(mensajeDeError(e));
    }
  };

  const columnas = [
    { title: 'Candidato', render: (_, p) => <span className="font-medium">{p.candidato?.nombre}</span> },
    { title: 'Etapa', dataIndex: 'etapa', render: (e) => ETIQUETA_ETAPA[e] },
    { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS_POSTULACION[e]?.color}>{ESTADOS_POSTULACION[e]?.label}</Tag> },
    { title: 'Días en etapa', dataIndex: 'dias_en_etapa', align: 'center' },
    { title: 'ATS', render: (_, p) => (p.ats ? <Tag color={RESULTADOS_ATS[p.ats.resultado]?.color}>{p.ats.puntaje ?? '—'}</Tag> : '—') },
    { title: 'Puntaje final', render: (_, p) => (p.puntaje_final?.puntaje != null ? `${p.puntaje_final.puntaje}${p.puntaje_final.provisional ? ' (prov.)' : ''}` : '—') },
    { title: 'Siguiente acción', dataIndex: 'siguiente_accion' },
  ];

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <Segmented value={vista} onChange={setVista} options={[{ value: 'tablero', label: 'Tablero' }, { value: 'tabla', label: 'Tabla' }]} />
        <Input.Search placeholder="Buscar candidato" allowClear className="max-w-xs" onSearch={(v) => { setPagina(1); setBusqueda(v); }} />
        {vista === 'tabla' && (
          <Select allowClear placeholder="Estado" className="min-w-40" value={estado} onChange={(v) => { setPagina(1); setEstado(v); }}
            options={Object.entries(ESTADOS_POSTULACION).map(([value, { label }]) => ({ value, label }))} />
        )}
        {vista === 'tabla' && gestiona && seleccion.length > 0 && (
          <Dropdown
            menu={{
              items: [
                ...ETAPAS.map((e) => ({ key: `mover:${e.value}`, label: `Mover a ${e.label}` })),
                { key: 'descartar', label: 'Descartar seleccionados', danger: true },
              ],
              onClick: ({ key }) => setMasiva(key),
            }}
          >
            <Button>Acción para {seleccion.length} seleccionado(s)</Button>
          </Dropdown>
        )}
      </div>

      {vista === 'tablero' ? (
        <EstadoSeccion cargando={tablero.cargando && !tablero.datos} error={tablero.error} onReintentar={tablero.recargar}>
          {tablero.datos && <TableroPostulaciones columnas={tablero.datos} onAbrir={onAbrir} onMover={gestiona ? mover : () => {}} />}
        </EstadoSeccion>
      ) : (
        <EstadoSeccion cargando={tabla.cargando && !tabla.datos} error={tabla.error} onReintentar={tabla.recargar}>
          <Table
            rowKey="id"
            loading={tabla.cargando}
            columns={columnas}
            dataSource={tabla.datos?.data ?? []}
            scroll={{ x: 900 }}
            rowSelection={gestiona ? { selectedRowKeys: seleccion, onChange: setSeleccion } : undefined}
            onRow={(p) => ({ onDoubleClick: () => onAbrir(p.id) })}
            locale={{ emptyText: 'Sin candidatos' }}
            pagination={{ current: tabla.datos?.meta?.current_page ?? 1, pageSize: 20, total: tabla.datos?.meta?.total ?? 0, onChange: setPagina, showSizeChanger: false }}
            expandable={{ expandedRowRender: (p) => <Button size="small" onClick={() => onAbrir(p.id)}>Abrir ficha</Button>, expandRowByClick: false }}
          />
        </EstadoSeccion>
      )}

      <MotivoModal
        abierto={Boolean(masiva)}
        titulo={masiva === 'descartar' ? 'Descartar candidaturas' : `Mover a ${ETIQUETA_ETAPA[masiva?.split(':')[1]] ?? ''}`}
        peligroso={masiva === 'descartar'}
        efecto="Se aplicará a cada candidatura por separado; verás el resultado individual."
        motivoObligatorio={masiva === 'descartar'}
        etiquetaMotivo={masiva === 'descartar' ? 'Motivo interno' : 'Motivo (obligatorio si retrocede de etapa)'}
        campoExtra={masiva === 'descartar' ? (
          <Form.Item name="causa" label="Causa" rules={[{ required: true }]}>
            <Select options={CAUSAS_DESCARTE} />
          </Form.Item>
        ) : null}
        onCerrar={() => setMasiva(null)}
        onConfirmar={async (v) => {
          try {
            const resultado = await tabla.acciones.accionMasiva(masiva === 'descartar'
              ? { ids: seleccion, accion: 'descartar', causa: v.causa, motivo: v.motivo }
              : { ids: seleccion, accion: 'mover', etapa: masiva.split(':')[1], motivo: v.motivo || undefined });
            setResultadosMasivos(resultado);
            setMasiva(null);
            setSeleccion([]);
            tabla.recargar();
          } catch (e) {
            message.error(mensajeDeError(e));
            throw e;
          }
        }}
      />
      <Modal open={Boolean(resultadosMasivos)} title="Resultado por candidatura" footer={null} onCancel={() => setResultadosMasivos(null)}>
        <ul className="space-y-1 text-sm">
          {(resultadosMasivos ?? []).map((r) => {
            const fila = tabla.datos?.data?.find((p) => p.id === r.id);
            return <li key={r.id}><Tag color={r.ok ? 'green' : 'red'}>{r.ok ? 'OK' : 'Error'}</Tag>{fila?.candidato?.nombre ?? `#${r.id}`}: {r.mensaje}</li>;
          })}
        </ul>
      </Modal>
    </div>
  );
}

function Evaluaciones({ proceso, puedeInvitar }) {
  const { message, modal } = App.useApp();
  const { datos, cargando, error, recargar, invitar } = useCoincidencias(proceso.id, puedeInvitar);
  const [email, setEmail] = useState('');

  const enviarInvitacion = async (valores) => {
    try {
      const r = await invitar(proceso.id, valores);
      if (r.correo_enviado) {
        message.success('Invitación enviada por correo.');
      } else {
        modal.info({ title: 'El correo no se pudo enviar', content: <div><p>Comparte este enlace personal:</p><Input.TextArea readOnly rows={2} value={r.enlace} /></div> });
      }
    } catch (e) {
      message.error(mensajeDeError(e));
    }
  };

  if (!puedeInvitar) {
    return <p className="text-sm text-gray-500">Las pruebas y entrevistas se gestionan desde la ficha de cada candidato.</p>;
  }

  return (
    <div className="space-y-4">
      <Card size="small" title="Publicación interna: invitar a un colaborador">
        <p className="mb-2 text-xs text-gray-500">Agento no tiene portal de colaboradores: la vacante interna se comparte con un enlace personal y vigente, nunca en el listado público.</p>
        <Space.Compact className="w-full max-w-md">
          <Input type="email" placeholder="correo@empresa.com" value={email} onChange={(e) => setEmail(e.target.value)} aria-label="Correo del colaborador" />
          <Button type="primary" disabled={!proceso.publicacion.interna || !email} onClick={() => enviarInvitacion({ tipo: 'interna', email }).then(() => setEmail(''))}>Invitar</Button>
        </Space.Compact>
        {!proceso.publicacion.interna && <p className="mt-1 text-xs text-orange-600">Registra primero la publicación interna.</p>}
      </Card>
      <Card size="small" title="Coincidencias en el banco de talentos (empresa activa)">
        <EstadoSeccion cargando={cargando} error={error} vacio={(datos ?? []).length === 0} mensajeVacio="Sin coincidencias explicables" onReintentar={recargar}>
          <Table
            size="small"
            rowKey={(c) => c.candidato.id}
            pagination={false}
            dataSource={datos ?? []}
            scroll={{ x: 600 }}
            columns={[
              { title: 'Candidato', render: (_, c) => c.candidato.nombre },
              { title: 'Puntaje de búsqueda', dataIndex: 'puntaje' },
              { title: 'Por qué coincide', render: (_, c) => <ul className="list-disc pl-4 text-xs">{c.razones.map((r) => <li key={r}>{r}</li>)}</ul> },
              {
                title: '',
                render: (_, c) => (
                  <Button size="small" disabled={proceso.estado !== 'abierto'} onClick={() => enviarInvitacion({ tipo: 'banco', candidato_id: c.candidato.id })}>Invitar</Button>
                ),
              },
            ]}
          />
        </EstadoSeccion>
      </Card>
    </div>
  );
}

function Actividad({ procesoId }) {
  const { datos, cargando, error, recargar } = useActividadProceso(procesoId);
  return (
    <EstadoSeccion cargando={cargando} error={error} vacio={(datos ?? []).length === 0} onReintentar={recargar}>
      <Timeline
        items={(datos ?? []).map((a) => ({
          children: (
            <div className="text-sm">
              <p className="font-medium">{a.accion.replaceAll('_', ' ')}</p>
              <p className="text-xs text-gray-500">{a.actor} · {dayjs(a.created_at).format('DD/MM/YYYY HH:mm')}</p>
              {a.motivo && <p className="text-xs text-gray-600">Motivo: {a.motivo}</p>}
            </div>
          ),
        }))}
      />
    </EstadoSeccion>
  );
}

export default function ProcesoDetallePage({ user, procesoId, onVolver }) {
  const { message } = App.useApp();
  const { datos, cargando, error, recargar, acciones } = useProcesoDetalle(procesoId);
  const gestiona = tiene(user, 'seleccion.vacantes.gestionar');
  const veCandidatos = tiene(user, 'seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar');
  const usuarios = useUsuariosSeleccion(gestiona);
  const [postulacionAbierta, setPostulacionAbierta] = useState(null);
  const [accionEstado, setAccionEstado] = useState(null);
  const [publicar, setPublicar] = useState(null);
  const [version, setVersion] = useState(0);

  const proceso = datos?.data;
  const finalizado = proceso && ['cerrado', 'cancelado'].includes(proceso.estado);

  const ejecutar = async (accion, exito) => {
    try {
      await accion();
      message.success(exito);
      recargar();
    } catch (e) {
      message.error(mensajeDeError(e));
      throw e;
    }
  };

  const enlacePublico = proceso ? `${window.location.origin}/empleos/${proceso.codigo}` : '';

  const menuEstado = proceso && gestiona ? [
    ...(['borrador', 'abierto', 'en_pausa'].includes(proceso.estado) ? [{ key: 'publicar', label: 'Publicar / ampliar publicación' }] : []),
    ...(proceso.estado === 'abierto' ? [{ key: 'pausar', label: 'Pausar' }] : []),
    ...(proceso.estado === 'en_pausa' ? [{ key: 'reanudar', label: 'Reanudar' }] : []),
    ...(['abierto', 'en_pausa'].includes(proceso.estado) ? [{ key: 'cerrar', label: 'Cerrar proceso', danger: true }] : []),
    ...((proceso.publicacion.externa || proceso.publicacion.interna) && !finalizado ? [{ key: 'retirar', label: 'Retirar publicación', danger: true }] : []),
  ] : [];

  return (
    <div className="space-y-4">
      <Button type="link" icon={<ArrowLeftOutlined />} className="px-0!" onClick={onVolver}>Procesos</Button>
      <EstadoSeccion cargando={cargando && !proceso} error={error} onReintentar={recargar}>
        {proceso && (
          <>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-mono text-xs font-semibold text-agento-blue-bright">{proceso.codigo}</p>
                <h2 className="text-lg font-semibold text-gray-900">{proceso.cargo}</h2>
                <div className="mt-1 flex flex-wrap gap-1">
                  <Tag color={ESTADOS_PROCESO[proceso.estado]?.color}>{ESTADOS_PROCESO[proceso.estado]?.label}</Tag>
                  <Tag color={proceso.publicacion.externa ? 'blue' : 'default'}>{proceso.publicacion.externa ? 'Publicación externa' : 'Sin publicación externa'}</Tag>
                  <Tag color={proceso.publicacion.interna ? 'blue' : 'default'}>{proceso.publicacion.interna ? 'Publicación interna' : 'Sin publicación interna'}</Tag>
                </div>
              </div>
              <Space wrap>
                {proceso.publicacion.externa && (
                  <Button icon={<CopyOutlined />} onClick={() => navigator.clipboard?.writeText(enlacePublico).then(() => message.success('Enlace copiado.'))}>Copiar enlace público</Button>
                )}
                {menuEstado.length > 0 && (
                  <Dropdown menu={{ items: menuEstado, onClick: ({ key }) => (key === 'publicar' ? setPublicar({ interna: proceso.publicacion.interna, externa: proceso.publicacion.externa }) : setAccionEstado(key)) }} trigger={['click']}>
                    <Button type="primary">Acciones del proceso</Button>
                  </Dropdown>
                )}
              </Space>
            </div>

            <Tabs
              destroyOnHidden
              items={[
                { key: 'resumen', label: 'Resumen', children: <Resumen proceso={proceso} porEtapa={datos.candidatos_por_etapa} editable={gestiona && !finalizado} usuarios={usuarios.datos} onActualizar={(v) => ejecutar(() => acciones.actualizar(proceso.id, v), 'Proceso actualizado.')} /> },
                ...(veCandidatos ? [{ key: 'candidatos', label: 'Candidatos', children: <Candidatos user={user} proceso={proceso} version={version} onAbrir={setPostulacionAbierta} /> }] : []),
                { key: 'perfil', label: 'Perfil', children: <PerfilProcesoForm perfil={proceso.perfil} editable={gestiona && !finalizado} onGuardar={async (v) => { await acciones.guardarPerfil(proceso.id, v); recargar(); }} /> },
                { key: 'evaluaciones', label: 'Evaluaciones', children: <Evaluaciones proceso={proceso} puedeInvitar={tiene(user, 'seleccion.candidatos.gestionar')} /> },
                { key: 'actividad', label: 'Actividad', children: <Actividad key={version} procesoId={proceso.id} /> },
              ]}
            />
          </>
        )}
      </EstadoSeccion>

      <PostulacionDrawer
        user={user}
        postulacionId={postulacionAbierta}
        proceso={proceso}
        onCerrar={() => setPostulacionAbierta(null)}
        onCambio={() => {
          setVersion((v) => v + 1);
          recargar();
        }}
      />

      <Modal
        open={Boolean(publicar)}
        title="Publicar proceso"
        okText="Publicar"
        cancelText="Cancelar"
        onCancel={() => setPublicar(null)}
        onOk={() => ejecutar(() => acciones.publicar(proceso.id, publicar), 'Publicación registrada.').then(() => setPublicar(null)).catch(() => {})}
      >
        <p className="mb-3 text-sm text-gray-600">La publicación interna puede registrarse antes que la externa. Una vacante solo interna nunca aparece en el portal público.</p>
        <div className="flex flex-col gap-2">
          <Checkbox checked={publicar?.interna} disabled={proceso?.publicacion.interna} onChange={(e) => setPublicar((p) => ({ ...p, interna: e.target.checked }))}>Publicación interna (por invitación)</Checkbox>
          <Checkbox checked={publicar?.externa} disabled={proceso?.publicacion.externa} onChange={(e) => setPublicar((p) => ({ ...p, externa: e.target.checked }))}>Publicación externa (portal /empleos)</Checkbox>
        </div>
      </Modal>

      <MotivoModal
        abierto={Boolean(accionEstado)}
        titulo={{ pausar: 'Pausar proceso', reanudar: 'Reanudar proceso', cerrar: 'Cerrar proceso', retirar: 'Retirar publicación' }[accionEstado]}
        peligroso={['cerrar', 'retirar'].includes(accionEstado)}
        motivoObligatorio={accionEstado !== 'reanudar'}
        etiquetaMotivo="Motivo interno (no se publica)"
        efecto={{
          pausar: 'Se bloquean nuevas postulaciones. Las candidaturas conservan su estado. El candidato verá solo el mensaje público.',
          reanudar: 'El proceso vuelve a recibir postulaciones.',
          cerrar: 'El proceso deja de recibir postulaciones de forma definitiva.',
          retirar: 'La publicación deja de mostrarse; el proceso conserva su estado.',
        }[accionEstado]}
        campoExtra={['pausar', 'cerrar'].includes(accionEstado) ? (
          <Form.Item name="mensaje_publico" label="Mensaje público para candidatos (opcional)">
            <Input maxLength={300} />
          </Form.Item>
        ) : null}
        onCerrar={() => setAccionEstado(null)}
        onConfirmar={async (v) => {
          await ejecutar(
            () => (accionEstado === 'retirar'
              ? acciones.retirarPublicacion(proceso.id, v.motivo)
              : acciones.cambiarEstado(proceso.id, { accion: accionEstado, motivo: v.motivo || undefined, mensaje_publico: v.mensaje_publico || undefined })),
            'Proceso actualizado.',
          );
          setAccionEstado(null);
          setVersion((x) => x + 1);
        }}
      />
    </div>
  );
}
