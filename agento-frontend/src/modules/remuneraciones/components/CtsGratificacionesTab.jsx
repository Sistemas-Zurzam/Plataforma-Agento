import { BankOutlined, CalculatorOutlined, CheckCircleOutlined, ClockCircleOutlined, DownloadOutlined, ExclamationCircleOutlined, FilePdfOutlined, GiftOutlined, MailOutlined, TeamOutlined } from '@ant-design/icons';
import { Alert, App, Button, DatePicker, Empty, Form, Input, Modal, Select, Table, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { colorForName, initialsForName } from '../../../utils/avatarColor';
import EnviarBoletasCorreoModal from './EnviarBoletasCorreoModal';
import TxtCtsBbvaModal from './TxtCtsBbvaModal';

const TEXTOS_CORREO_CTS = {
  titulo: 'Enviar boletas de CTS por correo',
  aviso: 'Se envía la liquidación del depósito de CTS',
  detalle: 'Cada colaborador recibe su boleta de CTS en PDF al correo de su ficha. Solo se envía a quienes ya tienen el depósito registrado.',
  documento: 'boleta(s) de CTS',
};

// Último envío de la boleta de CTS por correo (ver BeneficioSocialService::resumen).
function EstadoCorreoCts({ envio, fila }) {
  if (!envio) return <span className="text-xs text-gray-400">{fila.depositado > 0 ? 'Sin enviar' : '—'}</span>;
  if (envio.estado === 'pendiente') return <Tag color="blue">En cola</Tag>;
  if (envio.estado === 'fallido') return <Tooltip title={envio.error}><Tag color="red">Fallido</Tag></Tooltip>;
  return <Tooltip title={envio.enviado_at ? `Enviada el ${dayjs(envio.enviado_at).format('DD/MM/YYYY HH:mm')}` : ''}><Tag color="green">Enviada</Tag></Tooltip>;
}

const PERIODOS = [
  { key: 'gratificacion_julio', titulo: 'Gratificación Julio', subtitulo: 'Enero - Junio', icon: <GiftOutlined /> },
  { key: 'gratificacion_diciembre', titulo: 'Gratificación Diciembre', subtitulo: 'Julio - Diciembre', icon: <GiftOutlined /> },
  { key: 'cts_mayo', titulo: 'CTS Mayo', subtitulo: 'Noviembre (año anterior) - Abril', icon: <BankOutlined /> },
  { key: 'cts_noviembre', titulo: 'CTS Noviembre', subtitulo: 'Mayo - Octubre', icon: <BankOutlined /> },
];

const ESTADO_COLOR = { sin_calcular: 'default', calculado: 'gold', pagado: 'green', pendiente_deposito: 'orange', depositado_parcial: 'blue', conciliado: 'green', observado: 'red' };
const ESTADO_LABEL = { sin_calcular: 'Sin calcular', calculado: 'Calculado', pagado: 'Pagado', pendiente_deposito: 'Pendiente', depositado_parcial: 'Parcial', conciliado: 'Conciliado', observado: 'Observado' };

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function textoAlertaCts(texto) {
  return String(texto ?? '')
    .replace(/revisiÃ³n/gi, 'revision')
    .replace(/revisiÃƒÂ³n/gi, 'revision')
    .replace(/rÃ©gimen/gi, 'regimen')
    .replace(/vÃ­nculo/gi, 'vinculo')
    .replace(/clasificaciÃ³n/gi, 'clasificacion')
    .replace(/perÃ­odo/gi, 'periodo')
    .replace(/dÃ­as/gi, 'dias')
    .replace(/Ã³/g, 'o')
    .replace(/Ã©/g, 'e')
    .replace(/Ã¡/g, 'a')
    .replace(/Ã­/g, 'i')
    .replace(/Ãº/g, 'u')
    .replace(/Ã±/g, 'n');
}

function TarjetaStat({ icono, valor, etiqueta, color }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-lg ${color}`}>{icono}</span>
      <div className="min-w-0">
        <p className="truncate text-lg font-semibold text-gray-900">{valor}</p>
        <p className="truncate text-xs text-gray-500">{etiqueta}</p>
      </div>
    </div>
  );
}

/**
 * Consume el mismo catálogo/motor que Planilla Mensual - nunca recalcula:
 * suma las líneas GRATIFICACION_LEGAL/BONIFICACION_EXTRAORDINARIA/
 * CTS_PROVISION ya persistidas en boleta_conceptos para el rango de meses
 * del período elegido. "Calcular" congela esa suma en un snapshot
 * versionado (igual que una Boleta) para que quede reproducible aunque las
 * boletas mensuales se recalculen después.
 */
export default function CtsGratificacionesTab({
  fetchResumenBeneficio, calcularBeneficio, pagarBeneficio, resumen, loading, puedeCalcular, puedePagar,
  registrarDepositosCts, descargarBoletaCts, descargarBoletasCts, previsualizarEnvioCts, enviarBoletasCtsPorCorreo,
  validarTxtCtsBbva, exportarTxtCtsBbva, puedeExportarTxtBbva,
}) {
  const { message, modal } = App.useApp();
  const [periodo, setPeriodo] = useState('gratificacion_julio');
  const [anio, setAnio] = useState(new Date().getFullYear());
  const [calculando, setCalculando] = useState(false);
  const [detalleObservado, setDetalleObservado] = useState(null);
  // CTS calculada: filas elegidas (detalle_id) para depositar, descargar boletas o enviarlas por correo.
  const [seleccion, setSeleccion] = useState([]);
  const [depositoOpen, setDepositoOpen] = useState(false);
  const [registrandoDeposito, setRegistrandoDeposito] = useState(false);
  const [envioCtsOpen, setEnvioCtsOpen] = useState(false);
  const [txtBbvaOpen, setTxtBbvaOpen] = useState(false);
  const [descargando, setDescargando] = useState(null);
  const [formDeposito] = Form.useForm();

  useEffect(() => {
    fetchResumenBeneficio(periodo, anio);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodo, anio]);

  const periodoActivo = PERIODOS.find((p) => p.key === periodo);
  const esGratificacion = periodo.startsWith('gratificacion');
  const esCtsCalculada = !esGratificacion && Boolean(resumen?.calculado) && Boolean(resumen?.id);

  const cambiarPeriodo = (valor) => { setSeleccion([]); setPeriodo(valor); };
  const cambiarAnio = (valor) => { setSeleccion([]); setAnio(valor); };
  const recargar = () => fetchResumenBeneficio(periodo, anio);

  const registrarDeposito = async () => {
    const valores = await formDeposito.validateFields();
    setRegistrandoDeposito(true);
    try {
      const resultado = await registrarDepositosCts(resumen.id, {
        detalle_ids: seleccion, fecha_deposito: valores.fecha_deposito.format('YYYY-MM-DD'), entidad: valores.entidad.trim(), operacion: valores.operacion.trim(),
      });
      message.success(`${resultado.registrados} depósito(s) registrado(s).`);
      if (resultado.omitidos.length > 0) {
        modal.info({
          title: `${resultado.omitidos.length} colaborador(es) sin registrar`,
          content: <ul className="list-disc pl-5 text-sm">{resultado.omitidos.map((o) => <li key={o.colaborador}><b>{o.colaborador}</b>: {o.motivo}</li>)}</ul>,
        });
      }
      setDepositoOpen(false);
      setSeleccion([]);
      recargar();
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo registrar el depósito');
    } finally {
      setRegistrandoDeposito(false);
    }
  };

  const descargar = async (clave, accion) => {
    setDescargando(clave);
    try {
      await accion();
    } catch {
      message.error('No se pudo generar la boleta de CTS');
    } finally {
      setDescargando(null);
    }
  };

  const verObservaciones = (fila) => setDetalleObservado(fila);

  const handleCalcular = () => {
    modal.confirm({
      title: `Calcular ${periodoActivo?.titulo} ${anio}`,
      content: 'Se congelará un snapshot con los montos ya provisionados en las boletas mensuales de este período. Si vuelves a calcular más adelante, se crea una nueva versión - la anterior queda en el historial.',
      okText: 'Calcular',
      cancelText: 'Cancelar',
      onOk: async () => {
        setCalculando(true);
        try {
          await calcularBeneficio(periodo, anio);
          message.success('Cálculo generado correctamente');
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo calcular');
        } finally {
          setCalculando(false);
        }
      },
    });
  };

  const handlePagar = () => {
    let referencia = '';
    modal.confirm({
      title: 'Marcar como pagado',
      content: (
        <div className="mt-2">
          <p className="mb-2 text-xs text-gray-500">Ingresa una referencia de pago real (N.º de operación, lote bancario, constancia).</p>
          <Input placeholder="Ej. OP-2026-0715-001" onChange={(e) => { referencia = e.target.value; }} />
        </div>
      ),
      okText: 'Marcar como pagado',
      cancelText: 'Cancelar',
      onOk: async () => {
        if (!referencia.trim()) {
          message.error('La referencia de pago es obligatoria');
          return Promise.reject();
        }
        try {
          await pagarBeneficio(resumen.id, referencia.trim());
          message.success('Marcado como pagado');
        } catch (err) {
          message.error('No se pudo marcar como pagado');
        }
      },
    });
  };

  const columnas = [
    {
      title: 'Colaborador',
      key: 'colaborador',
      render: (_, fila) => (
        <div className="flex items-center gap-3">
          <span
            className="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold text-white"
            style={{ backgroundColor: colorForName(fila.colaborador) }}
          >
            {initialsForName(fila.colaborador)}
          </span>
          <div className="min-w-0">
            <p className="truncate font-medium text-gray-900">{fila.colaborador}</p>
            <p className="truncate text-xs text-gray-400">{fila.legajo}</p>
          </div>
        </div>
      ),
    },
    { title: 'Empresa', dataIndex: 'empresa' },
    { title: 'Sueldo base', dataIndex: 'sueldo_basico', render: soles },
    { title: 'Meses', dataIndex: 'meses', align: 'center' },
    { title: esGratificacion ? 'Grat. bruta' : 'CTS bruta', dataIndex: 'bruta', render: soles },
    ...(!esGratificacion ? [{ title: 'Estimado (revisión)', dataIndex: 'importe_estimado_sujeto_revision', render: (v) => v == null ? '-' : <span className="text-orange-600">{soles(v)}</span> }] : []),
    ...(esGratificacion ? [{ title: 'Bonif. extraordinaria', dataIndex: 'bonificacion_extraordinaria', render: (v) => <span className="text-blue-500">{soles(v)}</span> }] : []),
    ...(esCtsCalculada ? [{ title: 'Depositado', dataIndex: 'depositado', render: (v) => (Number(v) > 0 ? soles(v) : <span className="text-gray-400">—</span>) }] : []),
    { title: esGratificacion ? 'Grat. neta' : 'CTS aprobable', dataIndex: 'importe_aprobable', render: (v, fila) => <span className={`font-semibold ${fila.estado === 'observado' ? 'text-orange-600' : 'text-green-600'}`}>{fila.estado === 'observado' ? 'Pendiente de revision' : soles(v ?? fila.neta)}</span> },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (estado, fila) => (
        <div className="flex items-center gap-2">
          <Tag color={ESTADO_COLOR[estado] ?? 'default'}>{ESTADO_LABEL[estado] ?? estado}</Tag>
          {estado === 'observado' && (
            <Button size="small" icon={<ExclamationCircleOutlined />} onClick={() => verObservaciones(fila)}>
              Ver motivo
            </Button>
          )}
        </div>
      ),
    },
    ...(esCtsCalculada ? [
      { title: 'Correo', key: 'envio_correo', render: (_, fila) => <EstadoCorreoCts envio={fila.envio_correo} fila={fila} /> },
      {
        title: 'Boleta',
        key: 'boleta',
        align: 'center',
        render: (_, fila) => (
          <Tooltip title="Descargar boleta de CTS">
            <Button size="small" type="text" icon={<DownloadOutlined />} loading={descargando === fila.detalle_id}
              onClick={() => descargar(fila.detalle_id, () => descargarBoletaCts(fila.detalle_id))} />
          </Tooltip>
        ),
      },
    ] : []),
  ];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {PERIODOS.map((p) => (
          <button
            key={p.key}
            type="button"
            onClick={() => cambiarPeriodo(p.key)}
            className={`flex items-start gap-3 rounded-2xl border p-4 text-left transition ${
              periodo === p.key ? 'border-agento-blue bg-agento-blue-light/40' : 'border-gray-100 bg-white hover:border-gray-200'
            }`}
          >
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${periodo === p.key ? 'bg-agento-blue text-white' : 'bg-gray-100 text-gray-500'}`}>
              {p.icon}
            </span>
            <div className="min-w-0">
              <p className="truncate font-semibold text-gray-900">{p.titulo}</p>
              <p className="truncate text-xs text-gray-400">{p.subtitulo}</p>
              {resumen && periodo === p.key && <p className="text-xs text-gray-400">Vence: {resumen.vence}</p>}
            </div>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-500">Año:</span>
          <Select
            size="small"
            className="w-28"
            value={anio}
            onChange={cambiarAnio}
            options={[anio - 1, anio, anio + 1].map((a) => ({ value: a, label: a }))}
          />
          {resumen && (
            <Tag color={ESTADO_COLOR[resumen.calculado ? resumen.estado : 'sin_calcular']}>
              {resumen.calculado ? `${ESTADO_LABEL[resumen.estado]} · versión ${resumen.version}` : 'Vista previa - todavía no calculado'}
            </Tag>
          )}
        </div>
        <div className="flex items-center gap-2">
          {puedeCalcular && (
            <Tooltip title={resumen?.total_colaboradores === 0 ? 'No hay boletas calculadas en este período' : undefined}>
              <Button type="primary" icon={<CalculatorOutlined />} loading={calculando} disabled={!resumen || resumen.total_colaboradores === 0} onClick={handleCalcular}>
                {resumen?.calculado ? `Recalcular ${esGratificacion ? 'gratificación' : 'CTS'}` : `Calcular ${esGratificacion ? 'gratificación' : 'CTS'}`}
              </Button>
            </Tooltip>
          )}
          {/* CTS no se marca como pagada en bloque: se registra el depósito de cada colaborador. */}
          {puedePagar && esGratificacion && resumen?.calculado && resumen.estado === 'calculado' && (
            <Button icon={<BankOutlined />} onClick={handlePagar}>Marcar como pagado</Button>
          )}
        </div>
      </div>

      {resumen && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <TarjetaStat icono={<TeamOutlined />} valor={resumen.total_colaboradores} etiqueta="Colaboradores" color="bg-agento-blue-light text-agento-blue" />
          <TarjetaStat icono={<GiftOutlined />} valor={soles(resumen.total_neto)} etiqueta={`Total ${esGratificacion ? 'gratificaciones' : 'CTS'}`} color="bg-green-50 text-green-600" />
          <TarjetaStat icono={<CheckCircleOutlined />} valor={resumen.total_colaboradores - resumen.pendientes_de_pago} etiqueta="Pagados" color="bg-blue-50 text-blue-600" />
          <TarjetaStat icono={<ClockCircleOutlined />} valor={resumen.pendientes_de_pago} etiqueta="Pendientes" color="bg-orange-50 text-orange-500" />
        </div>
      )}

      <p className="text-sm font-semibold text-gray-700">{periodoActivo?.titulo} {anio}</p>

      {esCtsCalculada && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5">
          <span className="text-sm font-medium text-blue-900">{seleccion.length} colaborador(es) seleccionado(s)</span>
          <div className="flex flex-wrap gap-2">
            {seleccion.length > 0 && <Button size="small" onClick={() => setSeleccion([])}>Limpiar selección</Button>}
            {puedeExportarTxtBbva && seleccion.length > 0 && resumen.estado === 'calculado' && (
              <Button size="small" icon={<DownloadOutlined />} onClick={() => setTxtBbvaOpen(true)}>
                TXT BBVA ({seleccion.length})
              </Button>
            )}
            {puedePagar && seleccion.length > 0 && resumen.estado === 'calculado' && (
              <Button size="small" type="primary" icon={<BankOutlined />} onClick={() => { formDeposito.setFieldsValue({ fecha_deposito: dayjs(), entidad: 'BBVA', operacion: '' }); setDepositoOpen(true); }}>
                Registrar depósito ({seleccion.length})
              </Button>
            )}
            {seleccion.length > 0 && (
              <Button size="small" icon={<FilePdfOutlined />} loading={descargando === 'masivo'} onClick={() => descargar('masivo', () => descargarBoletasCts(resumen.id, seleccion))}>
                Descargar boletas CTS ({seleccion.length})
              </Button>
            )}
            {puedePagar && seleccion.length > 0 && (
              <Button size="small" icon={<MailOutlined />} onClick={() => setEnvioCtsOpen(true)}>
                Enviar por correo ({seleccion.length})
              </Button>
            )}
          </div>
        </div>
      )}

      <Table
        rowKey={(fila) => fila.detalle_id ?? fila.colaborador_id}
        rowSelection={esCtsCalculada ? { selectedRowKeys: seleccion, onChange: setSeleccion } : undefined}
        loading={loading}
        dataSource={resumen?.colaboradores ?? []}
        columns={columnas}
        scroll={{ x: 900 }}
        pagination={false}
        locale={{ emptyText: <Empty description="Sin boletas calculadas en este período" /> }}
      />
      <Modal
        open={depositoOpen}
        title={`Registrar depósito de CTS (${seleccion.length})`}
        okText="Registrar depósito"
        cancelText="Cancelar"
        confirmLoading={registrandoDeposito}
        onOk={registrarDeposito}
        onCancel={() => setDepositoOpen(false)}
        destroyOnHidden
      >
        <p className="mb-3 text-sm text-gray-500">Se registra el saldo pendiente de cada colaborador seleccionado con los datos del abono bancario. Los cálculos observados no se depositan.</p>
        <Form form={formDeposito} layout="vertical">
          <Form.Item name="fecha_deposito" label="Fecha de depósito" rules={[{ required: true, message: 'Indica la fecha del depósito' }]}>
            <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(d) => d.isAfter(dayjs(), 'day')} />
          </Form.Item>
          <Form.Item name="entidad" label="Entidad financiera" rules={[{ required: true, whitespace: true, message: 'Indica la entidad' }]}>
            <Input maxLength={160} />
          </Form.Item>
          <Form.Item name="operacion" label="N.º de operación del abono" rules={[{ required: true, whitespace: true, message: 'Indica el número de operación' }]}>
            <Input maxLength={120} placeholder="Ej. 000123456" />
          </Form.Item>
        </Form>
      </Modal>

      <TxtCtsBbvaModal
        open={txtBbvaOpen}
        onCancel={() => setTxtBbvaOpen(false)}
        beneficioId={resumen?.id}
        detalleIds={seleccion}
        validarTxtCtsBbva={validarTxtCtsBbva}
        exportarTxtCtsBbva={exportarTxtCtsBbva}
      />

      <EnviarBoletasCorreoModal
        open={envioCtsOpen}
        onCancel={() => setEnvioCtsOpen(false)}
        onEnviado={() => { setEnvioCtsOpen(false); setSeleccion([]); recargar(); }}
        origenId={resumen?.id}
        ids={seleccion}
        idKey="detalle_id"
        textos={TEXTOS_CORREO_CTS}
        previsualizar={previsualizarEnvioCts}
        enviarCorreo={enviarBoletasCtsPorCorreo}
      />

      {detalleObservado && (
        <Modal
          open
          title={`Observaciones CTS: ${detalleObservado.colaborador}`}
          onCancel={() => setDetalleObservado(null)}
          footer={<Button onClick={() => setDetalleObservado(null)}>Cerrar</Button>}
        >
          <div className="space-y-3">
            <Alert
              type="warning"
              showIcon
              message="Importe pendiente de revision"
              description={detalleObservado.importe_estimado_sujeto_revision == null
                ? "No hay un estimado fiable porque falta informacion necesaria para completar el calculo."
                : `Estimado sujeto a revision: ${soles(detalleObservado.importe_estimado_sujeto_revision)}. Aprobable ahora: ${soles(detalleObservado.importe_aprobable ?? 0)}. No depositar hasta resolver los puntos observados.`}
            />
            {(detalleObservado.alertas ?? []).length > 0 ? (
              <ul className="list-disc space-y-2 pl-5 text-sm text-gray-700">
                {detalleObservado.alertas.map((alerta, index) => <li key={`${index}-${alerta}`}>{textoAlertaCts(alerta)}</li>)}
              </ul>
            ) : (
              <p className="text-sm text-gray-500">El calculo esta observado, pero la respuesta no incluye el detalle de las alertas. Recalcula el periodo para actualizar el desglose.</p>
            )}
            {detalleObservado.dato_faltante && <Alert type="info" showIcon message="Dato pendiente" description={textoAlertaCts(detalleObservado.dato_faltante)} />}
          </div>
        </Modal>
      )}
    </div>
  );
}
