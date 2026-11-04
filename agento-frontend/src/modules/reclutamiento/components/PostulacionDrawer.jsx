import { FilePdfOutlined } from '@ant-design/icons';
import {
  App, Button, DatePicker, Descriptions, Drawer, Empty, Form, Input, InputNumber, List, Modal, Select, Space, Switch, Table, Tabs, Tag, Timeline,
} from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import {
  CAUSAS_DESCARTE, ESTADOS_POSTULACION, ETAPAS, ETIQUETA_ETAPA, RESULTADOS_ATS, TIPOS_ENTREVISTA, etiquetaDe, mensajeDeError, tiene,
} from '../constants/seleccion';
import { usePerfilProceso, usePostulacion, usePruebas, useUsuariosSeleccion } from '../hooks/useSeleccion';
import ContratarModal from './ContratarModal';
import EstadoSeccion from './EstadoSeccion';
import EvaluarEntrevistaModal from './EvaluarEntrevistaModal';
import MotivoModal from './MotivoModal';

const COMPONENTES = [
  { clave: 'ats', etiqueta: 'ATS' },
  { clave: 'psicometria', etiqueta: 'Psicometría' },
  { clave: 'entrevistas', etiqueta: 'Entrevistas' },
];

function PuntajeFinal({ puntaje }) {
  if (!puntaje) {
    return <p className="text-sm text-gray-500">Aún sin componentes evaluados.</p>;
  }
  return (
    <div className="space-y-2">
      <p className="text-2xl font-semibold text-gray-900">
        {puntaje.puntaje ?? '—'}
        <Tag className="ml-2" color={puntaje.provisional ? 'orange' : 'green'}>{puntaje.provisional ? 'Provisional' : 'Definitivo'}</Tag>
      </p>
      <div className="grid grid-cols-3 gap-2 text-sm">
        {COMPONENTES.map(({ clave, etiqueta }) => {
          const componente = puntaje.componentes?.[clave];
          const peso = puntaje.componentes?._pesos_efectivos?.[clave];
          return (
            <div key={clave} className="rounded-lg bg-gray-50 p-2">
              <p className="text-xs text-gray-500">{etiqueta}{peso != null ? ` · ${Math.round(peso)}%` : ''}</p>
              <p className="font-medium">
                {componente?.estado === 'completo' ? componente.valor : componente?.estado === 'no_aplica' ? 'No aplica' : 'Pendiente'}
              </p>
            </div>
          );
        })}
      </div>
      <p className="text-xs text-gray-400">Perfil v{puntaje.perfil_version}. Los pendientes no cuentan como 0; los “no aplica” redistribuyen su peso.</p>
    </div>
  );
}

function EvaluacionAtsForm({ perfil, onGuardar }) {
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  if (!perfil) {
    return null;
  }
  const guardar = async () => {
    const valores = await form.validateFields();
    setGuardando(true);
    try {
      await onGuardar({
        requisitos: valores.requisitos ?? {},
        competencias: valores.competencias ?? {},
        comentario: valores.comentario,
      });
      form.resetFields();
    } finally {
      setGuardando(false);
    }
  };
  return (
    <Form form={form} layout="vertical" className="rounded-xl border border-gray-100 p-3">
      <p className="mb-2 text-sm font-medium">Evaluación manual (trazable)</p>
      {perfil.requisitos.map((r) => (
        <Form.Item key={r.id} name={['requisitos', String(r.id)]} label={`${r.tipo === 'excluyente' ? 'Excluyente' : 'Deseable'}: ${r.descripcion}`} initialValue="sin_informacion">
          <Select options={[{ value: 'cumple', label: 'Cumple' }, { value: 'no_cumple', label: 'No cumple (confirmado)' }, { value: 'sin_informacion', label: 'Sin información' }]} />
        </Form.Item>
      ))}
      <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
        {perfil.competencias.map((c) => (
          <Form.Item key={c.id} name={['competencias', c.nombre]} label={`${c.nombre} (requerido ${c.nivel})`}>
            <InputNumber min={1} max={5} placeholder="Sin dato" className="w-full!" />
          </Form.Item>
        ))}
      </div>
      <Form.Item name="comentario" label="Comentario">
        <Input.TextArea rows={2} maxLength={2000} />
      </Form.Item>
      <Button type="primary" onClick={guardar} loading={guardando}>Registrar evaluación ATS</Button>
    </Form>
  );
}

