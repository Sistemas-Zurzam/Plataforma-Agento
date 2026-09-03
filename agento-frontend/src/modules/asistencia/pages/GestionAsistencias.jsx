import { ArrowLeftOutlined, CalendarOutlined, CheckCircleOutlined, ClockCircleOutlined, DownOutlined, EditOutlined, ExclamationCircleOutlined, EyeOutlined, FileExcelOutlined, FolderOpenOutlined, HistoryOutlined, PlusOutlined, ReloadOutlined, SafetyCertificateOutlined, ScheduleOutlined, SearchOutlined, SendOutlined, TeamOutlined, UploadOutlined, UsergroupAddOutlined } from '@ant-design/icons';
import { Alert, App, Button, Calendar, Card, DatePicker, Dropdown, Empty, Form, Input, Modal, Select, Space, Statistic, Table, Tabs, Tag, TimePicker, Typography, Upload } from 'antd';
import dayjs from 'dayjs';
import 'dayjs/locale/es';
import { useCallback, useEffect, useMemo, useState } from 'react';
import EmpresaActivaFiltro from '../../configuracion/components/EmpresaActivaFiltro';
import PlanificacionRotativos from '../components/PlanificacionRotativos';
import api from '../../../services/api';

const { RangePicker } = DatePicker;
const { Text, Title } = Typography;
dayjs.locale('es');
const ESTADOS = {
  presente: ['P', 'Presente', 'green'], tardanza: ['T', 'Tardanza', 'gold'], falta: ['F', 'Falta', 'red'],
  marcacion_incompleta: ['MI', 'Marcación incompleta', 'orange'],
  horario_desplazado: ['HD', 'Horario desplazado', 'purple'],
  horas_incompletas: ['HI', 'Horas incompletas', 'volcano'],
  home_office: ['TR', 'Trabajo remoto', 'blue'], descanso: ['D', 'Descanso', 'default'],
  feriado: ['D', 'Feriado', 'default'], no_programado: ['—', 'Sin procesar', 'default'],
  permiso: ['PE', 'Permiso', 'cyan'], falta_justificada: ['FJ', 'Falta justificada', 'gold'],
  dia_sin_clasificar: ['?', 'Rotativo sin planificar — pendiente de clasificación', 'magenta'],
};
const duracion = (minutos) => `${Math.floor((minutos ?? 0) / 60)}h ${String((minutos ?? 0) % 60).padStart(2, '0')}m`;
const hora = (valor) => (valor ? dayjs(valor).format('HH:mm') : '—');
const claveEstado = (resultado) => (resultado?.estado === 'presente' && resultado?.minutos_tardanza > 0 ? 'tardanza' : resultado?.estado);

function EstadoDia({ resultado, mostrarHoras = false }) {
  const estado = resultado ? (ESTADOS[claveEstado(resultado)] ?? ESTADOS.no_programado) : ESTADOS.no_programado;
  return (
    <div className="flex flex-col items-start gap-1">
      <Tag color={estado[2]} title={estado[1]} className="!m-0 min-w-7 text-center">{estado[0]}</Tag>
      {mostrarHoras && Number(resultado?.minutos_trabajados) > 0 && (
        <span className="text-[11px] font-medium text-slate-600" title="Horas trabajadas">
          {duracion(Number(resultado.minutos_trabajados))}
        </span>
      )}
      {mostrarHoras && (resultado?.entrada || resultado?.salida) && (
        <span
          className="text-[10px] leading-tight text-slate-500"
          title={`Entrada: ${hora(resultado?.entrada)} · Salida: ${hora(resultado?.salida)}`}
        >
          E {hora(resultado?.entrada)} · S {hora(resultado?.salida)}
        </span>
      )}
    </div>
  );
}

const ESTADOS_CORREGIBLES = [
  { value: 'presente', label: 'Presente' },
  { value: 'falta_justificada', label: 'Falta justificada' },
  { value: 'permiso', label: 'Permiso' },
  { value: 'home_office', label: 'Trabajo remoto' },
  { value: 'descanso', label: 'Descanso' },
  { value: 'feriado', label: 'Feriado' },
];

