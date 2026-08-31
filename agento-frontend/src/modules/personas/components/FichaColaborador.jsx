import {
  ArrowLeftOutlined,
  ApartmentOutlined,
  BankOutlined,
  CalendarOutlined,
  CameraOutlined,
  ClockCircleOutlined,
  DeleteOutlined,
  DollarOutlined,
  EditOutlined,
  FileTextOutlined,
  MedicineBoxOutlined,
  RiseOutlined,
  TeamOutlined,
  UserDeleteOutlined,
  UserOutlined,
  UploadOutlined,
  CheckCircleOutlined,
  EyeOutlined,
  IdcardOutlined,
  PlusOutlined,
} from '@ant-design/icons';
import { App, Avatar, Button, Card, DatePicker, Empty, Form, Input, InputNumber, Modal, Progress, Select, Spin, Table, Tabs, Tag, Upload } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { colorForName, initialsForName } from '../../../utils/avatarColor';
import { TIPO_CONTRATO_OPTIONS } from '../constants/opciones';
import { useColaboradores } from '../hooks/useColaboradores';
import EditarCalendarioModal from './EditarCalendarioModal';
import EditarHorarioColaboradorModal from './EditarHorarioColaboradorModal';
import EditarColaboradorModal from './EditarColaboradorModal';
import CesarColaboradorModal from './CesarColaboradorModal';
import VerCarnetModal from './VerCarnetModal';

export const DOCUMENTOS_REQUERIDOS = [
  { tipo: 'documento_identidad', nombre: 'Copia de documento de identidad' },
  { tipo: 'recibo_servicio', nombre: 'Recibo de luz o agua' },
  { tipo: 'contrato_firmado', nombre: 'Contrato firmado' },
];

function etiquetaContrato(valor) {
  return TIPO_CONTRATO_OPTIONS.find((opcion) => opcion.value === valor)?.label ?? valor ?? '—';
}

function fecha(fechaValor) {
  return fechaValor ? dayjs(fechaValor).format('DD MMM YYYY') : '—';
}

function dinero(valor, moneda = 'PEN') {
  if (valor === null || valor === undefined) return '—';
  return new Intl.NumberFormat('es-PE', { style: 'currency', currency: moneda }).format(Number(valor));
}

function antiguedad(fechaIngreso) {
  if (!fechaIngreso) return '—';
  const meses = Math.max(0, dayjs().diff(dayjs(fechaIngreso), 'month'));
  if (meses < 12) return `${meses} ${meses === 1 ? 'mes' : 'meses'}`;
  const anios = Math.floor(meses / 12);
  const resto = meses % 12;
  return resto ? `${anios} a ${resto} m` : `${anios} ${anios === 1 ? 'año' : 'años'}`;
}

function edad(fechaNacimiento) {
  return fechaNacimiento ? `${dayjs().diff(dayjs(fechaNacimiento), 'year')} años` : '—';
}

function Dato({ label, children }) {
  return (
    <div>
      <p className="text-xs text-gray-500">{label}</p>
      <p className="mt-0.5 font-medium text-gray-900">{children ?? '—'}</p>
    </div>
  );
}

function Indicador({ icono, color, label, valor }) {
  return (
    <Card className="h-full" styles={{ body: { padding: 16 } }}>
      <div className="flex items-center gap-3">
        <span className={`flex h-10 w-10 items-center justify-center rounded-full ${color}`}>{icono}</span>
        <div><p className="text-xs text-gray-500">{label}</p><p className="font-semibold text-gray-900">{valor}</p></div>
      </div>
    </Card>
  );
}

