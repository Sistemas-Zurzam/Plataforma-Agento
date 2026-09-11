import { CheckCircleOutlined, DownloadOutlined, UploadOutlined } from '@ant-design/icons';
import { Alert, App, Button, Input, Popconfirm, Select, Table, Tag, Upload } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { useConceptoDefinicionesPlame } from '../../configuracion/hooks/useConceptoDefinicionesPlame';
import { CONCEPTOS_CON_DEFINICION, CONCEPTOS_REGISTRABLES } from './AgregarConceptoComplementariaModal';

const soles = (valor) => `S/ ${Number(valor ?? 0).toFixed(2)}`;

const ESTADO_LOTE_COLOR = {
  borrador: 'default',
  exportado: 'blue',
  revisado: 'gold',
  aplicado: 'green',
  anulado: 'red',
};

/**
 * Bono de asistencia (política Livex) — un BonoAsistenciaLote se calcula y
 * aplica sobre un ciclo de planilla sin importar si ya está cerrado o pagado.
 *
 * Flujo: generar lote (propuesta conservadora solo con datos de asistencia)
 * → descargar Excel para Livex → Livex lo revisa fuera de Agento (marca meta
 * comercial cumplida, aprueba/no cada colaborador, puede corregir una falta
 * injustificada a justificada) → reimportar ese Excel (match por DNI) →
 * aplicar. El backend decide internamente, por colaborador, la vía de
 * aplicación: si la boleta del colaborador todavía no está pagada, escribe
 * el monto final en ColaboradorConceptoPeriodo (el "Calcular planilla" del
 * ciclo ya lo lee — este panel NUNCA dispara ese recálculo); si la boleta ya
 * está pagada, el backend genera automáticamente una Planilla Complementaria
 * para esos colaboradores. Todo esto es transparente para esta pantalla:
 * el panel solo informa el resultado tras aplicar.
 *
 * El tab que aloja este panel es siempre visible mientras la empresa tenga
 * el flag activo (ver bono_asistencia_habilitado). Generar/exportar/
 * importar/aplicar/anular están siempre disponibles sin importar el estado
 * del ciclo — la única condición que oculta esas acciones es el estado del
 * LOTE seleccionado: un lote `aplicado` o `anulado` queda en solo lectura
 * (solo se sigue viendo su detalle), porque ya no tiene sentido volver a
 * operar sobre él.
 */