function DetalleDiaModal({ open, fecha, colaborador, onCerrar, onReprocesar, reprocesando, onCorregir, corrigiendo, puedeCorregir: puedeCorregirPermiso = true }) {
  const [motivo, setMotivo] = useState('');
  const [entrada, setEntrada] = useState('');
  const [salida, setSalida] = useState('');
  const [estadoManual, setEstadoManual] = useState(undefined);

  useEffect(() => {
    if (open) { setMotivo(''); setEntrada(''); setSalida(''); setEstadoManual(undefined); }
  }, [open, fecha]);

  if (!fecha) return null;

  const fechaTexto = fecha.format('YYYY-MM-DD');
  const tipoProgramado = colaborador.calendario?.[fechaTexto];
  const resultado = colaborador.resultados?.[fechaTexto];
  const estado = resultado ? (ESTADOS[claveEstado(resultado)] ?? ESTADOS.no_programado) : ESTADOS.no_programado;
  const marcacionesDelDia = (colaborador.marcaciones ?? [])
    .filter((m) => m.marcado_at?.startsWith(fechaTexto))
    .sort((a, b) => dayjs(a.marcado_at).valueOf() - dayjs(b.marcado_at).valueOf());
  const puedeCorregir = Boolean(resultado?.id);

  return (
    <Modal
      title={`Detalle del día — ${fechaTexto}`}
      open={open}
      onCancel={onCerrar}
      destroyOnHidden
      footer={[
        <Button key="cerrar" onClick={onCerrar}>Cerrar</Button>,
        <Button
          key="reprocesar"
          icon={<ReloadOutlined />}
          loading={reprocesando}
          disabled={!motivo.trim()}
          onClick={() => onReprocesar(fechaTexto, motivo.trim()).then(onCerrar).catch(() => {})}
        >
          Reprocesar
        </Button>,
        // V3 Fase 3 — A1: visible/habilitado solo si el usuario tiene el
        // permiso real (asistencia.incidencias), no solo si backend
        // devolvería 403 — y con ícono para que no compita visualmente con
        // "Reprocesar".
        puedeCorregirPermiso && (
          <Button
            key="corregir"
            type="primary"
            icon={<EditOutlined />}
            loading={corrigiendo}
            disabled={!motivo.trim() || !puedeCorregir || (!entrada && !salida && !estadoManual)}
            onClick={() => onCorregir(resultado.id, { entrada: entrada || undefined, salida: salida || undefined, estado: estadoManual, motivo: motivo.trim() }).then(onCerrar).catch(() => {})}
          >
            Guardar corrección
          </Button>
        ),
      ]}
    >
      <div className="grid grid-cols-2 gap-4">
        <div>
          <Text type="secondary" className="text-xs">Tipo programado</Text>
          <p className="font-medium capitalize">{tipoProgramado ? tipoProgramado.replaceAll('_', ' ') : 'Sin configurar'}</p>
        </div>
        <div>
          <Text type="secondary" className="text-xs">Estado real</Text>
          <p><Tag color={estado[2]}>{estado[1]}</Tag></p>
        </div>
      </div>
      <div className="mt-4">
        <Text type="secondary" className="text-xs">Marcaciones ({marcacionesDelDia.length})</Text>
        {marcacionesDelDia.length === 0 ? (
          <p className="mt-1 text-sm text-gray-400">Sin marcaciones registradas</p>
        ) : (
          <ul className="mt-1 space-y-1 text-sm text-gray-700">
            {marcacionesDelDia.map((m, index) => {
              const horaMarcacion = dayjs(m.marcado_at).format('HH:mm:ss');
              const horaEntrada = resultado?.entrada ? dayjs(resultado.entrada).format('HH:mm:ss') : null;
              const horaSalida = resultado?.salida ? dayjs(resultado.salida).format('HH:mm:ss') : null;
              const esEntrada = horaEntrada ? horaMarcacion === horaEntrada : index === 0;
              const esSalida = horaSalida
                ? horaMarcacion === horaSalida
                : (marcacionesDelDia.length === 2 && !esEntrada)
                  || (marcacionesDelDia.length > 2 && index === marcacionesDelDia.length - 1);
              const etiqueta = esEntrada ? 'Entrada' : esSalida ? 'Salida' : 'Intermedia';
              const color = esEntrada ? 'green' : esSalida ? 'blue' : 'default';

              return (
                <li key={m.id} className="flex items-center gap-2">
                  <Tag color={color} className="!m-0 w-20 text-center">{etiqueta}</Tag>
                  <span>{horaMarcacion} · {m.origen}</span>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      <div className="mt-5 rounded-lg border border-slate-200 p-3">
        <Text strong className="text-sm">Corrección manual</Text>
        <p className="mt-0.5 text-xs text-gray-500">
          Úsalo cuando la persona sí asistió pero el biométrico no registró la marcación: escribe la hora real y, si hace falta, fuerza el estado.
        </p>
        {!puedeCorregir && (
          <p className="mt-2 text-xs text-amber-600">Este día todavía no tiene un resultado procesado — usa "Reprocesar" primero para poder corregirlo.</p>
        )}
        <div className="mt-3 grid grid-cols-2 gap-3">
          <div>
            <label className="text-xs text-gray-500">Entrada real</label>
            <TimePicker
              className="mt-1 w-full"
              format="HH:mm"
              placeholder="--:--"
              allowClear
              value={entrada ? dayjs(entrada, 'HH:mm') : null}
              onChange={(_, horaTexto) => setEntrada(horaTexto)}
              disabled={!puedeCorregir}
            />
          </div>
          <div>
            <label className="text-xs text-gray-500">Salida real</label>
            <TimePicker
              className="mt-1 w-full"
              format="HH:mm"
              placeholder="--:--"
              allowClear
              value={salida ? dayjs(salida, 'HH:mm') : null}
              onChange={(_, horaTexto) => setSalida(horaTexto)}
              disabled={!puedeCorregir}
            />
          </div>
        </div>
        <div className="mt-3">
          <label className="text-xs text-gray-500">Forzar estado (opcional)</label>
          <Select
            className="mt-1 w-full"
            allowClear
            placeholder="Dejar que el cálculo lo determine"
            options={ESTADOS_CORREGIBLES}
            value={estadoManual}
            onChange={setEstadoManual}
            disabled={!puedeCorregir}
          />
        </div>
      </div>

      <div className="mt-4">
        <label className="text-xs text-gray-500">Motivo <span className="text-red-500">*</span></label>
        <Input.TextArea
          className="mt-1"
          rows={2}
          maxLength={500}
          showCount
          value={motivo}
          onChange={(event) => setMotivo(event.target.value)}
          placeholder="Ej: el trabajador llegó pero el biométrico no registró la marcación"
        />
      </div>
    </Modal>
  );
}

/**
 * V3 Fase 3 — A8/A11/A16/A22: formulario mínimo para resolver una
 * incidencia (Falta u Horas Incompletas, aunque funciona para cualquier
 * tipo) creando un AsistenciaPermiso REAL, nunca una etiqueta
 * resultado.estado='permiso'. Compartido entre Vista general y Perfil
 * único — un solo modal, un solo endpoint (PATCH .../incidencias/{id}/permiso).
 */
function ModalResolverConPermiso({ open, incidencia, form, onCancel, onSubmit, submitting }) {
  const tipo = Form.useWatch('tipo', form);

  return (
    <Modal title="Resolver incidencia registrando un permiso" open={open} onCancel={onCancel} footer={null} destroyOnHidden width={520}>
      {incidencia && (
        <Alert
          className="mb-4"
          type="info"
          showIcon
          message={`${incidencia.colaborador ? `${incidencia.colaborador.nombres ?? ''} ${incidencia.colaborador.apellidos ?? ''}`.trim() : ''} · ${dayjs(incidencia.fecha).format('DD/MM/YYYY')} · ${(incidencia.tipo ?? '').replaceAll('_', ' ')}`}
          description="Se creará y aprobará un permiso real para este colaborador — la incidencia quedará resuelta con trazabilidad de ambas acciones."
        />
      )}
      <Form form={form} layout="vertical" onFinish={onSubmit} requiredMark="optional">
        <Form.Item name="tipo" label="Tipo de permiso" rules={[{ required: true }]}>
          <Select options={[{ value: 'personal', label: 'Personal' }, { value: 'medico', label: 'Médico' }, { value: 'capacitacion', label: 'Capacitación' }, { value: 'comision_servicio', label: 'Comisión de servicio' }, { value: 'vacaciones', label: 'Vacaciones' }, { value: 'otro', label: 'Otro' }]} />
        </Form.Item>
        {['personal', 'capacitacion'].includes(tipo) && (
          <Form.Item
            name="con_goce"
            label="¿Con goce de haber?"
            rules={[{ required: true, message: 'Indica si el permiso es con o sin goce de haber' }]}
          >
            <Select placeholder="Selecciona" options={[{ value: true, label: 'Con goce de haber' }, { value: false, label: 'Sin goce de haber' }]} />
          </Form.Item>
        )}
        {tipo === 'medico' && (
          <Form.Item name="pagador_subsidio" label="Pagador del subsidio" extra="Déjalo vacío si no lo sabes todavía.">
            <Select allowClear placeholder="No indicado" options={[{ value: 'empleador', label: 'Empleador' }, { value: 'essalud_directo', label: 'EsSalud directamente' }]} />
          </Form.Item>
        )}
        <Form.Item
          name="fechas"
          label="Período del permiso"
          rules={[{ required: true, message: 'Selecciona las fechas' }]}
          extra="La implementación actual de permisos es por día completo — no permite un tramo de horas dentro del día."
        >
          <RangePicker className="w-full" format="DD/MM/YYYY" />
        </Form.Item>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Ingresa el motivo' }, { max: 1000 }]}>
          <Input.TextArea rows={3} placeholder="Motivo del permiso" />
        </Form.Item>
        <div className="flex justify-end gap-2">
          <Button onClick={onCancel}>Cancelar</Button>
          <Button type="primary" htmlType="submit" loading={submitting}>Registrar permiso y resolver</Button>
        </div>
      </Form>
    </Modal>
  );
}

function PerfilAsistencia({ colaborador, loading, onVolver, onReprocesar, reprocesando, onReprocesarDia, reprocesandoDia, onCorregirDia, corrigiendoDia, puedeResolverIncidencias, accionesIncidencia }) {
  const [diaSeleccionado, setDiaSeleccionado] = useState(null);

  if (loading || !colaborador) return <Card loading className="min-h-72" />;
  const resumen = colaborador.resumen ?? {};
  const resultadoDelDia = (fecha) => colaborador.resultados?.[fecha.format('YYYY-MM-DD')];
  const calendario = <div className="rounded-xl border border-slate-200 bg-white p-3"><Calendar fullscreen onSelect={setDiaSeleccionado} cellRender={(fecha, info) => info.type === 'date' ? <div className="mt-1"><EstadoDia resultado={resultadoDelDia(fecha)} mostrarHoras /></div> : info.originNode} /></div>;
  const marcaciones = <Table size="small" rowKey="id" dataSource={colaborador.marcaciones ?? []} columns={[
    { title: 'Fecha', dataIndex: 'marcado_at', render: (value) => dayjs(value).format('DD/MM/YYYY') },
    { title: 'Hora', dataIndex: 'marcado_at', render: (value) => dayjs(value).format('HH:mm:ss') },
    { title: 'Origen', dataIndex: 'origen' },
    { title: 'Dispositivo', dataIndex: 'dispositivo', render: (value) => value ?? '—' },
    { title: 'Estado', dataIndex: 'estado_procesamiento', render: (value) => <Tag color={value === 'asociada' ? 'green' : value === 'anulada' ? 'red' : 'gold'}>{value}</Tag> },
  ]} locale={{ emptyText: <Empty description="No hay marcaciones en este período" /> }} />;
  // V3 Fase 3 — A4: mismas acciones (Aprobar/Rechazar/Resolver) y mismo
  // endpoint que la vista general — accionesIncidencia() se reutiliza tal
  // cual, solo se le indica que "Editar marcaciones" debe abrir
  // DetalleDiaModal (estado local de este componente) en vez del
  // Modal.confirm que usa la vista general.
  const incidencias = <Table size="small" rowKey="id" dataSource={colaborador.incidencias ?? []} columns={[
    { title: 'Fecha', dataIndex: 'fecha', render: (value) => dayjs(value).format('DD/MM/YYYY') }, { title: 'Tipo', dataIndex: 'tipo', render: (value) => value.replaceAll('_', ' ') },
    { title: 'Descripción', dataIndex: 'descripcion' }, { title: 'Estado', dataIndex: 'estado', render: (value) => <Tag>{value}</Tag> },
    {
      title: 'Acciones', width: 160,
      render: (_, row) => accionesIncidencia(row, { onEditarMarcaciones: () => setDiaSeleccionado(dayjs(row.fecha)) }),
    },
  ]} locale={{ emptyText: <Empty description="No hay incidencias en este período" /> }} />;
  const extras = <Table size="small" rowKey="id" dataSource={colaborador.horas_extra ?? []} columns={[
    { title: 'Fecha', dataIndex: 'fecha', render: (value) => dayjs(value).format('DD/MM/YYYY') }, { title: 'Tasa', dataIndex: 'tasa', render: (value) => `${value}%` },
    { title: 'Observadas', dataIndex: 'minutos_observados', render: duracion }, { title: 'Aprobadas', dataIndex: 'minutos_aprobados', render: duracion },
    { title: 'Estado', dataIndex: 'estado', render: (value) => <Tag>{value}</Tag> },
    { title: 'Motivo', dataIndex: 'motivo', ellipsis: true, render: (value) => value || '—' },
  ]} locale={{ emptyText: <Empty description="No hay horas extra en este período" /> }} />;
  const permisos = <Table size="small" rowKey="id" dataSource={colaborador.permisos ?? []} columns={[
    { title: 'Tipo', dataIndex: 'tipo', render: (value) => value.replaceAll('_', ' ') }, { title: 'Desde', dataIndex: 'fecha_inicio', render: (value) => dayjs(value).format('DD/MM/YYYY') },
    { title: 'Hasta', dataIndex: 'fecha_fin', render: (value) => dayjs(value).format('DD/MM/YYYY') }, { title: 'Motivo', dataIndex: 'motivo' }, { title: 'Estado', dataIndex: 'estado', render: (value) => <Tag>{value}</Tag> },
  ]} locale={{ emptyText: <Empty description="No hay permisos en este período" /> }} />;
  const historial = <Table size="small" rowKey="id" dataSource={colaborador.historial ?? []} columns={[
    { title: 'Fecha y hora', dataIndex: 'fecha', width: 180, render: (value) => dayjs(value).format('DD/MM/YYYY HH:mm:ss') },
    { title: 'Acción', dataIndex: 'accion', render: (value) => value.replaceAll('_', ' ') },
    { title: 'Motivo', dataIndex: 'motivo', render: (value) => value ?? '—' },
  ]} locale={{ emptyText: <Empty description="No hay acciones auditadas en este período" /> }} />;
  const tarjetas = [
    ['Días trabajados', resumen.dias_trabajados, 'text-green-600'], ['Faltas', resumen.faltas, 'text-red-500'],
    ['Tardanzas', resumen.tardanzas, 'text-amber-500'], ['Horas extra', duracion(resumen.minutos_extra), 'text-violet-600'],
    ['Inc. pendientes', colaborador.incidencias_pendientes, 'text-red-500'], ['Descansos/Feriados', resumen.descansos_feriados, 'text-slate-600'],
    ['Home office', resumen.home_office, 'text-blue-600'],
  ];

  return <div className="space-y-4">
    <div className="flex items-center justify-between border-b border-slate-200 pb-3">
      <div className="flex items-center gap-3"><Button type="text" icon={<ArrowLeftOutlined />} onClick={onVolver} /><div><Text type="secondary" className="text-xs">Gestión de asistencias / Colaboradores / Perfil de asistencia</Text><Title level={4} className="!mb-0 !mt-1">{colaborador.nombre_completo}</Title><Text type="secondary" className="text-xs">{colaborador.empresa} · {colaborador.area ?? 'Sin área'} · {colaborador.cargo}</Text></div></div>
      <Button icon={<ReloadOutlined />} loading={reprocesando} onClick={onReprocesar}>Reprocesar</Button>
    </div>
    <Tabs items={[
      { key: 'resumen', label: 'Resumen', children: <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{tarjetas.map(([label, value, color]) => <Card size="small" key={label}><div className={`text-xl font-semibold ${color}`}>{value ?? 0}</div><Text type="secondary" className="text-xs">{label}</Text></Card>)}</div> },
      { key: 'calendario', label: 'Calendario', children: calendario },
      { key: 'marcaciones', label: 'Marcaciones', children: marcaciones },
      { key: 'incidencias', label: 'Incidencias', children: incidencias },
      { key: 'horas-extra', label: 'Horas extra', children: extras },
      { key: 'permisos', label: 'Permisos', children: permisos },
      { key: 'historial', label: 'Historial', children: historial },
    ]} />
    <DetalleDiaModal
      open={Boolean(diaSeleccionado)}
      fecha={diaSeleccionado}
      colaborador={colaborador}
      onCerrar={() => setDiaSeleccionado(null)}
      onReprocesar={(fecha, motivo) => onReprocesarDia(fecha, motivo)}
      reprocesando={reprocesandoDia}
      onCorregir={(resultadoId, datos) => onCorregirDia(resultadoId, datos)}
      corrigiendo={corrigiendoDia}
      puedeCorregir={puedeResolverIncidencias}
    />
  </div>;
}

export default function GestionAsistencias({ user, onUserRefresh, colaboradorId, initialTab, onVerColaborador, onVolver }) {
  const { message } = App.useApp();
  const [permisoForm] = Form.useForm();
  const [solicitudForm] = Form.useForm();
  const tipoPermiso = Form.useWatch('tipo', permisoForm);
  const [rango, setRango] = useState([dayjs().startOf('month'), dayjs()]);
  const [activeTab, setActiveTab] = useState(initialTab || 'resumen');

  useEffect(() => {
    if (initialTab) setActiveTab(initialTab);
  }, [initialTab]);
  const [busqueda, setBusqueda] = useState('');
  const [filtroSede, setFiltroSede] = useState();
  const [filtroArea, setFiltroArea] = useState();
  const [filtroPreparacion, setFiltroPreparacion] = useState('todos');
  const [resultados, setResultados] = useState([]);
  const [colaboradores, setColaboradores] = useState([]);
  const [stats, setStats] = useState({});
  const [statsColaboradores, setStatsColaboradores] = useState({});
  const [loading, setLoading] = useState(false);
  const [reprocesando, setReprocesando] = useState(false);
  const [reprocesandoDia, setReprocesandoDia] = useState(false);
  const [corrigiendoDia, setCorrigiendoDia] = useState(false);
  const [perfil, setPerfil] = useState(null);
  const [permisos, setPermisos] = useState([]);
  const [estadoPermiso, setEstadoPermiso] = useState('todos');
  const [permisoModalOpen, setPermisoModalOpen] = useState(false);
  const [guardandoPermiso, setGuardandoPermiso] = useState(false);
  const [solicitudesArea, setSolicitudesArea] = useState([]);
  const [estadoSolicitud, setEstadoSolicitud] = useState('todos');
  const [solicitudModalOpen, setSolicitudModalOpen] = useState(false);
  const [guardandoSolicitud, setGuardandoSolicitud] = useState(false);
  const [importandoMarcaciones, setImportandoMarcaciones] = useState(false);
  const [noAsociadas, setNoAsociadas] = useState([]);
  const [archivoPendiente, setArchivoPendiente] = useState(null);
  const [previsualizacion, setPrevisualizacion] = useState(null);
  const [incidencias, setIncidencias] = useState([]);
  const [filtroEstadoIncidencia, setFiltroEstadoIncidencia] = useState('todos');
  const [colaboradorFiltroIncidencia, setColaboradorFiltroIncidencia] = useState(null);
  const [permisoIncidenciaModalOpen, setPermisoIncidenciaModalOpen] = useState(false);
  const [incidenciaParaPermiso, setIncidenciaParaPermiso] = useState(null);
  const [resolviendoConPermiso, setResolviendoConPermiso] = useState(false);
  const [permisoIncidenciaForm] = Form.useForm();
  const [horasExtra, setHorasExtra] = useState([]);
  const [colaboradorFiltroHorasExtra, setColaboradorFiltroHorasExtra] = useState(null);
  const [estadoFiltroHorasExtra, setEstadoFiltroHorasExtra] = useState('todos');
  const [horasExtraSeleccionadas, setHorasExtraSeleccionadas] = useState([]);
  const [importaciones, setImportaciones] = useState([]);
  const [periodos, setPeriodos] = useState([]);
  const [coberturaEnProceso, setCoberturaEnProceso] = useState({});
  const [, setAuditoria] = useState([]);
  const [periodoModalOpen, setPeriodoModalOpen] = useState(false);
  const [periodoForm] = Form.useForm();
  const puedeProcesar = user?.role === 'administrador' || user?.permisos?.includes('asistencia.procesar');
  const puedeGestionarPermisos = user?.role === 'administrador' || user?.permisos?.includes('asistencia.permisos');
  const puedeGestionarArea = user?.role === 'administrador' || user?.permisos?.includes('asistencia.gestiones_area');
  const puedeGestionarPeriodos = user?.role === 'administrador' || user?.permisos?.includes('asistencia.periodos');
  const puedeResolverIncidencias = user?.role === 'administrador' || user?.permisos?.includes('asistencia.incidencias');
  const puedeGestionarHorasExtra = user?.role === 'administrador' || user?.permisos?.includes('asistencia.horas_extra');
  const puedeAprobarRrhh = user?.role === 'administrador' || user?.permisos?.includes('asistencia.aprobar_rrhh');

  const parametros = useMemo(() => ({
    fecha_desde: rango[0].format('YYYY-MM-DD'), fecha_hasta: rango[1].format('YYYY-MM-DD'), per_page: 100,
    busqueda: busqueda || undefined, sede: filtroSede, area: filtroArea,
    preparacion: filtroPreparacion === 'todos' ? undefined : filtroPreparacion,
  }), [busqueda, filtroArea, filtroPreparacion, filtroSede, rango]);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const [resumenResponse, colaboradoresResponse, permisosResponse, solicitudesResponse, noAsociadasResponse, horasExtraResponse, importacionesResponse, periodosResponse, auditoriaResponse] = await Promise.all([
        api.get('/asistencia/resumen', { params: parametros }),
        api.get('/asistencia/colaboradores', { params: parametros }),
        api.get('/asistencia/permisos', { params: parametros }),
        api.get('/asistencia/gestiones-area', { params: parametros }),
        api.get('/asistencia/marcaciones-no-asociadas', { params: parametros }),
        api.get('/asistencia/horas-extra', { params: parametros }),
        api.get('/asistencia/importaciones', { params: parametros }),
        api.get('/asistencia/periodos', { params: parametros }),
        api.get('/asistencia/auditoria', { params: parametros }),
      ]);
      setResultados(resumenResponse.data.data ?? []);
      setStats(resumenResponse.data.stats ?? {});
      setColaboradores(colaboradoresResponse.data.data ?? []);
      setStatsColaboradores(colaboradoresResponse.data.stats ?? {});
      setPermisos(permisosResponse.data.data ?? []);
      setSolicitudesArea(solicitudesResponse.data.data ?? []);
      setNoAsociadas(noAsociadasResponse.data.data ?? []);
      setHorasExtra(horasExtraResponse.data.data ?? []);
      setHorasExtraSeleccionadas([]);
      setImportaciones(importacionesResponse.data.data ?? []);
      setPeriodos(periodosResponse.data.data ?? []);
      setAuditoria(auditoriaResponse.data.data ?? []);
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo cargar la gestión de asistencia');
    } finally { setLoading(false); }
  }, [message, parametros]);

  useEffect(() => { cargar(); }, [cargar, user?.empresa?.id]);

  /**
   * Separado del Promise.all de cargar(): Incidencias necesita su propio
   * filtro de estado y, opcionalmente, quedar preseleccionado a un
   * colaborador (al hacer click en el contador de incidencias de la tabla
   * de Colaboradores) — sin afectar el resto de tabs que comparten
   * `parametros`.
   */
  const cargarIncidencias = useCallback(async () => {
    try {
      const { data } = await api.get('/asistencia/incidencias', {
        params: {
          ...parametros,
          estado: filtroEstadoIncidencia === 'todos' ? undefined : filtroEstadoIncidencia,
          colaborador_id: colaboradorFiltroIncidencia ?? undefined,
        },
      });
      setIncidencias(data.data ?? []);
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo cargar las incidencias');
    }
  }, [colaboradorFiltroIncidencia, filtroEstadoIncidencia, message, parametros]);

  useEffect(() => { cargarIncidencias(); }, [cargarIncidencias, user?.empresa?.id]);

  useEffect(() => {
    if (!colaboradorId) { setPerfil(null); return; }
    setLoading(true);
    api.get(`/asistencia/colaboradores/${colaboradorId}`, { params: parametros })
      .then(({ data }) => setPerfil(data.data ?? data))
      .catch((error) => message.error(error.response?.data?.message ?? 'No se pudo abrir el perfil de asistencia'))
      .finally(() => setLoading(false));
  }, [colaboradorId, message, parametros]);

  const reprocesar = async () => {
    setReprocesando(true);
    try {
      const payload = colaboradorId ? { ...parametros, colaborador_ids: [colaboradorId] } : parametros;
      const { data } = await api.post('/asistencia/reprocesar', payload);
      const eliminados = data.resultados_eliminados_anteriores_ingreso ?? 0;
      message.success(`${data.resultados_procesados} jornadas procesadas${eliminados > 0 ? ` · ${eliminados} resultado(s) anterior(es) al ingreso eliminado(s)` : ''}`);
      await cargar();
      if (colaboradorId) {
        const perfilResponse = await api.get(`/asistencia/colaboradores/${colaboradorId}`, { params: parametros });
        setPerfil(perfilResponse.data.data ?? perfilResponse.data);
      }
    } catch (error) { message.error(error.response?.data?.message ?? 'No se pudo reprocesar la asistencia'); }
    finally { setReprocesando(false); }
  };

  const reprocesarDia = async (fecha, motivo) => {
    setReprocesandoDia(true);
    try {
      await api.post('/asistencia/reprocesar', {
        fecha_desde: fecha, fecha_hasta: fecha, colaborador_ids: [colaboradorId], motivo,
      });
      message.success('Día reprocesado correctamente');
      const perfilResponse = await api.get(`/asistencia/colaboradores/${colaboradorId}`, { params: parametros });
      setPerfil(perfilResponse.data.data ?? perfilResponse.data);
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo reprocesar el día');
      throw error;
    } finally { setReprocesandoDia(false); }
  };

  const corregirDia = async (resultadoId, datos) => {
    setCorrigiendoDia(true);
    try {
      await api.patch(`/asistencia/resultados/${resultadoId}`, datos);
      message.success('Corrección guardada correctamente');
      const perfilResponse = await api.get(`/asistencia/colaboradores/${colaboradorId}`, { params: parametros });
      setPerfil(perfilResponse.data.data ?? perfilResponse.data);
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo guardar la corrección');
      throw error;
    } finally { setCorrigiendoDia(false); }
  };

  const crearPermiso = async (values) => {
    setGuardandoPermiso(true);
    try {
      await api.post('/asistencia/permisos', {
        colaborador_id: values.colaborador_id,
        tipo: values.tipo,
        fecha_inicio: values.fechas[0].format('YYYY-MM-DD'),
        fecha_fin: values.fechas[1].format('YYYY-MM-DD'),
        motivo: values.motivo,
        con_goce: values.con_goce ?? undefined,
        pagador_subsidio: values.tipo === 'medico' ? (values.pagador_subsidio ?? undefined) : undefined,
      });
      message.success('Permiso registrado y pendiente de aprobación');
      setPermisoModalOpen(false);
      permisoForm.resetFields();
      const response = await api.get('/asistencia/permisos', { params: parametros });
      setPermisos(response.data.data ?? []);
    } catch (error) { message.error(error.response?.data?.message ?? 'No se pudo registrar el permiso'); }
    finally { setGuardandoPermiso(false); }
  };

  const crearSolicitudArea = async (values) => {
    setGuardandoSolicitud(true);
    try {
      await api.post('/asistencia/gestiones-area', {
        tipo: values.tipo, origen: values.origen,
        fecha_inicio: values.fechas[0].format('YYYY-MM-DD'), fecha_fin: values.fechas[1].format('YYYY-MM-DD'),
        colaborador_ids: values.colaborador_ids, motivo: values.motivo, medio_recepcion: values.medio_recepcion,
      });
      message.success('Solicitud registrada correctamente');
      setSolicitudModalOpen(false); solicitudForm.resetFields();
      const response = await api.get('/asistencia/gestiones-area', { params: parametros });
      setSolicitudesArea(response.data.data ?? []);
    } catch (error) { message.error(error.response?.data?.message ?? 'No se pudo crear la solicitud'); }
    finally { setGuardandoSolicitud(false); }
  };

  const prepararArchivo = async (archivo) => {
    setImportandoMarcaciones(true);
    try {
      const formulario = new FormData();
      formulario.append('archivo', archivo);
      const { data } = await api.post('/asistencia/importar/previsualizar', formulario, { headers: { 'Content-Type': 'multipart/form-data' } });
      setArchivoPendiente(archivo); setPrevisualizacion(data.data);
    } catch (error) { message.error(error.response?.data?.message ?? 'No se pudo analizar el archivo de marcaciones'); }
    finally { setImportandoMarcaciones(false); }
    return Upload.LIST_IGNORE;
  };

  const confirmarImportacion = async () => {
    if (!archivoPendiente) return;
    setImportandoMarcaciones(true);
    try {
      const formulario = new FormData(); formulario.append('archivo', archivoPendiente);
      const { data } = await api.post('/asistencia/importar', formulario, { headers: { 'Content-Type': 'multipart/form-data' } });
      const detalle = data.data;
      message.success(`${detalle.filas_nuevas} nuevas, ${detalle.filas_duplicadas} duplicadas y ${detalle.filas_observadas} pendientes de asociación`);
      setArchivoPendiente(null); setPrevisualizacion(null); await cargar(); setActiveTab('diaria');
    } catch (error) { message.error(error.response?.data?.message ?? 'No se pudo confirmar la importación'); }
    finally { setImportandoMarcaciones(false); }
  };

  const solicitarDecision = (titulo, endpoint, accion, extra = {}, advertencia = null) => {
    let motivo = '';
    Modal.confirm({
      title: titulo,
      content: <div>
        {advertencia && <Alert className="mb-3" type="info" showIcon message={advertencia} />}
        <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(event) => { motivo = event.target.value; }} />
      </div>,
      okText: accion === 'aprobar' ? 'Aprobar' : accion === 'rechazar' ? 'Rechazar' : 'Confirmar',
      okButtonProps: { danger: accion === 'rechazar' },
      cancelText: 'Cancelar',
      onOk: async () => {
        if (!motivo.trim()) { message.warning('Ingresa el motivo de la decisión'); throw new Error('motivo_requerido'); }
        await api.patch(endpoint, { accion, motivo: motivo.trim(), ...extra });
        message.success('Decisión registrada con trazabilidad');
        await cargar();
        if (endpoint.includes('/asistencia/incidencias/')) await cargarIncidencias();
      },
    });
  };

  /**
   * Fase 4A — antes de cerrar un período, Agento verifica que todas las
   * combinaciones colaborador×fecha del rango ya fueron evaluadas por
   * Asistencia. Si falta cobertura, el backend responde 202 (no 200) y
   * encola la materialización en segundo plano — acá se hace polling del
   * resultado, igual que el patrón ya usado para el cálculo de planilla en
   * Remuneraciones (ciclos_remunerativos.calculo_estado).
   */
  const pollEstadoCobertura = async (periodoId, intentos = 0) => {
    const { data } = await api.get(`/asistencia/periodos/${periodoId}/estado-cobertura`);

    if (data.cobertura_estado === 'en_proceso') {
      if (intentos > 100) {
        message.info('La verificación de cobertura sigue en proceso. Vuelve a intentar cerrar el período en unos minutos.');
        setCoberturaEnProceso((prev) => ({ ...prev, [periodoId]: false }));
        return;
      }
      setTimeout(() => pollEstadoCobertura(periodoId, intentos + 1), 3000);
      return;
    }

    setCoberturaEnProceso((prev) => ({ ...prev, [periodoId]: false }));
    await cargar();

    const resultado = data.cobertura_resultado ?? {};
    if (data.cobertura_estado === 'error') {
      if (resultado.errores?.length) {
        Modal.warning({
          title: 'La cobertura de asistencia terminó con errores',
          content: (
            <ul className="list-disc pl-4 text-xs">
              {resultado.errores.map((error, indice) => (
                <li key={indice}>Colaborador #{error.colaborador_id} · {error.fecha}: {error.motivo}</li>
              ))}
            </ul>
          ),
        });
      } else {
        message.error(`No se pudo completar la cobertura de asistencia: ${resultado.error ?? 'error desconocido'}`);
      }
      return;
    }

    const generados = [
      resultado.faltas ? `${resultado.faltas} faltas pendientes` : null,
      resultado.dias_sin_clasificar ? `${resultado.dias_sin_clasificar} días sin clasificar` : null,
      resultado.trabajos_en_descanso ? `${resultado.trabajos_en_descanso} trabajos en descanso` : null,
    ].filter(Boolean);
    message.success(
      `Cobertura de asistencia completada. Se procesaron ${resultado.procesadas ?? 0} fechas.`
      // Cerrar en sí no revisa incidencias (eso lo sigue haciendo, sin
      // cambios, el gate de "Enviar a Nómina") — el aviso es para que
      // RR.HH. las resuelva antes de llegar a ese paso, no una condición
      // para poder cerrar ahora mismo.
      + (generados.length ? ` Se generaron: ${generados.join(', ')}. Ya puedes cerrar el período — resuelve las incidencias antes de enviarlo a Nómina.` : ' Ya puedes intentar cerrar el período nuevamente.'),
      8,
    );
  };

  const handleCerrarPeriodo = (periodo) => {
    let motivo = '';
    Modal.confirm({
      title: 'Cerrar período',
      content: <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(event) => { motivo = event.target.value; }} />,
      okText: 'Confirmar',
      cancelText: 'Cancelar',
      onOk: async () => {
        if (!motivo.trim()) { message.warning('Ingresa el motivo de la decisión'); throw new Error('motivo_requerido'); }
        try {
          const { data, status } = await api.patch(`/asistencia/periodos/${periodo.id}`, { accion: 'cerrar', motivo: motivo.trim() });
          if (status === 202) {
            message.info(data.message);
            setCoberturaEnProceso((prev) => ({ ...prev, [periodo.id]: true }));
            pollEstadoCobertura(periodo.id);
            await cargar();
            return;
          }
          message.success('Decisión registrada con trazabilidad');
          await cargar();
        } catch (error) {
          // Fase 4A.1 — fecha futura, pendientes sin resolver o cobertura en
          // error llegan como ValidationException (422): a diferencia de
          // solicitarDecision(), acá sí vale la pena mostrar el detalle
          // porque son bloqueos de negocio reales, no un error genérico.
          const detalle = error.response?.data?.errors ? Object.values(error.response.data.errors)[0][0] : null;
          message.error(detalle ?? error.response?.data?.message ?? 'No se pudo cerrar el período');
          throw error;
        }
      },
    });
  };

  // Si el período ya estaba "en_proceso" al cargar (lo disparó otro
  // usuario, o se recargó la página), retoma el polling en vez de dejarlo
  // huérfano — mismo patrón que Remuneraciones para calculo_estado.
  useEffect(() => {
    periodos.forEach((periodo) => {
      if (periodo.cobertura_estado === 'en_proceso' && !coberturaEnProceso[periodo.id]) {
        setCoberturaEnProceso((prev) => ({ ...prev, [periodo.id]: true }));
        pollEstadoCobertura(periodo.id);
      }
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodos]);

  const crearPeriodo = async (values) => {
    await api.post('/asistencia/periodos', {
      fecha_inicio: values.fechas[0].format('YYYY-MM-DD'), fecha_fin: values.fechas[1].format('YYYY-MM-DD'),
    });
    message.success('Período creado'); setPeriodoModalOpen(false); periodoForm.resetFields(); await cargar();
  };

  const asociarIdentidad = (registro) => {
    let colaboradorSeleccionado;
    Modal.confirm({
      title: `Asociar DNI / Person ID ${registro.person_id}`,
      content: <Select className="mt-3 w-full" showSearch optionFilterProp="label" placeholder="Seleccionar colaborador" onChange={(value) => { colaboradorSeleccionado = value; }} options={colaboradores.map((item) => ({ value: item.id, label: `${item.nombre_completo} · ${item.documento}` }))} />,
      okText: 'Asociar y reprocesar', cancelText: 'Cancelar',
      onOk: async () => {
        if (!colaboradorSeleccionado) { message.warning('Selecciona un colaborador'); throw new Error('colaborador_requerido'); }
        const { data } = await api.post(`/asistencia/marcaciones-no-asociadas/${colaboradorSeleccionado}/asociar`, { person_id: registro.person_id });
        message.success(`${data.marcaciones_asociadas} marcaciones asociadas y reprocesadas`); await cargar();
      },
    });
  };

  const editarJornada = (resultado) => {
    const valores = { entrada: resultado.entrada ? dayjs(resultado.entrada).format('HH:mm') : '', salida: resultado.salida ? dayjs(resultado.salida).format('HH:mm') : '', estado: resultado.estado, motivo: '' };
    Modal.confirm({
      title: `Editar jornada · ${dayjs(resultado.fecha).format('DD/MM/YYYY')}`,
      width: 520,
      content: <div className="mt-4 space-y-3"><div className="grid grid-cols-2 gap-3"><TimePicker className="w-full" format="HH:mm" placeholder="--:--" allowClear defaultValue={valores.entrada ? dayjs(valores.entrada, 'HH:mm') : undefined} onChange={(_, horaTexto) => { valores.entrada = horaTexto; }} /><TimePicker className="w-full" format="HH:mm" placeholder="--:--" allowClear defaultValue={valores.salida ? dayjs(valores.salida, 'HH:mm') : undefined} onChange={(_, horaTexto) => { valores.salida = horaTexto; }} /></div><Select className="w-full" defaultValue={valores.estado} onChange={(value) => { valores.estado = value; }} options={['presente', 'falta_justificada', 'permiso', 'home_office', 'descanso', 'feriado'].map((value) => ({ value, label: value.replaceAll('_', ' ') }))} /><Input.TextArea rows={3} placeholder="Motivo obligatorio de la corrección" onChange={(event) => { valores.motivo = event.target.value; }} /></div>,
      okText: 'Guardar corrección', cancelText: 'Cancelar',
      onOk: async () => {
        if (!valores.motivo.trim()) { message.warning('Ingresa el motivo'); throw new Error('motivo_requerido'); }
        await api.patch(`/asistencia/resultados/${resultado.id}`, valores); message.success('Jornada corregida y auditada'); await cargar();
      },
    });
  };

  // V3 Fase 3 — A8/A11/A20: abre "Editar jornada" (mismo Modal.confirm que
  // ya usa la pestaña Asistencia diaria) a partir de una fila de incidencia,
  // que trae el resultado en forma cruda (entrada_at/salida_at) en vez del
  // shape simplificado (entrada/salida) que espera editarJornada().
  const editarMarcacionesDeIncidencia = (row) => {
    if (!row.resultado) { message.warning('Este día todavía no tiene un resultado procesado — usa "Reprocesar" primero.'); return; }
    editarJornada({
      id: row.resultado.id, fecha: row.fecha, estado: row.resultado.estado,
      entrada: row.resultado.entrada_at, salida: row.resultado.salida_at,
    });
  };

  const abrirModalPermiso = (row) => {
    setIncidenciaParaPermiso(row);
    permisoIncidenciaForm.resetFields();
    permisoIncidenciaForm.setFieldsValue({ tipo: 'personal', fechas: [dayjs(row.fecha), dayjs(row.fecha)] });
    setPermisoIncidenciaModalOpen(true);
  };

  // V3 Fase 3 — A8/A11/A16: crea un AsistenciaPermiso REAL y resuelve la
  // incidencia en un solo paso atómico (backend), nunca un
  // resultado.estado='permiso' de mentira.
  const resolverConPermiso = async (values) => {
    setResolviendoConPermiso(true);
    try {
      await api.patch(`/asistencia/incidencias/${incidenciaParaPermiso.id}/permiso`, {
        tipo: values.tipo,
        fecha_inicio: values.fechas[0].format('YYYY-MM-DD'),
        fecha_fin: values.fechas[1].format('YYYY-MM-DD'),
        motivo: values.motivo,
        con_goce: values.con_goce ?? undefined,
        pagador_subsidio: values.tipo === 'medico' ? (values.pagador_subsidio ?? undefined) : undefined,
      });
      message.success('Incidencia resuelta: permiso registrado y aprobado con trazabilidad');
      setPermisoIncidenciaModalOpen(false);
      await cargar();
      await cargarIncidencias();
      if (colaboradorId) {
        const perfilResponse = await api.get(`/asistencia/colaboradores/${colaboradorId}`, { params: parametros });
        setPerfil(perfilResponse.data.data ?? perfilResponse.data);
      }
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo resolver la incidencia con un permiso');
    } finally {
      setResolviendoConPermiso(false);
    }
  };

  /**
   * V3 Fase 3 — principio: un solo motor de acciones para Vista general y
   * Perfil único, mismos endpoints (Aprobar/Rechazar → PATCH incidencias/{id};
   * Resolver → editar marcaciones o permiso real). `opciones.onEditarMarcaciones`
   * permite que el Perfil abra su propio DetalleDiaModal en vez del
   * Modal.confirm que usa la Vista general — el resto es idéntico.
   */
  // Rotativo Fase 1 — "día sin clasificar" nunca usa Aprobar/Rechazar: no
  // hay nada que aprobar en un día que todavía no se sabe qué es. Reutiliza
  // solicitarDecision() tal cual (mismo payload {accion, motivo}), solo con
  // acciones de dominio ('descanso'/'laboral') en vez de aprobar/rechazar,
  // contra el nuevo endpoint /clasificar-dia. "Registrar permiso" reutiliza
  // el mismo modal de permiso ya existente para las demás incidencias.
  const accionesDiaSinClasificar = (row) => {
    const endpoint = `/asistencia/incidencias/${row.id}/clasificar-dia`;
    return <Space size={2} wrap>
      <Button type="link" size="small" onClick={() => solicitarDecision('Marcar como descanso', endpoint, 'descanso')}>Marcar descanso</Button>
      <Button type="link" size="small" onClick={() => solicitarDecision('Marcar como día laborable', endpoint, 'laboral')}>Marcar laborable</Button>
      <Button type="link" size="small" onClick={() => abrirModalPermiso(row)}>Registrar permiso</Button>
    </Space>;
  };

  // Fase 3.1 — resuelve trabajo_en_descanso con 3 acciones de dominio,
  // nunca Aprobar/Rechazar genérico (no hay nada que "aprobar" en la
  // incidencia misma — lo que se decide es qué pasa con la HE 100%
  // asociada y, si corresponde, con la planificación). Reutiliza
  // solicitarDecision() para "pago" (solo motivo); sustitutorio/corregir
  // necesitan un campo extra, así que siguen el mismo patrón de
  // Modal.confirm con variable mutable que ya usan editarJornada()/
  // asociarIdentidad() en este mismo archivo.
  const abrirSustitutorio = (row) => {
    // Fase 3.2.1 — ciclo de 7 días desde el día trabajado (Informe N°
    // 027-2021-MTPE/14: "semana" para el descanso es un ciclo de 7 días,
    // no necesariamente la semana calendario lunes-domingo), no la semana
    // calendario. El sustitutorio siempre es posterior al día trabajado.
    const diaTrabajado = dayjs(row.fecha);
    const limiteCiclo = diaTrabajado.add(6, 'day');
    const valores = { fecha_sustitutoria: null, motivo: '' };
    Modal.confirm({
      title: 'Otorgar descanso sustitutorio',
      width: 480,
      content: <div className="mt-3 space-y-3">
        <Alert type="info" showIcon message={`Debe caer entre ${diaTrabajado.add(1, 'day').format('DD/MM')} y ${limiteCiclo.format('DD/MM/YYYY')} — ciclo de 7 días desde el descanso trabajado.`} />
        <DatePicker
          className="w-full" format="DD/MM/YYYY" placeholder="Fecha del descanso sustitutorio"
          disabledDate={(d) => !d.isAfter(diaTrabajado, 'day') || d.isAfter(limiteCiclo, 'day')}
          onChange={(value) => { valores.fecha_sustitutoria = value ? value.format('YYYY-MM-DD') : null; }}
        />
        <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(event) => { valores.motivo = event.target.value; }} />
      </div>,
      okText: 'Confirmar', cancelText: 'Cancelar',
      onOk: async () => {
        if (!valores.fecha_sustitutoria) { message.warning('Selecciona la fecha del descanso sustitutorio'); throw new Error('fecha_requerida'); }
        if (!valores.motivo.trim()) { message.warning('Ingresa el motivo'); throw new Error('motivo_requerido'); }
        await api.patch(`/asistencia/incidencias/${row.id}/resolver-trabajo-descanso`, {
          accion: 'sustitutorio', fecha_sustitutoria: valores.fecha_sustitutoria, motivo: valores.motivo.trim(),
        });
        message.success('Descanso sustitutorio registrado');
        await cargar();
        await cargarIncidencias();
      },
    });
  };

  const abrirCorregirPlanificacion = (row) => {
    const valores = { tipo: 'laborable_presencial', motivo: '' };
    Modal.confirm({
      title: 'Corregir planificación',
      width: 480,
      content: <div className="mt-3 space-y-3">
        <Alert type="warning" showIcon message="El día se recalculará por completo con el motor normal de asistencia (puede quedar presente, con tardanza, HD, HI, etc.)." />
        <Select
          className="w-full" defaultValue={valores.tipo} onChange={(value) => { valores.tipo = value; }}
          options={[{ value: 'laborable_presencial', label: 'Trabajo presencial' }, { value: 'home_office', label: 'Home office' }]}
        />
        <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(event) => { valores.motivo = event.target.value; }} />
      </div>,
      okText: 'Confirmar', cancelText: 'Cancelar',
      onOk: async () => {
        if (!valores.motivo.trim()) { message.warning('Ingresa el motivo'); throw new Error('motivo_requerido'); }
        await api.patch(`/asistencia/incidencias/${row.id}/resolver-trabajo-descanso`, {
          accion: 'corregir_planificacion', tipo: valores.tipo, motivo: valores.motivo.trim(),
        });
        message.success('Planificación corregida y jornada reprocesada');
        await cargar();
        await cargarIncidencias();
      },
    });
  };

  const accionesTrabajoEnDescanso = (row) => <Space size={2} wrap>
    <Button type="link" size="small" onClick={() => solicitarDecision('Corresponde pago', `/asistencia/incidencias/${row.id}/resolver-trabajo-descanso`, 'pago')}>Corresponde pago</Button>
    <Button type="link" size="small" onClick={() => abrirSustitutorio(row)}>Descanso sustitutorio</Button>
    <Button type="link" size="small" onClick={() => abrirCorregirPlanificacion(row)}>Corregir planificación</Button>
  </Space>;

  const accionesIncidencia = (row, opciones = {}) => {
    if (row.estado !== 'pendiente' || !puedeResolverIncidencias) return '—';
    if (row.tipo === 'dia_sin_clasificar') return accionesDiaSinClasificar(row);
    if (row.tipo === 'trabajo_en_descanso') return accionesTrabajoEnDescanso(row);
    const onEditarMarcaciones = opciones.onEditarMarcaciones ?? (() => editarMarcacionesDeIncidencia(row));
    const advertencia = row.tipo === 'horario_desplazado'
      ? 'Al aprobar, el día quedará como presente sin descuento de tardanza.'
      : row.tipo === 'horas_incompletas'
        ? 'Al aprobar, el día será remunerado según las horas efectivamente trabajadas.'
        : null;
    const menuResolver = {
      items: [
        { key: 'marcaciones', label: 'Editar marcaciones' },
        { key: 'permiso', label: 'Registrar permiso' },
      ],
      onClick: ({ key }) => (key === 'marcaciones' ? onEditarMarcaciones(row) : abrirModalPermiso(row)),
    };
    return <Space size={2}>
      <Button type="link" size="small" onClick={() => solicitarDecision('Aprobar incidencia', `/asistencia/incidencias/${row.id}`, 'aprobar', {}, advertencia)}>Aprobar</Button>
      <Button type="link" size="small" danger onClick={() => solicitarDecision('Rechazar incidencia', `/asistencia/incidencias/${row.id}`, 'rechazar')}>Rechazar</Button>
      <Dropdown menu={menuResolver}><Button type="link" size="small">Resolver <DownOutlined /></Button></Dropdown>
    </Space>;
  };

  const fechasVisibles = useMemo(() => {
    const dias = [];
    const inicio = rango[0].isAfter(rango[1].subtract(6, 'day')) ? rango[0] : rango[1].subtract(6, 'day');
    for (let fecha = inicio; fecha.isBefore(rango[1]) || fecha.isSame(rango[1], 'day'); fecha = fecha.add(1, 'day')) dias.push(fecha);
    return dias;
  }, [rango]);

  const columnasColaboradores = useMemo(() => [
    { title: 'Colaborador', dataIndex: 'nombre_completo', width: 190, render: (nombre, row) => <div className="leading-tight"><button type="button" className="block max-w-full truncate text-left text-[12px] font-medium text-slate-900 hover:text-blue-600" onClick={() => onVerColaborador?.(row.id)}>{nombre}</button><Text type="secondary" className="block truncate text-[10px]">{row.legajo} · {row.cargo}</Text></div> },
    { title: 'Documento', dataIndex: 'documento', width: 88, responsive: ['sm'] },
    { title: 'ID biométrico', dataIndex: 'person_id', width: 92, responsive: ['lg'], render: (value) => value ?? '—' },
    { title: 'Empresa', dataIndex: 'empresa', width: 145, responsive: ['xl'], ellipsis: true },
    { title: 'Sede', dataIndex: 'sede', width: 118, responsive: ['lg'], ellipsis: true, render: (value) => value ?? '—' },
    { title: 'Área', dataIndex: 'area', width: 115, responsive: ['md'], ellipsis: true, render: (value) => value ?? '—' },
    { title: 'Horario', dataIndex: 'horario', width: 165, responsive: ['md'], ellipsis: true, render: (value) => value ?? <Text type="danger">Sin asignar</Text> },
    { title: 'Preparación', width: 72, responsive: ['sm'], align: 'center', render: (_, row) => <Tag className="!m-0" color={row.tiene_horario && row.tiene_calendario ? 'green' : 'red'}>{row.tiene_horario && row.tiene_calendario ? 'OK' : 'HC'}</Tag> },
    {
      title: 'Incid.',
      dataIndex: 'incidencias_pendientes',
      width: 48,
      responsive: ['md'],
      align: 'center',
      render: (valor, row) => (
        valor > 0 ? (
          <button
            type="button"
            className="font-semibold text-red-500 underline-offset-2 hover:underline"
            title={`Ver incidencias de ${row.nombre_completo}`}
            onClick={() => { setColaboradorFiltroIncidencia(row.id); setFiltroEstadoIncidencia('todos'); setActiveTab('incidencias'); }}
          >
            {valor}
          </button>
        ) : valor
      ),
    },
    ...fechasVisibles.map((fecha, index) => ({ title: <div className="text-center text-[10px] capitalize leading-3">{fecha.format('dd').slice(0, 2)}<br />{fecha.format('DD')}</div>, key: fecha.format('YYYY-MM-DD'), width: 36, responsive: index < fechasVisibles.length - 3 ? ['lg'] : undefined, align: 'center', render: (_, row) => <EstadoDia resultado={row.resultados?.[fecha.format('YYYY-MM-DD')]} /> })),
    { title: 'Acciones', key: 'acciones', width: 66, align: 'center', render: (_, row) => <Button type="text" size="small" icon={<EyeOutlined />} aria-label={`Ver asistencia de ${row.nombre_completo}`} title="Ver perfil de asistencia" onClick={() => onVerColaborador?.(row.id)} /> },
  ], [fechasVisibles, onVerColaborador]);

  const opcionesSede = useMemo(() => [...new Set(colaboradores.map((item) => item.sede).filter(Boolean))].sort().map((value) => ({ value, label: value })), [colaboradores]);
  const opcionesArea = useMemo(() => [...new Set(colaboradores.map((item) => item.area).filter(Boolean))].sort().map((value) => ({ value, label: value })), [colaboradores]);

  const colaboradoresFiltrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    return colaboradores.filter((item) => {
      const coincideTexto = !texto || [item.nombre_completo, item.documento, item.legajo, item.area]
        .some((value) => String(value ?? '').toLowerCase().includes(texto));
      const coincidePreparacion = filtroPreparacion === 'todos'
        || (filtroPreparacion === 'listos' && item.tiene_horario && item.tiene_calendario)
        || (filtroPreparacion === 'sin_horario' && !item.tiene_horario)
        || (filtroPreparacion === 'sin_calendario' && !item.tiene_calendario)
        || (filtroPreparacion === 'sin_biometrico' && !item.person_id);
      return coincideTexto && (!filtroSede || item.sede === filtroSede)
        && (!filtroArea || item.area === filtroArea) && coincidePreparacion;
    });
  }, [busqueda, colaboradores, filtroArea, filtroPreparacion, filtroSede]);

  const columnasResumen = useMemo(() => [
    { title: 'Colaborador', dataIndex: ['colaborador', 'nombre_completo'], render: (nombre, row) => <div><div className="font-medium">{nombre}</div><Text type="secondary" className="text-xs">{row.colaborador.legajo} · {row.colaborador.area ?? 'Sin área'}</Text></div> },
    { title: 'Fecha', dataIndex: 'fecha', width: 115, render: (value) => dayjs(value).format('DD/MM/YYYY') },
    { title: 'Entrada', dataIndex: 'entrada', width: 90, render: hora }, { title: 'Salida', dataIndex: 'salida', width: 90, render: hora },
    { title: 'Trabajado', dataIndex: 'minutos_trabajados', width: 115, render: duracion }, { title: 'Tardanza', dataIndex: 'minutos_tardanza', width: 105, render: duracion },
    { title: 'H. extra', dataIndex: 'minutos_extra_observados', width: 105, render: duracion },
    { title: 'Estado', dataIndex: 'estado', width: 170, render: (value) => <Tag color={ESTADOS[value]?.[2]}>{ESTADOS[value]?.[1] ?? value}</Tag> },
    { title: 'Acciones', width: 90, render: (_, row) => puedeResolverIncidencias ? <Button type="link" size="small" onClick={() => editarJornada(row)}>Editar</Button> : '—' },
  ], []);

  const resumen = <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
    <Card size="small"><Statistic title="Jornadas procesadas" value={stats.jornadas ?? 0} prefix={<TeamOutlined />} /></Card>
    <Card size="small"><Statistic title="Presentes" value={stats.presentes ?? 0} prefix={<CheckCircleOutlined className="text-green-600" />} /></Card>
    <Card size="small"><Statistic title="Faltas" value={stats.faltas ?? 0} prefix={<ExclamationCircleOutlined className="text-red-500" />} /></Card>
    <Card size="small"><Statistic title="Tardanzas computables" value={stats.tardanzas ?? 0} prefix={<ClockCircleOutlined className="text-amber-500" />} /></Card>
    <Card size="small"><Statistic title="Trabajo remoto" value={stats.home_office ?? 0} prefix={<TeamOutlined className="text-blue-500" />} /></Card>
    <Card size="small"><Statistic title="Incidencias pendientes" value={stats.incidencias_pendientes ?? 0} prefix={<ExclamationCircleOutlined className="text-rose-500" />} /></Card>
    <Card size="small"><Statistic title="Horas extra observadas" value={duracion(stats.minutos_extra_observados)} prefix={<ClockCircleOutlined className="text-violet-500" />} /></Card>
  </div>;

  const asistenciaDiaria = <Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="id" columns={columnasResumen} dataSource={resultados} loading={loading} scroll={{ x: 1000 }} pagination={{ pageSize: 20, size: 'small' }} locale={{ emptyText: <Empty description="No hay jornadas procesadas en este período" /> }} /></Card>;

  const vistaColaboradores = <div className="space-y-4">
    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">{[
      ['Total', statsColaboradores.total, 'text-slate-700', 'bg-slate-100', <TeamOutlined key="total" />],
      ['Listos', statsColaboradores.listos, 'text-emerald-600', 'bg-emerald-50', <CheckCircleOutlined key="listos" />],
      ['Falta horario', statsColaboradores.falta_horario, 'text-orange-500', 'bg-orange-50', <ExclamationCircleOutlined key="horario" />],
      ['Falta calendario', statsColaboradores.falta_calendario, 'text-amber-500', 'bg-amber-50', <CalendarOutlined key="calendario" />],
      ['Incidencias', statsColaboradores.incidencias, 'text-rose-500', 'bg-rose-50', <ExclamationCircleOutlined key="incidencias" />],
      ['Marc. incompletas', statsColaboradores.marcaciones_incompletas, 'text-orange-500', 'bg-orange-50', <ClockCircleOutlined key="incompletas" />],
      ['Horas extra pend.', statsColaboradores.horas_extra_pendientes, 'text-blue-600', 'bg-blue-50', <ClockCircleOutlined key="extras" />],
    ].map(([titulo, valor, color, fondo, icono]) => <div className="flex h-[58px] min-w-0 items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition-colors hover:border-slate-300" key={titulo}><div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm ${fondo} ${color}`}>{icono}</div><div className="min-w-0"><div className={`text-[15px] font-semibold leading-[18px] tabular-nums ${color}`}>{valor ?? 0}</div><Text type="secondary" className="block truncate text-[11px] leading-4">{titulo}</Text></div></div>)}</div>
    <div className="flex flex-nowrap items-center gap-2 overflow-x-auto pb-1">
      <Input size="middle" prefix={<SearchOutlined />} placeholder="Buscar nombre, documento, legajo..." value={busqueda} onChange={(event) => setBusqueda(event.target.value)} className="min-w-72 flex-1" allowClear />
      <EmpresaActivaFiltro user={user} onUserRefresh={onUserRefresh} />
      <Select size="middle" allowClear placeholder="Sede" value={filtroSede} onChange={setFiltroSede} options={opcionesSede} className="w-32 shrink-0" />
      <Select size="middle" allowClear placeholder="Área" value={filtroArea} onChange={setFiltroArea} options={opcionesArea} className="w-32 shrink-0" />
      <Select size="middle" value={filtroPreparacion} onChange={setFiltroPreparacion} className="w-40 shrink-0" options={[{ value: 'todos', label: 'Todos' }, { value: 'listos', label: 'Listos' }, { value: 'sin_horario', label: 'Sin horario' }, { value: 'sin_calendario', label: 'Sin calendario' }, { value: 'sin_biometrico', label: 'Sin ID biométrico' }]} />
      {(busqueda || filtroSede || filtroArea || filtroPreparacion !== 'todos') && <Button type="text" size="small" onClick={() => { setBusqueda(''); setFiltroSede(); setFiltroArea(); setFiltroPreparacion('todos'); }}>Limpiar</Button>}
    </div>
    <div className="flex flex-wrap items-center gap-1.5 text-xs"><Text type="secondary" className="mr-1">Leyenda</Text>{Object.entries(ESTADOS).map(([key, estado]) => <Tag className="!m-0" color={estado[2]} key={key}>{estado[0]} {estado[1]}</Tag>)}<Text type="secondary" className="ml-auto">{colaboradoresFiltrados.length} colaboradores</Text></div>
    <Card className="overflow-hidden border-slate-200" styles={{ body: { padding: 0 } }}><Table size="small" tableLayout="fixed" rowKey="id" columns={columnasColaboradores} dataSource={colaboradoresFiltrados} loading={loading} rowClassName="hover:bg-slate-50" pagination={{ pageSize: 25, size: 'small', showSizeChanger: false, showTotal: (total) => `${total} colaboradores` }} locale={{ emptyText: <Empty description="No hay colaboradores para los filtros seleccionados" /> }} /></Card>
  </div>;

  const importar = <div className="space-y-5">
    {!previsualizacion && <Upload.Dragger accept=".xlsx" multiple={false} beforeUpload={prepararArchivo} showUploadList={false} disabled={importandoMarcaciones} className="!block max-w-3xl"><p className="ant-upload-drag-icon"><UploadOutlined /></p><p className="ant-upload-text">{importandoMarcaciones ? 'Analizando archivo...' : 'Arrastra el archivo Transaction aquí o haz clic para seleccionarlo'}</p><p className="ant-upload-hint">Formato XLSX · máximo 10 MB · primero se mostrará una validación</p></Upload.Dragger>}
    {previsualizacion && <Card className="max-w-3xl border-slate-200"><div className="space-y-4">
      <div className="flex items-center gap-3"><FileExcelOutlined className="text-xl text-emerald-600" /><span className="font-semibold">{previsualizacion.archivo_nombre}</span><Tag color="green">{(previsualizacion.archivo_tamano / 1024).toFixed(1)} KB</Tag><Button type="link" size="small" className="ml-auto" onClick={() => { setArchivoPendiente(null); setPrevisualizacion(null); }}>Cambiar archivo</Button></div>
      <div className="grid grid-cols-2 gap-x-8 gap-y-3 md:grid-cols-3">
        {[['Período', `${previsualizacion.fecha_minima} → ${previsualizacion.fecha_maxima}`], ['Registros válidos', previsualizacion.registros_validos], ['Nuevos estimados', previsualizacion.registros_nuevos_estimados], ['Idénticos existentes', previsualizacion.registros_duplicados], ['DNI reconocidos', previsualizacion.colaboradores_reconocidos], ['DNI sin coincidencia', previsualizacion.person_ids_sin_coincidencia], ['Registros inválidos', previsualizacion.registros_invalidos]].map(([label, value]) => <div key={label}><Text type="secondary" className="block text-xs">{label}</Text><span className="font-semibold">{value}</span></div>)}
      </div>
      {previsualizacion.person_ids_sin_coincidencia > 0 && <Alert type="warning" showIcon message={`${previsualizacion.person_ids_sin_coincidencia} colaboradores no registrados`} description={`${previsualizacion.marcaciones_sin_coincidencia} marcaciones quedarán pendientes de vinculación.`} />}
      <div className="rounded-lg border border-slate-200 p-3"><div className="mb-2 text-xs font-medium">Validación por DNI y preparación</div><div className="grid grid-cols-5 gap-2">{[['Listos', previsualizacion.preparacion.listos, 'bg-emerald-50 text-emerald-700'], ['Falta horario', previsualizacion.preparacion.falta_horario, 'bg-amber-50 text-amber-700'], ['Falta calendario', previsualizacion.preparacion.falta_calendario, 'bg-orange-50 text-orange-700'], ['Faltan ambos', previsualizacion.preparacion.faltan_ambos, 'bg-rose-50 text-rose-700'], ['DNI no reconocidos', previsualizacion.person_ids_sin_coincidencia, 'bg-slate-50 text-slate-700']].map(([label, value, color]) => <div className={`rounded-md px-2 py-2 text-center ${color}`} key={label}><div className="font-semibold">{value}</div><div className="text-[10px]">{label}</div></div>)}</div></div>
      <Alert type="warning" showIcon message={`Se importarán ${previsualizacion.registros_validos.toLocaleString('es-PE')} marcaciones.`} description="Las marcaciones no reconocidas se conservarán pendientes de asociación. Solo se calculará asistencia para colaboradores preparados." />
      <Button type="primary" block size="large" loading={importandoMarcaciones} onClick={confirmarImportacion}>Confirmar importación ({previsualizacion.registros_validos.toLocaleString('es-PE')} registros)</Button>
    </div></Card>}
    {noAsociadas.length > 0 && <div className="space-y-2"><div><Title level={5} className="!mb-0">DNI del reloj sin coincidencia</Title><Text type="secondary" className="text-xs">El Person ID del Excel se compara exactamente con el número de documento del colaborador. Corrige el DNI en el padrón o en el reloj y vuelve a importar.</Text></div><Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="person_id" dataSource={noAsociadas} columns={[
      { title: 'DNI / Person ID', dataIndex: 'person_id', width: 140 },
      { title: 'Nombre en el reloj', dataIndex: 'nombre', ellipsis: true },
      { title: 'Marcaciones', dataIndex: 'marcaciones', width: 100, align: 'center' },
      { title: 'Período', width: 190, render: (_, row) => `${dayjs(row.desde).format('DD/MM/YYYY')} - ${dayjs(row.hasta).format('DD/MM/YYYY')}` },
      { title: 'Estado', width: 150, render: () => <Tag color="orange">DNI no registrado</Tag> },
      { title: 'Acciones', width: 130, render: (_, row) => <Button size="small" type="link" onClick={() => asociarIdentidad(row)}>Asociar</Button> },
    ]} pagination={{ pageSize: 10 }} /></Card></div>}
  </div>;
  const permisosFiltrados = estadoPermiso === 'todos' ? permisos : permisos.filter((permiso) => permiso.estado === estadoPermiso);
  const vistaPermisos = <div className="space-y-4">
    <div className="flex items-end gap-3"><div><Text type="secondary" className="mb-1 block text-xs">Estado</Text><Select value={estadoPermiso} onChange={setEstadoPermiso} className="w-44" options={[{ value: 'todos', label: 'Todos' }, { value: 'aprobado', label: 'Aprobados' }, { value: 'pendiente', label: 'Pendientes' }, { value: 'rechazado', label: 'Rechazados' }]} /></div>{puedeGestionarPermisos && <Button type="primary" icon={<PlusOutlined />} onClick={() => setPermisoModalOpen(true)}>Nuevo permiso</Button>}</div>
    <Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="id" dataSource={permisosFiltrados} columns={[
      { title: 'Colaborador', dataIndex: ['colaborador', 'nombre_completo'], render: (value, row) => <div><div className="font-medium">{value}</div><Text type="secondary" className="text-xs">{row.colaborador.legajo} · {row.colaborador.area ?? 'Sin área'}</Text></div> },
      { title: 'Tipo', dataIndex: 'tipo', render: (value) => value.replaceAll('_', ' ') },
      { title: 'Fecha inicio', dataIndex: 'fecha_inicio', render: (value) => dayjs(value).format('DD/MM/YYYY') },
      { title: 'Fecha fin', dataIndex: 'fecha_fin', render: (value) => dayjs(value).format('DD/MM/YYYY') },
      { title: 'Motivo', dataIndex: 'motivo', ellipsis: true },
      { title: 'Estado', dataIndex: 'estado', width: 110, render: (value) => <Tag color={value === 'aprobado' ? 'green' : value === 'rechazado' ? 'red' : 'gold'}>{value}</Tag> },
      { title: 'Acciones', width: 160, render: (_, row) => row.estado === 'pendiente' && puedeGestionarPermisos ? <Space size={2}><Button type="link" size="small" onClick={() => solicitarDecision('Aprobar permiso', `/asistencia/permisos/${row.id}`, 'aprobar')}>Aprobar</Button><Button type="link" size="small" danger onClick={() => solicitarDecision('Rechazar permiso', `/asistencia/permisos/${row.id}`, 'rechazar')}>Rechazar</Button></Space> : '—' },
    ]} pagination={{ pageSize: 15 }} locale={{ emptyText: <Empty image={<SafetyCertificateOutlined className="text-4xl text-slate-300" />} description="No hay permisos registrados" /> }} /></Card>
  </div>;
  const solicitudesFiltradas = estadoSolicitud === 'todos' ? solicitudesArea : solicitudesArea.filter((item) => item.estado === estadoSolicitud);
  const vistaGestionesArea = <div className="space-y-4">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><div className="flex items-center gap-2"><UsergroupAddOutlined /><span className="font-semibold">Gestiones del área</span><Tag color="green">Vista RR.HH.</Tag></div><Text type="secondary" className="text-xs">Solicitudes y programaciones de los colaboradores</Text></div>{puedeGestionarArea && <Button type="primary" icon={<PlusOutlined />} onClick={() => setSolicitudModalOpen(true)}>Programar / Solicitar</Button>}</div>
    <div className="flex flex-wrap gap-2">
      {[['todos', 'Todas', <SendOutlined key="todas" />], ['pendiente_responsable', 'Pend. responsable', <ExclamationCircleOutlined key="responsable" />], ['pendiente_rrhh', 'Pend. RR.HH.', <SafetyCertificateOutlined key="rrhh" />], ['finalizada', 'Historial', <HistoryOutlined key="historial" />]].map(([value, label, icon]) => <Button key={value} type={estadoSolicitud === value ? 'primary' : 'default'} ghost={estadoSolicitud === value} icon={icon} onClick={() => setEstadoSolicitud(value)}>{label}</Button>)}
    </div>
    <Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="id" dataSource={solicitudesFiltradas} columns={[
      { title: 'Fecha', dataIndex: 'fecha_inicio', width: 105, render: (value, row) => row.fecha_inicio === row.fecha_fin ? dayjs(value).format('DD/MM/YYYY') : `${dayjs(value).format('DD/MM')} - ${dayjs(row.fecha_fin).format('DD/MM/YYYY')}` },
      { title: 'Tipo', dataIndex: 'tipo', width: 140, render: (value) => value.replaceAll('_', ' ') },
      { title: 'Colaboradores', dataIndex: 'colaboradores', render: (items) => <div>{items.slice(0, 2).map((item) => item.nombre_completo).join(', ')}{items.length > 2 ? ` y ${items.length - 2} más` : ''}</div> },
      { title: 'Área', dataIndex: 'area', width: 150, render: (value) => value ?? 'Varias áreas' },
      { title: 'Motivo', dataIndex: 'motivo', ellipsis: true },
      { title: 'Estado', dataIndex: 'estado', width: 155, render: (value) => <Tag color={value === 'finalizada' ? 'green' : value === 'rechazada' ? 'red' : 'gold'}>{value.replaceAll('_', ' ')}</Tag> },
      { title: 'Acciones', width: 160, render: (_, row) => row.estado.includes('pendiente') ? <Space size={4}><Button size="small" type="link" onClick={() => solicitarDecision('Aprobar solicitud', `/asistencia/gestiones-area/${row.id}`, 'aprobar')}>Aprobar</Button><Button size="small" type="link" danger onClick={() => solicitarDecision('Rechazar solicitud', `/asistencia/gestiones-area/${row.id}`, 'rechazar')}>Rechazar</Button></Space> : '—' },
    ]} pagination={{ pageSize: 15 }} locale={{ emptyText: <Empty description="No hay solicitudes registradas" /> }} /></Card>
  </div>;

  const vistaIncidencias = <div className="space-y-2">
    <div className="flex flex-wrap items-center gap-2">
      <Input
        size="small"
        prefix={<SearchOutlined />}
        placeholder="Buscar colaborador (nombre, documento, legajo)"
        value={busqueda}
        onChange={(event) => setBusqueda(event.target.value)}
        allowClear
        className="w-72"
      />
      <Select size="small" className="w-40" value={filtroEstadoIncidencia} onChange={setFiltroEstadoIncidencia} options={[
        { value: 'todos', label: 'Todos los estados' }, { value: 'pendiente', label: 'Pendiente' },
        { value: 'resuelta', label: 'Resuelta' }, { value: 'rechazada', label: 'Rechazada' }, { value: 'observada', label: 'Observada' },
      ]} />
      <Text type="secondary" className="text-xs">Sede y área usan los filtros de la pestaña Colaboradores.</Text>
      {colaboradorFiltroIncidencia && (
        <Tag closable onClose={() => setColaboradorFiltroIncidencia(null)} color="blue">
          Filtrando por colaborador #{colaboradorFiltroIncidencia}
        </Tag>
      )}
    </div>
    <Card styles={{ body: { padding: 0 } }}><Table
      size="small"
      rowKey="id"
      dataSource={incidencias}
      expandable={{
        rowExpandable: (row) => ['horario_desplazado', 'horas_incompletas'].includes(row.tipo) && Boolean(row.resultado),
        expandedRowRender: (row) => {
          const r = row.resultado;
          const campos = row.tipo === 'horario_desplazado'
            ? [['Entrada marcada', hora(r.entrada_at)], ['Salida marcada', hora(r.salida_at)], ['Horas programadas', duracion(r.minutos_programados)], ['Horas trabajadas', duracion(r.minutos_trabajados)], ['Tardanza detectada', duracion(r.minutos_tardanza)]]
            : [['Entrada marcada', hora(r.entrada_at)], ['Salida marcada', hora(r.salida_at)], ['Horas programadas', duracion(r.minutos_programados)], ['Horas trabajadas', duracion(r.minutos_trabajados)], ['Horas incompletas', duracion(r.minutos_salida_anticipada)]];
          return <div className="grid grid-cols-2 gap-x-6 gap-y-1 py-1 text-xs sm:grid-cols-5">{campos.map(([label, value]) => <div key={label}><Text type="secondary" className="block">{label}</Text><span className="font-medium">{value}</span></div>)}</div>;
        },
      }}
      columns={[
      { title: 'Fecha', dataIndex: 'fecha', width: 110, render: (value) => dayjs(value).format('DD/MM/YYYY') },
      { title: 'Colaborador', render: (_, row) => `${row.colaborador?.nombres ?? ''} ${row.colaborador?.apellidos ?? ''}`.trim() },
      { title: 'Tipo', dataIndex: 'tipo', width: 170, render: (value) => value.replaceAll('_', ' ') },
      { title: 'Descripción', dataIndex: 'descripcion', ellipsis: true },
      { title: 'Estado', dataIndex: 'estado', width: 115, render: (value) => <Tag color={value === 'pendiente' ? 'gold' : value === 'resuelta' ? 'green' : 'red'}>{value}</Tag> },
      { title: 'Acciones', width: 210, render: (_, row) => accionesIncidencia(row) },
    ]} pagination={{ pageSize: 20, size: 'small' }} locale={{ emptyText: <Empty description="No hay incidencias en el período" /> }} /></Card>
  </div>;

  const colaboradoresConHorasExtra = Array.from(new Map(horasExtra.map((row) => [
    row.colaborador?.id,
    {
      value: row.colaborador?.id,
      label: `${row.colaborador?.nombres ?? ''} ${row.colaborador?.apellidos ?? ''}`.trim(),
    },
  ])).values()).filter((opcion) => opcion.value).sort((a, b) => a.label.localeCompare(b.label));

  const horasExtraFiltradas = horasExtra.filter((row) => (
    (!colaboradorFiltroHorasExtra || row.colaborador?.id === colaboradorFiltroHorasExtra)
    && (estadoFiltroHorasExtra === 'todos' || row.estado === estadoFiltroHorasExtra)
  ));

  const resolverHorasExtraMasivo = (accion) => {
    let motivo = '';
    Modal.confirm({
      title: accion === 'aprobar' ? 'Aprobar horas extra seleccionadas' : 'Rechazar horas extra seleccionadas',
      content: (
        <div className="mt-3 space-y-2">
          <Alert
            type="info"
            showIcon
            message={`${horasExtraSeleccionadas.length} registro(s) seleccionado(s)`}
            description={accion === 'aprobar' ? 'Se aprobarán todos los minutos observados de cada registro.' : undefined}
          />
          <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(event) => { motivo = event.target.value; }} />
        </div>
      ),
      okText: accion === 'aprobar' ? 'Aprobar' : 'Rechazar',
      okButtonProps: { danger: accion === 'rechazar' },
      cancelText: 'Cancelar',
      onOk: async () => {
        if (!motivo.trim()) { message.warning('Ingresa el motivo de la decisión'); throw new Error('motivo_requerido'); }
        await api.patch('/asistencia/horas-extra', { ids: horasExtraSeleccionadas, accion, motivo: motivo.trim() });
        message.success(`${horasExtraSeleccionadas.length} registro(s) procesado(s)`);
        setHorasExtraSeleccionadas([]);
        await cargar();
      },
    });
  };

  const vistaHorasExtra = <div className="space-y-3">
    <div className="flex flex-wrap items-center gap-2">
      <EmpresaActivaFiltro user={user} onUserRefresh={onUserRefresh} />
      <Select
        allowClear
        showSearch
        optionFilterProp="label"
        className="w-80"
        placeholder="Filtrar por colaborador"
        value={colaboradorFiltroHorasExtra}
        onChange={(value) => { setColaboradorFiltroHorasExtra(value); setHorasExtraSeleccionadas([]); }}
        options={colaboradoresConHorasExtra}
      />
      <Select
        className="w-40"
        value={estadoFiltroHorasExtra}
        onChange={(value) => { setEstadoFiltroHorasExtra(value); setHorasExtraSeleccionadas([]); }}
        options={[
          { value: 'todos', label: 'Todos los estados' },
          { value: 'pendiente', label: 'Pendientes' },
          { value: 'aprobado', label: 'Aprobadas' },
          { value: 'rechazado', label: 'Rechazadas' },
        ]}
      />
      {puedeGestionarHorasExtra && horasExtraSeleccionadas.length > 0 && <>
        <Button type="primary" onClick={() => resolverHorasExtraMasivo('aprobar')}>Aprobar seleccionadas</Button>
        <Button danger onClick={() => resolverHorasExtraMasivo('rechazar')}>Rechazar seleccionadas</Button>
        <Text type="secondary">{horasExtraSeleccionadas.length} seleccionada(s)</Text>
      </>}
    </div>
    <Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="id" dataSource={horasExtraFiltradas} rowSelection={puedeGestionarHorasExtra ? {
      selectedRowKeys: horasExtraSeleccionadas,
      onChange: setHorasExtraSeleccionadas,
      getCheckboxProps: (row) => ({ disabled: row.estado !== 'pendiente' }),
    } : undefined} columns={[
    { title: 'Fecha', dataIndex: 'fecha', width: 110, render: (value) => dayjs(value).format('DD/MM/YYYY') },
    { title: 'Colaborador', render: (_, row) => `${row.colaborador?.nombres ?? ''} ${row.colaborador?.apellidos ?? ''}`.trim() },
    { title: 'Tasa', dataIndex: 'tasa', width: 75, render: (value) => <Tag>{value}%</Tag> },
    { title: 'Observadas', dataIndex: 'minutos_observados', width: 105, render: duracion },
    { title: 'Aprobadas', dataIndex: 'minutos_aprobados', width: 105, render: duracion },
    { title: 'Estado', dataIndex: 'estado', width: 110, render: (value) => <Tag color={value === 'pendiente' ? 'gold' : value === 'aprobado' ? 'green' : 'red'}>{value}</Tag> },
    { title: 'Motivo', dataIndex: 'motivo', ellipsis: true, render: (value) => value || '—' },
    { title: 'Acciones', width: 160, render: (_, row) => row.estado === 'pendiente' && puedeGestionarHorasExtra ? <Space size={2}><Button type="link" size="small" onClick={() => solicitarDecision('Aprobar horas extra', `/asistencia/horas-extra/${row.id}`, 'aprobar', { minutos_aprobados: row.minutos_observados })}>Aprobar</Button><Button type="link" size="small" danger onClick={() => solicitarDecision('Rechazar horas extra', `/asistencia/horas-extra/${row.id}`, 'rechazar')}>Rechazar</Button></Space> : '—' },
  ]} pagination={{ pageSize: 20, size: 'small' }} locale={{ emptyText: <Empty description="No hay horas extra para el filtro seleccionado" /> }} /></Card>
  </div>;

  const vistaImportaciones = <Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="id" dataSource={importaciones} columns={[
    { title: 'Archivo', dataIndex: 'archivo_nombre', ellipsis: true },
    { title: 'Período', width: 210, render: (_, row) => `${dayjs(row.fecha_minima).format('DD/MM/YYYY')} – ${dayjs(row.fecha_maxima).format('DD/MM/YYYY')}` },
    { title: 'Total', dataIndex: 'filas_totales', width: 80 }, { title: 'Nuevas', dataIndex: 'filas_nuevas', width: 80 },
    { title: 'Idénticas', dataIndex: 'filas_duplicadas', width: 90 }, { title: 'Observadas', dataIndex: 'filas_observadas', width: 95 },
    { title: 'Estado', dataIndex: 'estado', width: 110, render: (value) => <Tag color={value === 'completada' ? 'green' : 'gold'}>{value}</Tag> },
  ]} pagination={{ pageSize: 15, size: 'small' }} locale={{ emptyText: <Empty description="No hay importaciones registradas" /> }} /></Card>;

  const vistaPeriodos = <div className="space-y-3">{puedeGestionarPeriodos && <div className="flex justify-end"><Button type="primary" icon={<PlusOutlined />} onClick={() => setPeriodoModalOpen(true)}>Nuevo período</Button></div>}<Card styles={{ body: { padding: 0 } }}><Table size="small" rowKey="id" dataSource={periodos} columns={[
    { title: 'Desde', dataIndex: 'fecha_inicio', render: (value) => dayjs(value).format('DD/MM/YYYY') },
    { title: 'Hasta', dataIndex: 'fecha_fin', render: (value) => dayjs(value).format('DD/MM/YYYY') },
    { title: 'Versión', dataIndex: 'version', width: 80 },
    { title: 'Estado', dataIndex: 'estado', render: (value, row) => <Space size={4}><Tag color={value === 'abierto' ? 'green' : value === 'cerrado' ? 'gold' : 'blue'}>{value.replaceAll('_', ' ')}</Tag>{row.cobertura_estado === 'en_proceso' && <Tag icon={<ReloadOutlined spin />} color="processing">verificando cobertura</Tag>}</Space> },
    { title: 'Acciones', width: 280, render: (_, row) => puedeGestionarPeriodos ? <Space size={3}>{row.estado === 'abierto' && <Button size="small" loading={coberturaEnProceso[row.id]} disabled={coberturaEnProceso[row.id]} onClick={() => handleCerrarPeriodo(row)}>Cerrar</Button>}{row.estado === 'cerrado' && <><Button size="small" type="primary" onClick={() => solicitarDecision('Enviar período a Nómina', `/asistencia/periodos/${row.id}`, 'enviar_nomina')}>Enviar a Nómina</Button><Button size="small" onClick={() => solicitarDecision('Reabrir período', `/asistencia/periodos/${row.id}`, 'reabrir')}>Reabrir</Button></>}</Space> : '—' },
  ]} pagination={{ pageSize: 15, size: 'small' }} /></Card></div>;
  const tabs = [
    { key: 'resumen', label: 'Resumen', icon: <TeamOutlined />, children: resumen }, { key: 'colaboradores', label: 'Colaboradores', icon: <TeamOutlined />, children: vistaColaboradores },
    { key: 'importar', label: 'Importar marcaciones', icon: <UploadOutlined />, children: importar }, { key: 'diaria', label: 'Asistencia diaria', icon: <CalendarOutlined />, children: asistenciaDiaria },
    { key: 'incidencias', label: 'Incidencias', icon: <ExclamationCircleOutlined />, children: vistaIncidencias },
    { key: 'horas-extra', label: 'Horas extra', icon: <ClockCircleOutlined />, children: vistaHorasExtra },
    { key: 'permisos', label: 'Permisos', icon: <SafetyCertificateOutlined />, children: vistaPermisos },
    { key: 'importaciones', label: 'Importaciones', icon: <FolderOpenOutlined />, children: vistaImportaciones },
    { key: 'periodos', label: 'Períodos', icon: <CalendarOutlined />, children: vistaPeriodos },
    { key: 'gestiones-area', label: 'Gestiones del área', icon: <UsergroupAddOutlined />, children: vistaGestionesArea },
    {
      key: 'planificacion', label: 'Planificación', icon: <ScheduleOutlined />,
      children: <PlanificacionRotativos
        puedeEditar={puedeResolverIncidencias}
        onAbrirIncidencia={(_incidenciaId, colaboradorId) => {
          setColaboradorFiltroIncidencia(colaboradorId);
          setFiltroEstadoIncidencia('todos');
          setActiveTab('incidencias');
        }}
      />,
    },
  ];

  if (colaboradorId) return <>
    <PerfilAsistencia colaborador={perfil} loading={loading} onVolver={onVolver} onReprocesar={reprocesar} reprocesando={reprocesando} onReprocesarDia={reprocesarDia} reprocesandoDia={reprocesandoDia} onCorregirDia={corregirDia} corrigiendoDia={corrigiendoDia} puedeResolverIncidencias={puedeResolverIncidencias} accionesIncidencia={accionesIncidencia} />
    <ModalResolverConPermiso
      open={permisoIncidenciaModalOpen}
      incidencia={incidenciaParaPermiso}
      form={permisoIncidenciaForm}
      onCancel={() => setPermisoIncidenciaModalOpen(false)}
      onSubmit={resolverConPermiso}
      submitting={resolviendoConPermiso}
    />
  </>;

  return <div className="space-y-4"><div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-end"><div><Title level={3} className="!mb-1">Gestión de asistencias</Title><Text type="secondary">Control diario de {user?.empresa?.nombre_comercial ?? 'la empresa activa'}</Text></div><Space wrap><RangePicker value={rango} allowClear={false} format="DD/MM/YYYY" onChange={(value) => value && setRango(value)} />{puedeProcesar && <Button icon={<ReloadOutlined />} loading={reprocesando} onClick={reprocesar}>Reprocesar</Button>}</Space></div><Tabs activeKey={activeTab} onChange={setActiveTab} items={tabs} />
    <Modal title="Nuevo permiso" open={permisoModalOpen} onCancel={() => setPermisoModalOpen(false)} footer={null} destroyOnHidden width={520}>
      <Form form={permisoForm} layout="vertical" onFinish={crearPermiso} initialValues={{ tipo: 'personal' }} requiredMark="optional">
        <Form.Item name="colaborador_id" label="Empleado" rules={[{ required: true, message: 'Selecciona un empleado' }]}><Select showSearch optionFilterProp="label" placeholder="Seleccionar empleado" options={colaboradores.map((item) => ({ value: item.id, label: `${item.nombre_completo} · ${item.legajo}` }))} /></Form.Item>
        <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select className="w-48" options={[{ value: 'personal', label: 'Personal' }, { value: 'medico', label: 'Médico' }, { value: 'capacitacion', label: 'Capacitación' }, { value: 'comision_servicio', label: 'Comisión de servicio' }, { value: 'vacaciones', label: 'Vacaciones' }, { value: 'otro', label: 'Otro' }]} /></Form.Item>
        {['personal', 'capacitacion'].includes(tipoPermiso) && (
          <Form.Item
            name="con_goce"
            label="¿Con goce de haber?"
            rules={[{ required: true, message: 'Indica si el permiso es con o sin goce de haber' }]}
            extra="Determina posteriormente el código SUNAT correcto (Tabla 21) — no lo elijas tú."
          >
            <Select
              placeholder="Selecciona"
              options={[{ value: true, label: 'Con goce de haber' }, { value: false, label: 'Sin goce de haber' }]}
            />
          </Form.Item>
        )}
        {tipoPermiso === 'medico' && (
          <Form.Item
            name="pagador_subsidio"
            label="Pagador del subsidio"
            extra="Déjalo vacío si no lo sabes todavía — solo indica quién pagó directamente el descanso médico, si ya se conoce."
          >
            <Select
              allowClear
              placeholder="No indicado"
              options={[{ value: 'empleador', label: 'Empleador' }, { value: 'essalud_directo', label: 'EsSalud directamente' }]}
            />
          </Form.Item>
        )}
        <Form.Item name="fechas" label="Período" rules={[{ required: true, message: 'Selecciona las fechas' }]}><RangePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Ingresa el motivo' }, { max: 1000 }]}><Input.TextArea rows={3} placeholder="Motivo del permiso" /></Form.Item>
        <div className="flex justify-end gap-2"><Button onClick={() => setPermisoModalOpen(false)}>Cancelar</Button><Button type="primary" htmlType="submit" loading={guardandoPermiso}>Crear permiso</Button></div>
      </Form>
    </Modal>
    <Modal title="Programar / Solicitar" open={solicitudModalOpen} onCancel={() => setSolicitudModalOpen(false)} footer={null} destroyOnHidden width={{ xs: '94%', sm: 620 }}>
      <Form form={solicitudForm} layout="vertical" onFinish={crearSolicitudArea} initialValues={{ tipo: 'horas_extra', origen: puedeAprobarRrhh ? 'rrhh_directo' : 'responsable_area' }} requiredMark="optional">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2"><Form.Item name="tipo" label="Tipo de solicitud" rules={[{ required: true }]}><Select options={[{ value: 'horas_extra', label: 'Horas extra' }, { value: 'permiso', label: 'Permiso' }, { value: 'cambio_horario', label: 'Cambio de horario' }, { value: 'trabajo_remoto', label: 'Trabajo remoto' }, { value: 'otro', label: 'Otro' }]} /></Form.Item><Form.Item name="origen" label="Origen" rules={[{ required: true }]}><Select options={puedeAprobarRrhh ? [{ value: 'rrhh_directo', label: 'RR.HH. directo' }, { value: 'responsable_area', label: 'Responsable del área' }, { value: 'colaborador', label: 'Colaborador' }] : [{ value: 'responsable_area', label: 'Responsable del área' }]} /></Form.Item></div>
        <Form.Item name="fechas" label="Fechas" rules={[{ required: true, message: 'Selecciona una fecha o rango' }]}><RangePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
        <Form.Item name="colaborador_ids" label="Colaboradores" rules={[{ required: true, message: 'Selecciona al menos un colaborador' }]}><Select mode="multiple" maxTagCount="responsive" showSearch optionFilterProp="label" placeholder="Buscar por nombre o legajo" options={colaboradores.map((item) => ({ value: item.id, label: `${item.nombre_completo} · ${item.legajo} · ${item.area ?? 'Sin área'}` }))} /></Form.Item>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Describe el motivo' }]}><Input.TextArea rows={3} placeholder="Describe el motivo de la solicitud" /></Form.Item>
        <Form.Item name="medio_recepcion" label="Medio de recepción (opcional)"><Select allowClear className="w-52" placeholder="Seleccionar" options={[{ value: 'correo', label: 'Correo' }, { value: 'whatsapp', label: 'WhatsApp' }, { value: 'verbal', label: 'Verbal' }, { value: 'documento', label: 'Documento' }, { value: 'sistema', label: 'Sistema' }]} /></Form.Item>
        <div className="flex justify-end gap-2"><Button onClick={() => setSolicitudModalOpen(false)}>Cancelar</Button><Button type="primary" htmlType="submit" loading={guardandoSolicitud}>Crear solicitud</Button></div>
      </Form>
    </Modal>
    <Modal title="Nuevo período de asistencia" open={periodoModalOpen} onCancel={() => setPeriodoModalOpen(false)} footer={null} destroyOnHidden width={460}>
      <Form form={periodoForm} layout="vertical" onFinish={crearPeriodo}>
        <Form.Item name="fechas" label="Rango del período" rules={[{ required: true, message: 'Selecciona el rango' }]}><RangePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
        <Alert className="mb-4" type="info" showIcon message="Los rangos no pueden superponerse. Al cerrar el período se bloqueará el reprocesamiento." />
        <div className="flex justify-end gap-2"><Button onClick={() => setPeriodoModalOpen(false)}>Cancelar</Button><Button type="primary" htmlType="submit">Crear período</Button></div>
      </Form>
    </Modal>
    <ModalResolverConPermiso
      open={permisoIncidenciaModalOpen}
      incidencia={incidenciaParaPermiso}
      form={permisoIncidenciaForm}
      onCancel={() => setPermisoIncidenciaModalOpen(false)}
      onSubmit={resolverConPermiso}
      submitting={resolviendoConPermiso}
    />
  </div>;
}