function Resumen({ colaborador }) {
  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <div className="space-y-4 lg:col-span-2">
        <Card title={<span><UserOutlined /> Datos personales</span>}>
          <div className="grid gap-5 sm:grid-cols-2">
            <Dato label="Nombre completo">{colaborador.nombre_completo}</Dato>
            <Dato label="Documento">{`${colaborador.tipo_documento?.toUpperCase() ?? ''} ${colaborador.numero_documento ?? ''}`.trim()}</Dato>
            <Dato label="Fecha de nacimiento">{fecha(colaborador.fecha_nacimiento)}</Dato>
            <Dato label="Edad">{edad(colaborador.fecha_nacimiento)}</Dato>
            <Dato label="Celular">{colaborador.celular_colaborador}</Dato>
            <Dato label="Dirección">{colaborador.direccion}</Dato>
            <Dato label="Email">{colaborador.email}</Dato>
            <Dato label="Ciudad / distrito">{[colaborador.ciudad_residencia, colaborador.distrito_residencia].filter(Boolean).join(' / ') || '—'}</Dato>
          </div>
        </Card>
        <Card title={<span><TeamOutlined /> Datos laborales</span>}>
          <div className="grid gap-5 sm:grid-cols-2">
            <Dato label="Empresa">{colaborador.empresa?.nombre_comercial}</Dato>
            <Dato label="Área">{colaborador.area?.nombre}</Dato>
            <Dato label="Cargo">{colaborador.cargo}</Dato>
            <Dato label="Tipo de contrato">{etiquetaContrato(colaborador.tipo_contrato)}</Dato>
            <Dato label="Modalidad">{colaborador.modalidad_trabajo}</Dato>
            <Dato label="Fecha de ingreso">{fecha(colaborador.fecha_ingreso)}</Dato>
            <Dato label="Antigüedad">{antiguedad(colaborador.fecha_ingreso)}</Dato>
            <Dato label="Régimen laboral">{colaborador.regimen_laboral}</Dato>
          </div>
        </Card>
        <Card size="small" styles={{ body: { padding: '14px 18px' } }}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2 font-semibold text-gray-800">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                <CalendarOutlined />
              </span>
              Saldo de vacaciones {dayjs().year()}
            </div>
            <p className="text-sm text-gray-500">Sin saldo registrado para este año.</p>
          </div>
        </Card>
      </div>
      <div className="space-y-4">
        <Card size="small" title={<span><ClockCircleOutlined /> Horario y calendario</span>}>
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2"><Dato label="Horario vigente">{colaborador.horario?.nombre}</Dato></div>
            <Dato label="Modalidad">{colaborador.modalidad_trabajo}</Dato>
            <Dato label="Desde">{fecha(colaborador.fecha_ingreso)}</Dato>
          </div>
        </Card>
        <Card size="small" title={<span><DollarOutlined /> Remuneración</span>}>
          <div className="grid grid-cols-2 gap-4">
            <Dato label="Sueldo base">{dinero(colaborador.remuneracion?.salario, colaborador.remuneracion?.moneda_salario)}</Dato>
            <Dato label="Periodicidad">{colaborador.remuneracion?.periodicidad_pago}</Dato>
          </div>
        </Card>
        <Card size="small" title={<span><BankOutlined /> Datos bancarios</span>}>
          <div className="grid grid-cols-2 gap-4">
            <Dato label="Banco">{colaborador.banco}</Dato><Dato label="Tipo">{colaborador.tipo_cuenta}</Dato>
            <Dato label="Moneda">{colaborador.moneda_cuenta}</Dato><Dato label="N.º de cuenta">{colaborador.numero_cuenta}</Dato>
          </div>
        </Card>
      </div>
    </div>
  );
}

function HistorialRemunerativo({ colaborador }) {
  const historial = colaborador.historial_remunerativo ?? [];
  if (!historial.length) return <Empty description="Sin historial remunerativo" />;
  return <div className="space-y-3">{historial.map((item) => (
    <Card key={item.id} size="small">
      <div className="flex items-center justify-between gap-4">
        <div><p className="font-semibold">Desde {fecha(item.vigencia_desde)}</p><p className="text-xs text-gray-500">{item.periodicidad_pago}</p></div>
        <p className="text-lg font-semibold text-emerald-600">{dinero(item.salario, item.moneda_salario)}</p>
      </div>
    </Card>
  ))}</div>;
}

function Asistencia() {
  return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Aún no hay marcaciones de asistencia registradas para este colaborador." />;
}

function Nominas() {
  return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Aún no existen nóminas calculadas para este colaborador." />;
}

const TIPOS_MOVIMIENTO_VACACIONES = [
  { value: 'devengo_inicial', label: 'Devengo inicial', color: 'blue' },
  { value: 'goce', label: 'Goce', color: 'orange' },
  { value: 'pago', label: 'Pago', color: 'red' },
  { value: 'ajuste', label: 'Ajuste', color: 'purple' },
];