const FILTRO_PUBLICADAS = { estado: 'publicada', per_page: 100 };

export default function PostulacionDrawer({ user, postulacionId, proceso, onCerrar, onCambio }) {
  const { message, modal: avisos } = App.useApp();
  const { datos: p, cargando, error, recargar, acciones, entrevistas } = usePostulacion(postulacionId);
  const perfil = usePerfilProceso(p?.proceso?.id);
  const pruebas = usePruebas(FILTRO_PUBLICADAS, Boolean(postulacionId) && tiene(user, 'seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar'));
  const usuarios = useUsuariosSeleccion(Boolean(postulacionId));
  const gestiona = tiene(user, 'seleccion.candidatos.gestionar');
  const puedeContratar = tiene(user, 'seleccion.contratar');
  const [modal, setModal] = useState(null);
  const [etapaDestino, setEtapaDestino] = useState();
  const [pruebaElegida, setPruebaElegida] = useState();
  const [entrevistaForm] = Form.useForm();

  const ejecutar = async (accion, exito) => {
    try {
      const resultado = await accion();
      message.success(exito);
      recargar();
      onCambio?.();
      return resultado;
    } catch (e) {
      message.error(mensajeDeError(e));
      throw e;
    }
  };

  const descargarCv = async () => {
    try {
      const blob = await acciones.descargarCv(p.id);
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo abrir el CV.'));
    }
  };

  const indiceEtapa = ETAPAS.findIndex((e) => e.value === p?.etapa);
  const vigente = p && ['activa', 'seleccionada'].includes(p.estado);
  const snapshot = p?.snapshot ?? {};

  const pestañas = p ? [
    {
      key: 'resumen',
      label: 'Resumen',
      children: (
        <div className="space-y-4">
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Correo">{p.candidato?.email}</Descriptions.Item>
            <Descriptions.Item label="Celular">{p.candidato?.celular ?? snapshot.celular}</Descriptions.Item>
            <Descriptions.Item label="Documento">{snapshot.tipo_documento?.toUpperCase()} {snapshot.numero_documento}</Descriptions.Item>
            <Descriptions.Item label="Ubicación">{snapshot.ubicacion ?? '—'}</Descriptions.Item>
            {snapshot.pretension_salarial && <Descriptions.Item label="Pretensión salarial">S/ {snapshot.pretension_salarial}</Descriptions.Item>}
            <Descriptions.Item label="Fuente">{p.fuente}</Descriptions.Item>
          </Descriptions>
          <Space>
            <Button icon={<FilePdfOutlined />} onClick={descargarCv} disabled={!p.cv?.disponible}>Ver CV {p.cv ? `(${p.cv.nombre})` : ''}</Button>
            <Tag>Extracción de CV: {p.extraccion_estado === 'no_disponible' ? 'no disponible (sin proveedor)' : p.extraccion_estado}</Tag>
          </Space>
          {snapshot.experiencias?.length > 0 && (
            <div>
              <p className="text-sm font-medium">Experiencia declarada</p>
              <List size="small" dataSource={snapshot.experiencias} renderItem={(e) => <List.Item>{e.titulo} · {e.institucion ?? ''}</List.Item>} />
            </div>
          )}
          {snapshot.estudios?.length > 0 && (
            <div>
              <p className="text-sm font-medium">Estudios declarados</p>
              <List size="small" dataSource={snapshot.estudios} renderItem={(e) => <List.Item>{e.titulo} · {e.institucion ?? ''}</List.Item>} />
            </div>
          )}
          {snapshot.habilidades?.length > 0 && (
            <div className="flex flex-wrap gap-1">
              {snapshot.habilidades.map((h) => <Tag key={h.nombre}>{h.nombre}{h.nivel ? ` · ${h.nivel}` : ''}</Tag>)}
            </div>
          )}
          <div>
            <p className="mb-1 text-sm font-medium">Puntaje final</p>
            <PuntajeFinal puntaje={p.puntaje_final} />
          </div>
          <div className="space-y-1">
            <p className="text-sm font-medium">Componentes que no aplican a esta candidatura</p>
            {COMPONENTES.map(({ clave, etiqueta }) => (
              <div key={clave} className="flex items-center gap-2">
                <Switch size="small" checked={p.no_aplica[clave]} onChange={(valor) => setModal({ tipo: 'no_aplica', componente: clave, valor })} aria-label={`${etiqueta} no aplica`} />
                <span className="text-sm">{etiqueta} no aplica</span>
              </div>
            ))}
          </div>
          <p className="text-xs text-gray-400">Snapshot capturado al postular ({snapshot.capturado_at ? dayjs(snapshot.capturado_at).format('DD/MM/YYYY HH:mm') : '—'}): no cambia aunque el candidato actualice su perfil.</p>
        </div>
      ),
    },
    {
      key: 'ats',
      label: 'ATS',
      children: (
        <div className="space-y-3">
          {(p.evaluaciones_ats ?? []).length === 0 && <Empty description="Sin evaluaciones ATS" />}
          {(p.evaluaciones_ats ?? []).map((e) => (
            <div key={e.id} className="rounded-xl border border-gray-100 p-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-lg font-semibold">{e.puntaje ?? '—'}</span>
                <Tag color={RESULTADOS_ATS[e.resultado]?.color}>{RESULTADOS_ATS[e.resultado]?.label ?? e.resultado}</Tag>
                <span className="text-xs text-gray-500">{e.origen} · {e.proveedor} · {e.formula} · perfil v{e.perfil_version} · {e.evaluado_por ?? 'Sistema'} · {dayjs(e.created_at).format('DD/MM/YYYY HH:mm')}</span>
              </div>
              <ul className="mt-2 space-y-0.5 text-xs text-gray-600">
                {(e.desglose?.excluyentes ?? []).map((r) => <li key={`x${r.id}`}>Excluyente: {r.descripcion} → {r.resultado}</li>)}
                {(e.desglose?.deseables ?? []).map((r) => <li key={`d${r.id}`}>Deseable: {r.descripcion} → {r.resultado}</li>)}
                {(e.desglose?.competencias ?? []).map((c) => <li key={c.nombre}>{c.nombre}: {c.nivel_candidato ?? 'sin dato'} / {c.nivel_requerido} (peso {c.peso})</li>)}
              </ul>
            </div>
          ))}
          <EvaluacionAtsForm perfil={perfil.datos} onGuardar={(valores) => ejecutar(() => acciones.evaluarAts(p.id, valores), 'Evaluación ATS registrada.')} />
        </div>
      ),
    },
    {
      key: 'pruebas',
      label: 'Pruebas',
      children: (
        <div className="space-y-3">
          {vigente && (
            <Space.Compact className="w-full">
              <Select
                className="w-full"
                placeholder="Asignar prueba publicada"
                value={pruebaElegida}
                onChange={setPruebaElegida}
                options={(pruebas.datos?.data ?? []).map((t) => ({ value: t.id, label: `${t.nombre} v${t.version}${t.validada ? '' : ' (no validada)'}` }))}
              />
              <Button
                type="primary"
                disabled={!pruebaElegida}
                onClick={async () => {
                  const r = await ejecutar(() => acciones.asignarPrueba(p.id, pruebaElegida), 'Prueba asignada.').catch(() => null);
                  if (r && !r.correo_enviado) {
                    avisos.info({ title: 'El correo no se pudo enviar', content: <div><p>Comparte este enlace personal con el candidato:</p><Input.TextArea readOnly value={r.enlace} rows={2} /></div> });
                  }
                  setPruebaElegida(undefined);
                }}
              >
                Asignar
              </Button>
            </Space.Compact>
          )}
          <Table
            size="small"
            rowKey="id"
            pagination={false}
            dataSource={p.pruebas ?? []}
            locale={{ emptyText: 'Sin pruebas asignadas' }}
            columns={[
              { title: 'Prueba', render: (_, a) => <span>{a.prueba.nombre} v{a.prueba.version} {!a.prueba.validada && <Tag>No validada</Tag>}</span> },
              { title: 'Estado', dataIndex: 'estado' },
              { title: 'Puntaje', render: (_, a) => a.intento?.puntaje ?? '—' },
              { title: 'Dimensiones', render: (_, a) => (a.intento?.dimensiones ?? []).map((d) => `${d.dimension}: ${d.puntaje}`).join(' · ') || '—' },
              { title: 'Cierre', render: (_, a) => a.intento?.cierre ?? '—' },
            ]}
          />
        </div>
      ),
    },
    {
      key: 'entrevistas',
      label: 'Entrevistas',
      children: (
        <div className="space-y-3">
          {vigente && <Button onClick={() => setModal({ tipo: 'programar' })}>Programar entrevista</Button>}
          {(p.entrevistas ?? []).length === 0 && <Empty description="Sin entrevistas" />}
          {(p.entrevistas ?? []).map((e) => (
            <div key={e.id} className="rounded-xl border border-gray-100 p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium">
                  {etiquetaDe(TIPOS_ENTREVISTA, e.tipo)} · {e.evaluador?.nombre ?? 'Sin evaluador'}
                  {e.programada_at && ` · ${dayjs(e.programada_at).format('DD/MM/YYYY HH:mm')}`}
                </span>
                <Space>
                  <Tag>{e.estado}</Tag>
                  {e.estado !== 'cancelada' && <Button size="small" onClick={() => setModal({ tipo: 'evaluar', entrevista: e })}>Evaluar</Button>}
                  {e.estado === 'programada' && <Button size="small" danger onClick={() => setModal({ tipo: 'cancelar_entrevista', entrevista: e })}>Cancelar</Button>}
                </Space>
              </div>
              {(e.evaluaciones ?? []).map((ev) => (
                <div key={ev.id} className="mt-2 rounded-lg bg-gray-50 p-2 text-xs text-gray-700">
                  <p className="font-medium">{ev.evaluador} · {ev.puntaje} pts (promedio {ev.promedio}) · {ev.recomendacion}</p>
                  <p>Fortalezas: {ev.fortalezas}</p>
                  <p>Riesgos: {ev.riesgos}</p>
                  {ev.comentarios && <p>Comentarios: {ev.comentarios}</p>}
                  <p>{ev.calificaciones.map((c) => `${c.competencia}: ${c.calificacion}`).join(' · ')}</p>
                </div>
              ))}
            </div>
          ))}
        </div>
      ),
    },
    {
      key: 'historial',
      label: 'Historial',
      children: (
        <Timeline
          items={(p.movimientos ?? []).map((m) => ({
            children: (
              <div className="text-sm">
                <p className="font-medium">
                  {m.etapa_anterior ? `${ETIQUETA_ETAPA[m.etapa_anterior]} → ` : ''}{ETIQUETA_ETAPA[m.etapa_nueva]}
                  {m.estado_anterior !== m.estado_nuevo && ` · ${ESTADOS_POSTULACION[m.estado_nuevo]?.label ?? m.estado_nuevo}`}
                </p>
                <p className="text-xs text-gray-500">{m.actor} · {dayjs(m.created_at).format('DD/MM/YYYY HH:mm')}</p>
                {m.motivo && <p className="text-xs text-gray-600">Motivo: {m.motivo}</p>}
              </div>
            ),
          }))}
        />
      ),
    },
  ] : [];

  return (
    <Drawer
      open={Boolean(postulacionId)}
      onClose={onCerrar}
      width={Math.min(760, typeof window !== 'undefined' ? window.innerWidth : 760)}
      title={p ? (
        <div>
          <span>{p.candidato?.nombre}</span>
          <div className="mt-1 flex flex-wrap gap-1">
            <Tag>{ETIQUETA_ETAPA[p.etapa]}</Tag>
            <Tag color={ESTADOS_POSTULACION[p.estado]?.color}>{ESTADOS_POSTULACION[p.estado]?.label}</Tag>
            <Tag>{p.proceso?.codigo} · {p.proceso?.cargo}</Tag>
          </div>
        </div>
      ) : 'Candidatura'}
      footer={p && gestiona ? (
        <div className="flex flex-wrap gap-2">
          {p.estado === 'activa' && (
            <Space.Compact>
              <Select
                placeholder="Mover a etapa"
                className="min-w-48"
                value={etapaDestino}
                onChange={setEtapaDestino}
                options={ETAPAS.filter((e) => e.value !== p.etapa).map((e) => ({ value: e.value, label: e.label }))}
              />
              <Button
                disabled={!etapaDestino}
                onClick={() => {
                  const retrocede = ETAPAS.findIndex((e) => e.value === etapaDestino) < indiceEtapa;
                  if (retrocede) {
                    setModal({ tipo: 'retroceder', etapa: etapaDestino });
                  } else {
                    ejecutar(() => acciones.mover(p.id, { etapa: etapaDestino }), 'Candidatura movida.').catch(() => {});
                    setEtapaDestino(undefined);
                  }
                }}
              >
                Mover
              </Button>
            </Space.Compact>
          )}
          {p.estado === 'activa' && p.etapa === 'seleccion' && (
            <Button onClick={() => ejecutar(() => acciones.seleccionar(p.id), 'Candidatura seleccionada.').catch(() => {})}>Seleccionar</Button>
          )}
          {p.estado === 'seleccionada' && puedeContratar && <Button type="primary" onClick={() => setModal({ tipo: 'contratar' })}>Contratar</Button>}
          {vigente && <Button danger onClick={() => setModal({ tipo: 'descartar' })}>Descartar</Button>}
          {vigente && <Button onClick={() => setModal({ tipo: 'retiro' })}>Registrar retiro</Button>}
          {p.estado === 'descartada' && <Button onClick={() => setModal({ tipo: 'reconsiderar' })}>Reconsiderar</Button>}
        </div>
      ) : null}
    >
      <EstadoSeccion cargando={cargando && !p} error={error} onReintentar={recargar}>
        {p && <Tabs items={pestañas} />}
      </EstadoSeccion>

      <MotivoModal
        abierto={modal?.tipo === 'descartar'}
        titulo="Descartar candidatura"
        peligroso
        efecto="El motivo interno no se muestra al candidato. El candidato verá solo el mensaje público."
        etiquetaMotivo="Motivo interno"
        campoExtra={(
          <>
            <Form.Item name="causa" label="Causa" rules={[{ required: true, message: 'Selecciona la causa.' }]}>
              <Select options={CAUSAS_DESCARTE} />
            </Form.Item>
            <Form.Item name="mensaje_publico" label="Mensaje público (opcional)">
              <Input maxLength={300} placeholder="Por defecto: mensaje neutro de agradecimiento" />
            </Form.Item>
          </>
        )}
        onCerrar={() => setModal(null)}
        onConfirmar={async (v) => {
          await ejecutar(() => acciones.descartar(p.id, v), 'Candidatura descartada.');
          setModal(null);
        }}
      />
      <MotivoModal
        abierto={modal?.tipo === 'retroceder'}
        titulo={`Retroceder a ${ETIQUETA_ETAPA[modal?.etapa] ?? ''}`}
        efecto="Retroceder de etapa queda registrado en el historial."
        onCerrar={() => setModal(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.mover(p.id, { etapa: modal.etapa, motivo }), 'Candidatura movida.');
          setEtapaDestino(undefined);
          setModal(null);
        }}
      />
      <MotivoModal
        abierto={modal?.tipo === 'retiro'}
        titulo="Registrar retiro del candidato"
        efecto="La candidatura queda retirada y sus pruebas pendientes se anulan."
        etiquetaMotivo="¿Cómo comunicó el retiro?"
        onCerrar={() => setModal(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.registrarRetiro(p.id, motivo), 'Retiro registrado.');
          setModal(null);
        }}
      />
      <MotivoModal
        abierto={modal?.tipo === 'reconsiderar'}
        titulo="Reconsiderar candidatura"
        efecto="La candidatura vuelve a estar activa en su etapa actual."
        onCerrar={() => setModal(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.reconsiderar(p.id, motivo), 'Candidatura reactivada.');
          setModal(null);
        }}
      />
      <MotivoModal
        abierto={modal?.tipo === 'no_aplica'}
        titulo={modal?.valor ? 'Marcar componente como no aplicable' : 'Volver a aplicar el componente'}
        efecto={modal?.valor ? 'Su peso se redistribuirá proporcionalmente entre los componentes aplicables.' : 'El componente volverá a contar en el puntaje final.'}
        onCerrar={() => setModal(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.noAplica(p.id, { componente: modal.componente, no_aplica: modal.valor, motivo }), 'Puntaje recalculado.');
          setModal(null);
        }}
      />
      <MotivoModal
        abierto={modal?.tipo === 'cancelar_entrevista'}
        titulo="Cancelar entrevista"
        peligroso
        onCerrar={() => setModal(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => entrevistas.cancelar(modal.entrevista.id, motivo), 'Entrevista cancelada.');
          setModal(null);
        }}
      />
      <Modal
        open={modal?.tipo === 'programar'}
        title="Programar entrevista"
        okText="Programar"
        cancelText="Cancelar"
        destroyOnHidden
        onCancel={() => setModal(null)}
        onOk={async () => {
          const v = await entrevistaForm.validateFields();
          await ejecutar(() => acciones.programarEntrevista(p.id, { ...v, programada_at: v.programada_at?.format('YYYY-MM-DD HH:mm:ss') }), 'Entrevista programada.').catch(() => {});
          entrevistaForm.resetFields();
          setModal(null);
        }}
      >
        <Form form={entrevistaForm} layout="vertical" initialValues={{ tipo: 'virtual' }}>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select options={TIPOS_ENTREVISTA} />
          </Form.Item>
          <Form.Item name="programada_at" label="Fecha y hora">
            <DatePicker showTime={{ format: 'HH:mm' }} format="DD/MM/YYYY HH:mm" className="w-full!" />
          </Form.Item>
          <Form.Item name="lugar" label="Lugar o enlace">
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="evaluador_user_id" label="Evaluador (por defecto, tú)">
            <Select allowClear showSearch optionFilterProp="label" options={(usuarios.datos ?? []).map((u) => ({ value: u.id, label: u.nombre }))} />
          </Form.Item>
        </Form>
      </Modal>
      <EvaluarEntrevistaModal
        entrevista={modal?.tipo === 'evaluar' ? modal.entrevista : null}
        competencias={perfil.datos?.competencias?.map((c) => c.nombre)}
        onEvaluar={entrevistas.evaluar}
        onCerrar={(guardado) => {
          setModal(null);
          if (guardado) {
            recargar();
            onCambio?.();
          }
        }}
      />
      {modal?.tipo === 'contratar' && (
        <ContratarModal
          postulacion={p}
          proceso={proceso ?? p.proceso}
          onContratar={acciones.contratar}
          onCerrar={(contratado) => {
            setModal(null);
            if (contratado) {
              recargar();
              onCambio?.();
            }
          }}
        />
      )}
    </Drawer>
  );
}