export default function BonoAsistenciaLotePanel({ ciclo, api, catalogo = [], onUpdated }) {
  const { message } = App.useApp();
  const { definiciones, loading: definicionesLoading, fetchDefiniciones } = useConceptoDefinicionesPlame();

  const [lotes, setLotes] = useState([]);
  const [lotesLoading, setLotesLoading] = useState(false);
  const [loteSeleccionadoId, setLoteSeleccionadoId] = useState(null);

  const [catalogoLocal, setCatalogoLocal] = useState(catalogo);
  const [cargandoCatalogo, setCargandoCatalogo] = useState(false);

  const [codigo, setCodigo] = useState(undefined);
  const [conceptoDefinicionId, setConceptoDefinicionId] = useState(undefined);
  const [nombreLote, setNombreLote] = useState('');
  const [motivoLote, setMotivoLote] = useState('');
  const [generando, setGenerando] = useState(false);

  const [resumenImportacion, setResumenImportacion] = useState(null);
  const [importando, setImportando] = useState(false);
  const [exportando, setExportando] = useState(false);
  const [aplicando, setAplicando] = useState(false);
  const [anulando, setAnulando] = useState(false);
  const [complementariaGenerada, setComplementariaGenerada] = useState(null);

  useEffect(() => { setCatalogoLocal(catalogo); }, [catalogo]);
  useEffect(() => {
    if (catalogo.length || typeof api.fetchCatalogoConceptos !== 'function') return;
    setCargandoCatalogo(true);
    api.fetchCatalogoConceptos()
      .then(setCatalogoLocal)
      .catch((e) => message.error(e.response?.data?.message ?? 'No se pudo cargar el catálogo de conceptos para el bono.'))
      .finally(() => setCargandoCatalogo(false));
  }, [ciclo?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const cargarLotes = async () => {
    if (!ciclo) return;
    setLotesLoading(true);
    try {
      const data = await api.fetchBonoAsistenciaLotes(ciclo.id);
      setLotes(data);
      setLoteSeleccionadoId((actual) => {
        if (actual === 'nuevo' || data.some((l) => l.id === actual)) return actual;
        return data.find((l) => l.estado !== 'anulado')?.id ?? 'nuevo';
      });
    } catch (e) {
      message.error(e.response?.data?.message ?? 'No se pudieron cargar los lotes de bono de asistencia.');
    } finally {
      setLotesLoading(false);
    }
  };

  useEffect(() => {
    setResumenImportacion(null);
    cargarLotes();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ciclo?.id]);

  useEffect(() => {
    setResumenImportacion(null);
    setComplementariaGenerada(null);
  }, [loteSeleccionadoId]);

  const opcionesConcepto = useMemo(() => CONCEPTOS_REGISTRABLES.filter(
    (c) => c.tipo === 'ingreso' && catalogoLocal.some((k) => k.codigo === c.codigo),
  ), [catalogoLocal]);
  const requiereDefinicionPlame = CONCEPTOS_CON_DEFINICION.includes(codigo);

  const handleCambioConcepto = (value) => {
    setCodigo(value);
    setConceptoDefinicionId(undefined);
    if (CONCEPTOS_CON_DEFINICION.includes(value)) {
      const concepto = catalogoLocal.find((c) => c.codigo === value);
      if (concepto) fetchDefiniciones(concepto.id, concepto.codigo);
    }
  };

  const generarLote = async () => {
    if (!codigo) return message.warning('Selecciona el concepto del bono.');
    if (requiereDefinicionPlame && !conceptoDefinicionId) return message.warning('Selecciona la clasificación PLAME del bono.');
    if (!nombreLote.trim()) return message.warning('Ingresa un nombre para el lote.');
    if (!motivoLote.trim()) return message.warning('Ingresa el motivo del bono.');
    const concepto = catalogoLocal.find((c) => c.codigo === codigo);
    setGenerando(true);
    try {
      const lote = await api.crearBonoAsistenciaLote(ciclo.id, {
        concepto_id: concepto.id,
        concepto_definicion_id: conceptoDefinicionId ?? null,
        nombre: nombreLote.trim(),
        motivo: motivoLote.trim(),
      });
      message.success('Lote de bono de asistencia generado con la propuesta conservadora según asistencia.');
      setLotes((actuales) => [lote, ...actuales]);
      setLoteSeleccionadoId(lote.id);
      setNombreLote('');
      setMotivoLote('');
      setCodigo(undefined);
      setConceptoDefinicionId(undefined);
    } catch (e) {
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudo generar el lote.');
    } finally {
      setGenerando(false);
    }
  };

  const actualizarLoteEnLista = (loteActualizado) => {
    setLotes((actuales) => actuales.map((l) => (l.id === loteActualizado.id ? loteActualizado : l)));
  };

  const handleExportar = async (loteId) => {
    setExportando(true);
    try {
      await api.exportarBonoAsistenciaLote(loteId);
    } catch {
      message.error('No se pudo descargar el Excel del lote.');
    } finally {
      setExportando(false);
    }
  };

  const handleImportar = (loteId) => async (archivo) => {
    setImportando(true);
    setResumenImportacion(null);
    try {
      const resultado = await api.importarBonoAsistenciaLote(loteId, archivo);
      setResumenImportacion({ coincidencias: resultado.coincidencias, sinCoincidencia: resultado.sin_coincidencia ?? [], duplicados: resultado.duplicados ?? [] });
      actualizarLoteEnLista(resultado.lote);
      message.success(`Excel importado: ${resultado.coincidencias} coincidencia(s) por DNI.`);
    } catch (e) {
      message.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? 'No se pudo importar el Excel de Livex.');
    } finally {
      setImportando(false);
    }
    return Upload.LIST_IGNORE;
  };

  const handleAplicar = async (loteId) => {
    setAplicando(true);
    try {
      const loteActualizado = await api.aplicarBonoAsistenciaLote(loteId);
      actualizarLoteEnLista(loteActualizado);
      if (loteActualizado.planilla_complementaria) {
        setComplementariaGenerada(loteActualizado.planilla_complementaria);
        message.success('Bono aplicado. Recalcula el ciclo para que se refleje en las boletas pendientes.');
      } else {
        setComplementariaGenerada(null);
        message.success('Aplicado. Recalcula el ciclo para que el bono aparezca en las boletas.');
      }
      onUpdated?.();
    } catch (e) {
      message.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? 'No se pudo aplicar el bono.');
    } finally {
      setAplicando(false);
    }
  };

  const handleAnular = async (loteId) => {
    const motivo = document.getElementById(`motivo-anular-bono-${loteId}`)?.value?.trim();
    if (!motivo) return message.warning('Ingresa el motivo de anulación.');
    setAnulando(true);
    try {
      await api.eliminarBonoAsistenciaLote(loteId, motivo);
      message.success('Lote anulado.');
      if (loteSeleccionadoId === loteId) setLoteSeleccionadoId('nuevo');
      await cargarLotes();
    } catch (e) {
      message.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? 'No se pudo anular el lote.');
    } finally {
      setAnulando(false);
    }
  };

  const columnasDetalle = [
    { title: 'Colaborador', dataIndex: 'colaborador_nombre_snapshot' },
    { title: 'Documento', dataIndex: 'documento_snapshot', width: 110 },
    { title: 'Faltas just.', dataIndex: 'dias_falta_justificada', width: 90, align: 'center' },
    { title: 'Faltas injust.', dataIndex: 'dias_falta_injustificada', width: 100, align: 'center' },
    { title: 'Tardanzas', dataIndex: 'tardanzas', width: 90, align: 'center' },
    { title: 'Bono base', dataIndex: 'bono_base', width: 110, render: soles },
    { title: '% propuesto', dataIndex: 'porcentaje_propuesto', width: 100, align: 'center', render: (v) => `${v}%` },
    { title: 'Monto propuesto', dataIndex: 'monto_propuesto', width: 130, render: soles },
    {
      title: 'Meta comercial',
      dataIndex: 'meta_comercial_cumplida',
      width: 120,
      align: 'center',
      render: (v) => (v == null ? <Tag>Pendiente</Tag> : v ? <Tag color="green">Cumplió</Tag> : <Tag color="red">No cumplió</Tag>),
    },
    {
      title: 'Aprobado',
      dataIndex: 'aprobado',
      width: 100,
      align: 'center',
      render: (v) => (v == null ? '—' : v ? <Tag color="green" icon={<CheckCircleOutlined />}>Sí</Tag> : <Tag color="red">No</Tag>),
    },
    { title: '% final', dataIndex: 'porcentaje_final', width: 90, align: 'center', render: (v) => (v == null ? '—' : `${v}%`) },
    { title: 'Monto final', dataIndex: 'monto_final', width: 120, render: (v) => (v == null ? '—' : soles(v)) },
    { title: 'Observación Livex', dataIndex: 'observacion_livex', render: (v) => v || '—' },
  ];

  if (!ciclo) return null;

  const loteActivo = loteSeleccionadoId && loteSeleccionadoId !== 'nuevo'
    ? lotes.find((l) => l.id === loteSeleccionadoId)
    : null;
  const mostrarFormularioNuevo = !loteActivo;
  const hayLoteActivo = lotes.some((l) => l.estado !== 'anulado');

  return (
    <div className="space-y-3">
      {lotes.length > 0 && (
        <div>
          <div className="mb-1 text-xs font-medium text-gray-600">Lote de bono de asistencia</div>
          <Select
            className="w-full max-w-xl"
            loading={lotesLoading}
            value={loteSeleccionadoId ?? 'nuevo'}
            onChange={setLoteSeleccionadoId}
            options={[
              { value: 'nuevo', label: 'Generar nuevo lote', disabled: hayLoteActivo },
              ...lotes.map((l) => ({
                value: l.id,
                label: (
                  <span>
                    {l.nombre} <Tag color={ESTADO_LOTE_COLOR[l.estado] ?? 'default'} className="ml-1">{l.estado}</Tag>
                  </span>
                ),
              })),
            ]}
          />
          <p className="mt-1 text-xs text-gray-400">
            Solo debería existir un lote activo (no aplicado ni anulado) por ciclo — verifica el estado antes de generar uno nuevo.
          </p>
        </div>
      )}

      {mostrarFormularioNuevo && (
        <div className="space-y-3 rounded-xl border border-gray-100 bg-white p-3">
          <p className="text-sm font-medium text-gray-700">Generar lote de bono de asistencia</p>
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-[220px]">
              <div className="mb-1 text-xs font-medium text-gray-600">Concepto</div>
              <Select
                className="w-full"
                placeholder="Selecciona un concepto"
                value={codigo}
                onChange={handleCambioConcepto}
                loading={cargandoCatalogo}
                disabled={cargandoCatalogo}
                notFoundContent={cargandoCatalogo ? 'Cargando conceptos...' : 'No hay conceptos de bono activos'}
                options={opcionesConcepto.map((c) => ({ value: c.codigo, label: c.nombre }))}
              />
            </div>
            {requiereDefinicionPlame && (
              <div className="min-w-[220px]">
                <div className="mb-1 text-xs font-medium text-gray-600">Clasificación PLAME</div>
                <Select
                  className="w-full"
                  placeholder="Selecciona una clasificación"
                  loading={definicionesLoading}
                  disabled={definicionesLoading}
                  value={conceptoDefinicionId}
                  onChange={setConceptoDefinicionId}
                  notFoundContent={definicionesLoading ? 'Cargando clasificaciones...' : 'No hay clasificaciones PLAME cargadas; verifica las migraciones'}
                  options={definiciones.filter((d) => d.activo).map((d) => ({ value: d.id, label: `${d.nombre} (${d.codigo_plame})` }))}
                />
              </div>
            )}
          </div>
          <Input value={nombreLote} onChange={(e) => setNombreLote(e.target.value)} maxLength={255} placeholder="Nombre del lote — ej. Bono de asistencia setiembre 2026" />
          <Input.TextArea value={motivoLote} onChange={(e) => setMotivoLote(e.target.value)} autoSize={{ minRows: 1, maxRows: 3 }} maxLength={1000} placeholder="Motivo: bono de asistencia mensual según política Livex..." />
          <Button type="primary" loading={generando} disabled={!codigo} onClick={generarLote}>Generar lote</Button>
          <p className="text-xs text-gray-500">
            Se propone el porcentaje más conservador según faltas y tardanzas del mes — la meta comercial todavía no se conoce en este punto (viene de Livex, fuera de Agento).
          </p>
        </div>
      )}

      {loteActivo && (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-100 bg-white px-4 py-2.5">
            <div>
              <p className="font-semibold text-gray-900">{loteActivo.nombre}</p>
              <Tag color={ESTADO_LOTE_COLOR[loteActivo.estado] ?? 'default'}>{loteActivo.estado}</Tag>
            </div>
            <div className="flex flex-wrap gap-2">
              {!['aplicado', 'anulado'].includes(loteActivo.estado) && (
                <Button icon={<DownloadOutlined />} loading={exportando} onClick={() => handleExportar(loteActivo.id)}>
                  Descargar Excel para Livex
                </Button>
              )}
              {loteActivo.estado === 'revisado' && (
                <Popconfirm
                  title="Aplicar bono de asistencia"
                  description="Se guardará el monto final para los colaboradores aprobados por Livex: en los conceptos del período si su boleta aún no está pagada, o mediante una Planilla Complementaria nueva si ya está pagada. Esto no recalcula el ciclo automáticamente."
                  okText="Aplicar"
                  cancelText="Cancelar"
                  onConfirm={() => handleAplicar(loteActivo.id)}
                >
                  <Button type="primary" loading={aplicando}>Aplicar bono</Button>
                </Popconfirm>
              )}
              {!['aplicado', 'anulado'].includes(loteActivo.estado) && (
                <Popconfirm
                  title="Anular lote"
                  description={<Input id={`motivo-anular-bono-${loteActivo.id}`} placeholder="Motivo de anulación" />}
                  okText="Anular"
                  okButtonProps={{ danger: true }}
                  cancelText="Cancelar"
                  onConfirm={() => handleAnular(loteActivo.id)}
                >
                  <Button danger loading={anulando}>Anular lote</Button>
                </Popconfirm>
              )}
            </div>
          </div>

          {!['aplicado', 'anulado'].includes(loteActivo.estado) && (
            <div className="rounded-xl border border-dashed border-gray-300 p-3">
              <p className="mb-2 text-xs font-medium text-gray-600">Subir Excel revisado por Livex</p>
              <Upload.Dragger accept=".xlsx" multiple={false} showUploadList={false} disabled={importando} beforeUpload={handleImportar(loteActivo.id)}>
                <p className="ant-upload-drag-icon"><UploadOutlined /></p>
                <p className="ant-upload-text">
                  {importando ? 'Importando...' : 'Arrastra el Excel revisado por Livex aquí o haz clic para seleccionarlo'}
                </p>
                <p className="ant-upload-hint">Se hace match por DNI contra este lote.</p>
              </Upload.Dragger>
            </div>
          )}

          {complementariaGenerada && (
            <Alert
              type="info"
              showIcon
              closable
              onClose={() => setComplementariaGenerada(null)}
              message={`Se generó la Planilla Complementaria "${complementariaGenerada.nombre}"`}
              description="Los colaboradores de este lote cuya boleta ya estaba pagada se agregaron ahí en vez del ciclo — apruébala y expórtala a Telecrédito BCP / BBVA Net Cash desde Planillas complementarias."
            />
          )}

          {resumenImportacion && (
            <Alert
              type={resumenImportacion.sinCoincidencia.length || resumenImportacion.duplicados.length ? 'warning' : 'success'}
              showIcon
              message={`${resumenImportacion.coincidencias} colaborador(es) coincidieron por DNI.`}
              description={<>
                {resumenImportacion.sinCoincidencia.length > 0 && <div>Sin coincidencia (revisa el DNI en el Excel): {resumenImportacion.sinCoincidencia.join(', ')}</div>}
                {resumenImportacion.duplicados.length > 0 && <div>DNI repetido en el archivo (se ignoraron esas filas, revisa y vuelve a subir): {resumenImportacion.duplicados.join(', ')}</div>}
              </>}
            />
          )}

          <Table
            rowKey="id"
            size="small"
            loading={lotesLoading}
            dataSource={loteActivo.detalles ?? []}
            columns={columnasDetalle}
            scroll={{ x: 1400 }}
            pagination={{ defaultPageSize: 10, showSizeChanger: true }}
            locale={{ emptyText: 'Este lote todavía no tiene detalle.' }}
          />
          {loteActivo.estado === 'aplicado' && (
            <p className="text-xs text-gray-500">Este lote ya fue aplicado — recalcula el ciclo para reflejar el bono en las boletas pendientes.</p>
          )}
        </div>
      )}
    </div>
  );
}