/**
 * Kardex de ajustes vacacionales manuales — la única forma de registrar
 * saldos que no se derivan de asistencia (devengo inicial al migrar de otro
 * sistema, goces/pagos ya liquidados fuera de Agento). Estos movimientos
 * alimentan directamente el cálculo de "Vacaciones pendientes y truncas" de
 * la liquidación por cese (VacacionMovimiento en el backend).
 */
function Vacaciones({ colaborador, listarVacacionMovimientos, crearVacacionMovimiento, eliminarVacacionMovimiento }) {
  const { message, modal } = App.useApp();
  const [form] = Form.useForm();
  const [movimientos, setMovimientos] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [guardando, setGuardando] = useState(false);

  const cargar = () => {
    setLoading(true);
    listarVacacionMovimientos(colaborador.id).then(setMovimientos).finally(() => setLoading(false));
  };

  useEffect(cargar, [colaborador.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const guardar = async (values) => {
    setGuardando(true);
    try {
      await crearVacacionMovimiento(colaborador.id, { ...values, fecha: values.fecha.format('YYYY-MM-DD') });
      message.success('Movimiento registrado correctamente');
      setModalOpen(false);
      form.resetFields();
      cargar();
    } catch (error) {
      const errors = error.response?.data?.errors;
      message.error(errors ? Object.values(errors)[0]?.[0] : 'No se pudo registrar el movimiento');
    } finally {
      setGuardando(false);
    }
  };

  const eliminar = (movimiento) => {
    modal.confirm({
      title: 'Eliminar movimiento',
      content: `¿Eliminar el ajuste de ${movimiento.dias} día(s) del ${dayjs(movimiento.fecha).format('DD/MM/YYYY')}?`,
      okText: 'Eliminar', okButtonProps: { danger: true },
      onOk: async () => {
        await eliminarVacacionMovimiento(colaborador.id, movimiento.id);
        message.success('Movimiento eliminado');
        cargar();
      },
    });
  };

  const columns = [
    { title: 'Fecha', dataIndex: 'fecha', render: (v) => fecha(v) },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => {
      const opcion = TIPOS_MOVIMIENTO_VACACIONES.find((o) => o.value === v);
      return <Tag color={opcion?.color}>{opcion?.label ?? v}</Tag>;
    } },
    { title: 'Días', dataIndex: 'dias', render: (v) => <span className={Number(v) < 0 ? 'text-red-500' : 'text-green-600'}>{Number(v) > 0 ? '+' : ''}{Number(v)}</span> },
    { title: 'Descripción', dataIndex: 'descripcion' },
    { title: '', render: (_, m) => colaborador.activo && <Button size="small" danger type="text" icon={<DeleteOutlined />} onClick={() => eliminar(m)} /> },
  ];

  return <div>
    <div className="mb-3 flex items-center justify-between">
      <p className="text-sm text-gray-500">Kardex de ajustes vacacionales — alimenta el cálculo de vacaciones truncas al momento del cese.</p>
      {colaborador.activo && <Button icon={<PlusOutlined />} onClick={() => setModalOpen(true)}>Registrar ajuste</Button>}
    </div>
    <Table rowKey="id" size="small" loading={loading} dataSource={movimientos} columns={columns} pagination={false} locale={{ emptyText: 'Sin movimientos registrados' }} />

    <Modal title="Registrar ajuste vacacional" open={modalOpen} onCancel={() => setModalOpen(false)} onOk={() => form.submit()} okText="Registrar" confirmLoading={guardando} destroyOnHidden centered>
      <Form form={form} layout="vertical" onFinish={guardar} initialValues={{ tipo: 'ajuste' }}>
        <Form.Item label="Fecha" name="fecha" rules={[{ required: true, message: 'Selecciona la fecha' }]}>
          <DatePicker className="w-full" format="DD/MM/YYYY" maxDate={dayjs()} />
        </Form.Item>
        <Form.Item label="Tipo" name="tipo" rules={[{ required: true }]}>
          <Select options={TIPOS_MOVIMIENTO_VACACIONES.map(({ value, label }) => ({ value, label }))} />
        </Form.Item>
        <Form.Item label="Días (positivo suma saldo, negativo lo reduce)" name="dias" rules={[{ required: true, message: 'Ingresa los días' }, { validator: (_, v) => (v === 0 ? Promise.reject('Los días no pueden ser 0') : Promise.resolve()) }]}>
          <InputNumber className="w-full" step={0.5} />
        </Form.Item>
        <Form.Item label="Descripción" name="descripcion" rules={[{ required: true, message: 'Indica el motivo del ajuste' }]}>
          <Input.TextArea rows={2} maxLength={255} showCount />
        </Form.Item>
      </Form>
    </Modal>
  </div>;
}

export function Legajo({ colaborador, subiendo, viendo, onImportar, onVer, soloLectura = false }) {
  const cargados = colaborador.documentos ?? [];
  const totalCargados = DOCUMENTOS_REQUERIDOS.filter(({ tipo }) => cargados.some((documento) => documento.tipo === tipo)).length;
  const porcentaje = Math.round((totalCargados / DOCUMENTOS_REQUERIDOS.length) * 100);

  return <div>
    <Card className="mb-5 border-amber-300"><p className="font-medium">{totalCargados} de {DOCUMENTOS_REQUERIDOS.length} documentos requeridos</p><Progress percent={porcentaje} showInfo={false} /></Card>
    <h3 className="mb-3 font-semibold">Documentos requeridos</h3>
    <div className="space-y-2">{DOCUMENTOS_REQUERIDOS.map(({ tipo, nombre }) => {
      const documento = cargados.find((item) => item.tipo === tipo);
      return <Card key={tipo} size="small"><div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">{documento ? <CheckCircleOutlined className="text-green-600" /> : <FileTextOutlined className="text-gray-500" />}<div className="min-w-0"><p className="font-medium">{nombre}</p><p className={`truncate text-xs ${documento ? 'text-green-600' : 'text-gray-500'}`}>{documento?.nombre_original ?? 'No cargado'}</p></div></div>
        {!soloLectura && (
          <div className="flex items-center gap-2">
            {documento && <Button size="small" icon={<EyeOutlined />} loading={viendo === documento.id} onClick={() => onVer(documento)}>Ver</Button>}
            <Upload accept=".pdf,.jpg,.jpeg,.png,.webp" showUploadList={false} beforeUpload={(archivo) => { onImportar(tipo, archivo); return false; }}><Button size="small" icon={<UploadOutlined />} loading={subiendo === tipo}>{documento ? 'Reemplazar' : 'Importar'}</Button></Upload>
          </div>
        )}
      </div></Card>;
    })}</div>
    <p className="mt-3 text-xs text-gray-500">Formatos permitidos: PDF, JPG, PNG y WEBP. Tamaño máximo: 10 MB por archivo.</p>
  </div>;
}

export default function FichaColaborador({ colaboradorId, user, onVolver }) {
  const { message, modal } = App.useApp();
  const {
    fetchColaborador, actualizarCalendario, actualizarHorario,
    actualizarColaborador, actualizarConfiguracionNomina, actualizarRemuneracion, cesarColaborador, previsualizarLiquidacionCese, eliminarColaborador, subirDocumento, verDocumento,
    subirFotoPerfil, fetchFotoPerfil, listarVacacionMovimientos, crearVacacionMovimiento, eliminarVacacionMovimiento,
  } = useColaboradores();
  const [colaborador, setColaborador] = useState(null);
  const [loading, setLoading] = useState(true);
  const [fotoUrl, setFotoUrl] = useState(null);
  const [subiendoFoto, setSubiendoFoto] = useState(false);
  const [calendarioOpen, setCalendarioOpen] = useState(false);
  const [guardandoCalendario, setGuardandoCalendario] = useState(false);
  const [horarioOpen, setHorarioOpen] = useState(false);
  const [guardandoHorario, setGuardandoHorario] = useState(false);
  const [editarOpen, setEditarOpen] = useState(false);
  const [guardandoEdicion, setGuardandoEdicion] = useState(false);
  const [ceseOpen, setCeseOpen] = useState(false);
  const [guardandoCese, setGuardandoCese] = useState(false);
  const [subiendoDocumento, setSubiendoDocumento] = useState(null);
  const [viendoDocumento, setViendoDocumento] = useState(null);
  const [documentoVista, setDocumentoVista] = useState(null);
  const [carnetOpen, setCarnetOpen] = useState(false);

  useEffect(() => {
    setLoading(true);
    setFotoUrl(null);
    fetchColaborador(colaboradorId)
      .then(setColaborador)
      .catch(() => setColaborador(null))
      .finally(() => setLoading(false));
  }, [colaboradorId, fetchColaborador]);

  useEffect(() => {
    if (!colaborador?.documentos?.some((d) => d.tipo === 'foto_perfil')) return;
    let cancelado = false;
    fetchFotoPerfil(colaborador.id).then((blob) => {
      if (!cancelado && blob) setFotoUrl(URL.createObjectURL(blob));
    });
    return () => { cancelado = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [colaborador?.id]);

  useEffect(() => () => { if (fotoUrl) URL.revokeObjectURL(fotoUrl); }, [fotoUrl]);

  const subirFoto = async (archivo) => {
    setSubiendoFoto(true);
    try {
      const actualizado = await subirFotoPerfil(colaborador.id, archivo);
      setColaborador(actualizado);
      const blob = await fetchFotoPerfil(colaborador.id);
      setFotoUrl(blob ? URL.createObjectURL(blob) : null);
      message.success('Foto de perfil actualizada correctamente');
    } catch {
      message.error('No se pudo subir la foto de perfil');
    } finally {
      setSubiendoFoto(false);
    }
    return false;
  };

  const importarDocumento = async (tipo, archivo) => {
    setSubiendoDocumento(tipo);
    try {
      const actualizado = await subirDocumento(colaborador.id, tipo, archivo);
      setColaborador(actualizado);
      message.success('Documento cargado correctamente');
    } catch (error) {
      const errors = error.response?.data?.errors;
      message.error(errors ? Object.values(errors)[0]?.[0] : 'No se pudo cargar el documento');
    } finally {
      setSubiendoDocumento(null);
    }
  };

  const abrirDocumento = async (documento) => {
    setViendoDocumento(documento.id);
    try {
      const archivo = await verDocumento(colaborador.id, documento.id);
      const url = URL.createObjectURL(archivo);
      setDocumentoVista({
        url,
        nombre: documento.nombre_original,
        mimeType: documento.mime_type || archivo.type,
      });
    } catch {
      message.error('No se pudo abrir el documento');
    } finally {
      setViendoDocumento(null);
    }
  };

  const cerrarDocumento = () => {
    // La foto de perfil reutiliza este mismo visor, pero su URL la maneja
    // el otro efecto (se revoca cuando cambia o se sube una nueva) — acá
    // NUNCA hay que revocarla, o el avatar se quedaría con una imagen rota.
    if (documentoVista?.url && documentoVista.url !== fotoUrl) URL.revokeObjectURL(documentoVista.url);
    setDocumentoVista(null);
  };

  const tabs = useMemo(() => colaborador ? [
    { key: 'resumen', label: <span><UserOutlined /> Resumen</span>, children: <Resumen colaborador={colaborador} /> },
    { key: 'remuneraciones', label: <span><RiseOutlined /> Historial remunerativo</span>, children: <HistorialRemunerativo colaborador={colaborador} /> },
    { key: 'asistencia', label: <span><MedicineBoxOutlined /> Asistencia</span>, children: <Asistencia /> },
    { key: 'nominas', label: <span><DollarOutlined /> Nóminas</span>, children: <Nominas /> },
    { key: 'vacaciones', label: <span><CalendarOutlined /> Vacaciones</span>, children: <Vacaciones colaborador={colaborador} listarVacacionMovimientos={listarVacacionMovimientos} crearVacacionMovimiento={crearVacacionMovimiento} eliminarVacacionMovimiento={eliminarVacacionMovimiento} /> },
    { key: 'legajo', label: <span><FileTextOutlined /> Legajo</span>, children: <Legajo colaborador={colaborador} subiendo={subiendoDocumento} viendo={viendoDocumento} onImportar={importarDocumento} onVer={abrirDocumento} /> },
  ] : [], [colaborador, subiendoDocumento, viendoDocumento]);

  const guardarCalendario = async (dias) => {
    setGuardandoCalendario(true);
    try {
      const actualizado = await actualizarCalendario(colaborador.id, dias);
      setColaborador(actualizado);
      setCalendarioOpen(false);
      message.success('Calendario actualizado correctamente');
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo actualizar el calendario');
    } finally {
      setGuardandoCalendario(false);
    }
  };

  /**
   * Fase 4C — si el cambio de horario afectaría fechas planificadas a mano
   * o con origen histórico desconocido, el backend NO aplica nada todavía:
   * responde 409 con el detalle exacto (nunca se confía en una
   * confirmación puramente de frontend). Acá se traduce ese detalle a
   * lenguaje llano (nunca "origen"/"NULL"/nombres de columna) y, si RR.HH.
   * confirma, se reenvía con la intención explícita que el backend exige.
   */
  const confirmarPlanificacionExistente = (datosHorario, impacto) => {
    const partes = [
      impacto.automaticas > 0 ? `${impacto.automaticas} fecha(s) generadas automáticamente por el horario anterior se recalcularán con el horario nuevo.` : null,
      impacto.humanas > 0 ? `${impacto.humanas} fecha(s) planificadas manualmente se conservarán tal cual, sin tocarlas.` : null,
      impacto.legacy > 0 ? `${impacto.legacy} fecha(s) históricas (de antes de este control) se conservarán tal cual, sin tocarlas.` : null,
    ].filter(Boolean);

    modal.confirm({
      title: 'El colaborador tiene planificación futura existente',
      content: (
        <div>
          <ul className="list-disc pl-4">
            {partes.map((parte, indice) => <li key={indice}>{parte}</li>)}
          </ul>
          <p className="mt-2">Las decisiones manuales o históricas nunca se eliminan automáticamente. ¿Deseas continuar de todas formas?</p>
        </div>
      ),
      okText: 'Continuar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setGuardandoHorario(true);
        try {
          const actualizado = await actualizarHorario(colaborador.id, { ...datosHorario, confirmar_planificacion_existente: true });
          setColaborador(actualizado);
          setHorarioOpen(false);
          message.success('Horario actualizado correctamente');
        } catch (error) {
          message.error(error.response?.data?.message ?? 'No se pudo actualizar el horario');
        } finally {
          setGuardandoHorario(false);
        }
      },
    });
  };

  const guardarHorario = (values) => {
    const { horario_nombre: horarioNombre, ...datosHorario } = values;

    modal.confirm({
      title: 'Confirmar cambio de horario',
      content: `¿Confirmas asignar el horario "${horarioNombre}" a ${colaborador?.nombre_completo} a partir del ${dayjs(datosHorario.vigencia_desde).format('DD/MM/YYYY')}? Esto cambia cómo se procesa su asistencia desde esa fecha en adelante.`,
      okText: 'Confirmar cambio',
      cancelText: 'Cancelar',
      onOk: async () => {
        setGuardandoHorario(true);
        try {
          const actualizado = await actualizarHorario(colaborador.id, datosHorario);
          setColaborador(actualizado);
          setHorarioOpen(false);
          message.success('Horario actualizado correctamente');
        } catch (error) {
          if (error.response?.status === 409 && error.response?.data?.requiere_confirmacion) {
            confirmarPlanificacionExistente(datosHorario, error.response.data.impacto);
            return;
          }
          message.error(error.response?.data?.message ?? 'No se pudo actualizar el horario');
        } finally {
          setGuardandoHorario(false);
        }
      },
    });
  };

  /**
   * onGuardar(datosBasicos, remuneracion, previsional) — EditarColaboradorModal
   * decide si `remuneracion` viene o no según si el usuario realmente tocó
   * el sueldo/moneda/periodicidad/asignación familiar (comparado contra lo
   * que ya estaba cargado). Si no viene, NO se toca colaborador_remuneraciones
   * — no tiene sentido crear una fila de historial cuando nada cambió.
   *
   * V3 P4/P5 — `previsional` (CUSPP/AFP/tipo de comisión/suspensión de 4ta)
   * viaja al MISMO endpoint que ya usa ConfiguracionNominaModal
   * (actualizarConfiguracionNomina) — nunca se reimplementa esa validación
   * acá. Viene null si el usuario no tiene permiso de nómina (la pestaña ni
   * se mostró).
   */
  const guardarEdicion = async (values, remuneracion, previsional) => {
    setGuardandoEdicion(true);
    try {
      let actualizado = await actualizarColaborador(colaborador.id, values);

      if (previsional) {
        try {
          actualizado = await actualizarConfiguracionNomina(colaborador.id, previsional);
        } catch (error) {
          setColaborador(actualizado);
          message.warning(error.response?.data?.message ?? 'Los datos básicos se guardaron, pero no se pudo actualizar la configuración previsional.');
        }
      }

      if (remuneracion) {
        try {
          actualizado = await actualizarRemuneracion(colaborador.id, remuneracion);
        } catch (error) {
          setColaborador(actualizado);
          setEditarOpen(false);
          message.warning(
            error.response?.data?.errors?.vigencia_desde?.[0] ??
              error.response?.data?.message ??
              'Los datos se guardaron, pero no se pudo actualizar la remuneración.',
          );
          return;
        }
      }

      setColaborador(actualizado);
      setEditarOpen(false);
      message.success('Colaborador actualizado correctamente');
    } catch (error) {
      const errors = error.response?.data?.errors;
      message.error(errors ? Object.values(errors)[0]?.[0] : 'No se pudo actualizar el colaborador');
    } finally {
      setGuardandoEdicion(false);
    }
  };

  const guardarCese = async (values) => {
    setGuardandoCese(true);
    try {
      const actualizado = await cesarColaborador(colaborador.id, values);
      setColaborador(actualizado);
      setCeseOpen(false);
      message.success('Cese registrado correctamente');
    } catch (error) {
      const errors = error.response?.data?.errors;
      message.error(errors ? Object.values(errors)[0]?.[0] : 'No se pudo registrar el cese');
    } finally {
      setGuardandoCese(false);
    }
  };

  const confirmarEliminacion = () => modal.confirm({
    title: 'Eliminar colaborador',
    content: `¿Confirmas la eliminación de ${colaborador.nombre_completo}? El registro dejará de aparecer, pero su historial se conservará.`,
    okText: 'Eliminar',
    cancelText: 'Cancelar',
    okButtonProps: { danger: true },
    onOk: async () => {
      await eliminarColaborador(colaborador.id);
      message.success('Colaborador eliminado correctamente');
      onVolver();
    },
  });

  if (loading) return <div className="flex min-h-96 items-center justify-center"><Spin size="large" /></div>;
  if (!colaborador) return <Empty description="No se pudo cargar el colaborador"><Button onClick={onVolver}>Volver</Button></Empty>;

  return <div className="mx-auto max-w-7xl">
    <Button type="text" icon={<ArrowLeftOutlined />} onClick={onVolver} className="mb-4">Volver a empleados</Button>
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div className="h-20 bg-gradient-to-r from-violet-700 via-violet-500 to-violet-300" />
      <div className="relative px-5 pb-5 sm:px-7">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
          <div className="flex min-w-0 items-end gap-4">
            <div className="relative -mt-9 shrink-0">
              <Avatar
                size={84}
                src={fotoUrl}
                onClick={() => fotoUrl && setDocumentoVista({ url: fotoUrl, nombre: 'Foto de perfil', mimeType: 'image/webp' })}
                className={`border-4 border-white text-xl font-bold shadow-md ${fotoUrl ? 'cursor-pointer' : ''}`}
                style={{ backgroundColor: colorForName(colaborador.nombre_completo) }}
              >
                {initialsForName(colaborador.nombre_completo)}
              </Avatar>
              <Upload accept="image/jpeg,image/png,image/webp" showUploadList={false} beforeUpload={subirFoto}>
                <Button
                  shape="circle"
                  size="small"
                  icon={<CameraOutlined />}
                  loading={subiendoFoto}
                  className="absolute right-0 bottom-0 shadow-sm"
                  aria-label="Cambiar foto de perfil"
                  title="Foto de perfil (opcional)"
                />
              </Upload>
            </div>
            <div className="min-w-0 pb-1">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="truncate text-xl font-bold text-gray-950">{colaborador.nombre_completo}</h2>
                <Tag color={colaborador.activo ? 'green' : 'default'} className="m-0">{colaborador.activo ? 'Activo' : 'Inactivo'}</Tag>
                <Tag color="blue" className="m-0">{etiquetaContrato(colaborador.tipo_contrato)}</Tag>
                {colaborador.es_trabajador_confianza && (
                  <Tag color="gold" className="m-0" title="No se le descuenta por faltas/tardanzas ni se le paga horas extra">
                    Trabajador de confianza
                  </Tag>
                )}
              </div>
              <p className="mt-1 font-medium text-gray-600">{colaborador.cargo}</p>
            </div>
          </div>
          <div className="flex flex-wrap gap-1 rounded-xl border border-gray-100 bg-gray-50 p-1.5">
            <Button type="text" size="small" icon={<ClockCircleOutlined />} onClick={() => setHorarioOpen(true)}>Horario</Button>
            <Button type="text" size="small" icon={<CalendarOutlined />} onClick={() => setCalendarioOpen(true)}>Calendario</Button>
            <Button type="text" size="small" icon={<EditOutlined />} onClick={() => setEditarOpen(true)}>Editar datos</Button>
            <Button type="text" size="small" icon={<IdcardOutlined />} onClick={() => setCarnetOpen(true)}>Carnet</Button>
            <span className="mx-1 hidden w-px bg-gray-200 sm:block" />
            <Button type="text" size="small" danger icon={<UserDeleteOutlined />} disabled={!colaborador.activo} onClick={() => setCeseOpen(true)}>Cesar</Button>
            <Button type="text" size="small" danger icon={<DeleteOutlined />} onClick={confirmarEliminacion}>Eliminar</Button>
          </div>
        </div>
        <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 border-t border-gray-100 pt-4 text-sm text-gray-500">
          <span className="flex items-center gap-1.5"><ApartmentOutlined /> {colaborador.empresa?.nombre_comercial ?? 'Sin empresa'}</span>
          <span className="flex items-center gap-1.5"><TeamOutlined /> {colaborador.area?.nombre ?? 'Sin área'}</span>
          <span className="flex items-center gap-1.5"><IdcardOutlined /> Legajo: {colaborador.legajo}</span>
        </div>
      </div>
    </div>
    <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Indicador icono={<ClockCircleOutlined />} color="bg-violet-50 text-violet-600" label="Antigüedad" valor={antiguedad(colaborador.fecha_ingreso)} /><Indicador icono={<DollarOutlined />} color="bg-green-50 text-green-600" label="Sueldo base" valor={dinero(colaborador.remuneracion?.salario, colaborador.remuneracion?.moneda_salario)} /><Indicador icono={<CalendarOutlined />} color="bg-blue-50 text-blue-600" label="Vacaciones disp." valor="Sin saldo" /><Indicador icono={<RiseOutlined />} color="bg-amber-50 text-amber-600" label="Asistencia 3M" valor="Sin registros" /></div>
    <Tabs className="mt-5" items={tabs} />
    <EditarHorarioColaboradorModal open={horarioOpen} colaborador={colaborador} submitting={guardandoHorario} onGuardar={guardarHorario} onCancel={() => setHorarioOpen(false)} />
    <EditarCalendarioModal open={calendarioOpen} colaborador={colaborador} submitting={guardandoCalendario} onGuardar={guardarCalendario} onCancel={() => setCalendarioOpen(false)} />
    <EditarColaboradorModal open={editarOpen} colaborador={colaborador} user={user} submitting={guardandoEdicion} onGuardar={guardarEdicion} onCancel={() => setEditarOpen(false)} />
    <CesarColaboradorModal open={ceseOpen} colaborador={colaborador} submitting={guardandoCese} onPrevisualizar={(values) => previsualizarLiquidacionCese(colaborador.id, values)} onGuardar={guardarCese} onCancel={() => setCeseOpen(false)} />
    <VerCarnetModal colaborador={carnetOpen ? colaborador : null} onClose={() => setCarnetOpen(false)} />
    <Modal title={documentoVista?.nombre ?? 'Ver documento'} open={Boolean(documentoVista)} onCancel={cerrarDocumento} footer={null} width={{ xs: '95%', sm: '90%', lg: 900 }} centered destroyOnHidden>
      {documentoVista?.mimeType?.startsWith('image/') ? (
        <div className="flex max-h-[72vh] justify-center overflow-auto rounded-lg bg-gray-50 p-3">
          <img src={documentoVista.url} alt={documentoVista.nombre} className="max-w-full object-contain" />
        </div>
      ) : documentoVista ? (
        <iframe title={documentoVista.nombre} src={documentoVista.url} className="h-[72vh] w-full rounded-lg border border-gray-200" />
      ) : null}
    </Modal>
  </div>;
}
