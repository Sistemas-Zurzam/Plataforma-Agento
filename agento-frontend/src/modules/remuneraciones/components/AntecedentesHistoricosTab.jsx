import { ArrowLeftOutlined, CheckOutlined, EditOutlined, FileExcelOutlined, MinusCircleOutlined, ReloadOutlined, RiseOutlined, SendOutlined, UploadOutlined } from '@ant-design/icons';
import { App, Button, DatePicker, Input, InputNumber, Select, Space, Statistic, Table, Tag, Upload } from 'antd';
import { useCallback, useEffect, useState } from 'react';
import api from '../../../services/api';

const ESTADOS_LOTE = { borrador: 'default', validado: 'blue', aprobado: 'cyan', aplicado: 'green', anulado: 'red' };
const CLASIFICACIONES = { aplicable: 'green', no_aplicable: 'default', observado: 'orange', error: 'red' };
const ESTADOS_VALIDACION = { pendiente: 'default', valido: 'green', observado: 'orange', error: 'red', ignorado: 'default', aplicado: 'blue' };

const mensajeError = (e, fallback) => {
  const errores = e.response?.data?.errors;
  if (errores) {
    const primero = Object.values(errores)[0];
    if (Array.isArray(primero)) return primero[0];
  }
  return e.response?.data?.message ?? fallback;
};

/**
 * Módulo de antecedentes laborales históricos — importación del Excel de
 * Contabilidad previo a que Agento registrara planillas (ver
 * DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md). Autocontenido como
 * LiquidacionesCeseTab: no depende de un ciclo remunerativo, consume `api`
 * directamente y usa `modal.confirm` con formularios inline para las
 * acciones por fila en vez de modales dedicados.
 */
export default function AntecedentesHistoricosTab({ puedeGestionarCiclos, puedeAprobar }) {
  const { message, modal } = App.useApp();
  const [vista, setVista] = useState('lista');

  const [lotes, setLotes] = useState([]);
  const [lotesLoading, setLotesLoading] = useState(false);
  const [paginacionLotes, setPaginacionLotes] = useState({ current: 1, pageSize: 20, total: 0 });

  const [archivo, setArchivo] = useState(null);
  const [fechaCorte, setFechaCorte] = useState(null);
  const [importando, setImportando] = useState(false);

  const [loteId, setLoteId] = useState(null);
  const [lote, setLote] = useState(null);
  const [resumen, setResumen] = useState(null);
  const [detalles, setDetalles] = useState([]);
  const [detallesLoading, setDetallesLoading] = useState(false);
  const [paginacionDetalles, setPaginacionDetalles] = useState({ current: 1, pageSize: 50, total: 0 });
  const [filtros, setFiltros] = useState({ clasificacion: undefined, estado_validacion: undefined, numero_documento: undefined, concepto: undefined });

  const cargarLotes = useCallback(async (page = 1, pageSize = 20) => {
    setLotesLoading(true);
    try {
      const { data } = await api.get('/nominas/importaciones-historicas', { params: { page, per_page: pageSize } });
      setLotes(data.data ?? []);
      setPaginacionLotes({ current: data.current_page, pageSize: data.per_page, total: data.total });
    } finally { setLotesLoading(false); }
  }, []);

  useEffect(() => {
    if (vista !== 'lista') return;
    const timer = setTimeout(() => cargarLotes(1, paginacionLotes.pageSize), 0);
    return () => clearTimeout(timer);
  }, [vista, cargarLotes, paginacionLotes.pageSize]);

  const cargarDetalle = useCallback(async (id, page = 1, pageSize = 50) => {
    setDetallesLoading(true);
    try {
      const { data } = await api.get(`/nominas/importaciones-historicas/${id}`, {
        params: { page, per_page: pageSize, ...filtros },
      });
      setLote(data.data);
      setResumen(data.resumen);
      setDetalles(data.detalles.data ?? []);
      setPaginacionDetalles({ current: data.detalles.current_page, pageSize: data.detalles.per_page, total: data.detalles.total });
    } finally { setDetallesLoading(false); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtros]);

  useEffect(() => {
    if (vista !== 'detalle' || !loteId) return;
    const timer = setTimeout(() => cargarDetalle(loteId, 1, paginacionDetalles.pageSize), 0);
    return () => clearTimeout(timer);
  }, [vista, loteId, cargarDetalle]);

  const recargarDetalle = () => cargarDetalle(loteId, paginacionDetalles.current, paginacionDetalles.pageSize);

  const importar = async () => {
    if (!archivo) return message.warning('Selecciona el Excel de antecedentes.');
    if (!fechaCorte) return message.warning('Indica la fecha de corte del lote.');
    setImportando(true);
    try {
      const formulario = new FormData();
      formulario.append('archivo', archivo);
      formulario.append('fecha_corte', fechaCorte.format('YYYY-MM-DD'));
      const { data } = await api.post('/nominas/importaciones-historicas', formulario);
      message.success('Lote importado y clasificado.');
      setArchivo(null); setFechaCorte(null);
      setLoteId(data.data.id);
      setVista('detalle');
    } catch (e) {
      message.error(mensajeError(e, 'No se pudo importar el archivo.'));
    } finally { setImportando(false); }
  };

  const abrirLote = (fila) => { setLoteId(fila.id); setVista('detalle'); };

  const aprobarLote = async () => {
    try {
      await api.patch(`/nominas/importaciones-historicas/${loteId}/aprobar`);
      message.success('Lote aprobado.');
      recargarDetalle();
    } catch (e) { message.error(mensajeError(e, 'No se pudo aprobar el lote.')); }
  };

  const aplicarLote = async () => {
    modal.confirm({
      title: 'Aplicar lote a los antecedentes definitivos',
      content: 'Esta acción crea los beneficios sociales, saldos vacacionales y saldos laborales pendientes a partir de las filas válidas. No se puede deshacer desde aquí.',
      okText: 'Aplicar',
      onOk: async () => {
        try {
          await api.post(`/nominas/importaciones-historicas/${loteId}/aplicar`);
          message.success('Lote aplicado.');
          recargarDetalle();
        } catch (e) { message.error(mensajeError(e, 'No se pudo aplicar el lote.')); }
      },
    });
  };

  const pedirFormulario = (titulo, campos, onOk) => {
    const valores = {};
    campos.forEach((c) => { valores[c.name] = c.default ?? null; });
    modal.confirm({
      title: titulo,
      width: 480,
      content: (
        <div className="space-y-2 mt-3">
          {campos.map((c) => (
            <div key={c.name}>
              <div className="mb-1 text-xs font-medium text-gray-600">{c.label}</div>
              {c.tipo === 'fecha' && <DatePicker className="w-full" format="DD/MM/YYYY" onChange={(v) => { valores[c.name] = v; }} />}
              {c.tipo === 'numero' && <InputNumber className="w-full" onChange={(v) => { valores[c.name] = v; }} />}
              {(!c.tipo || c.tipo === 'texto') && <Input onChange={(e) => { valores[c.name] = e.target.value; }} />}
            </div>
          ))}
        </div>
      ),
      okText: 'Confirmar',
      onOk: async () => {
        for (const c of campos) {
          if (c.requerido && !valores[c.name]) { message.warning(`Completa: ${c.label}`); throw new Error('dato_requerido'); }
        }
        const payload = {};
        campos.forEach((c) => { payload[c.name] = c.tipo === 'fecha' ? valores[c.name]?.format('YYYY-MM-DD') : valores[c.name]; });
        try {
          await onOk(payload);
          recargarDetalle();
        } catch (e) {
          message.error(mensajeError(e, 'No se pudo completar la acción.'));
          throw e;
        }
      },
    });
  };

  const corregir = (fila) => pedirFormulario(`Corregir fila #${fila.fila_numero}`, [
    { name: 'colaborador_id', label: 'ID de colaborador (vinculación manual)', tipo: 'numero' },
    { name: 'importe', label: 'Importe', tipo: 'numero', default: fila.importe ? Number(fila.importe) : null },
    { name: 'motivo', label: 'Motivo de la corrección', requerido: true },
  ], async (payload) => {
    const { motivo, ...resto } = payload;
    const cambios = Object.fromEntries(Object.entries(resto).filter(([, v]) => v !== null && v !== undefined && v !== ''));
    await api.patch(`/nominas/importaciones-historicas/${loteId}/detalles/${fila.id}`, { cambios, motivo });
    message.success('Fila corregida.');
  });

  const confirmarCts = (fila) => pedirFormulario(`Confirmar CTS depositada — fila #${fila.fila_numero}`, [
    { name: 'referencia_deposito', label: 'Referencia del depósito', requerido: true },
    { name: 'fecha_deposito', label: 'Fecha del depósito', tipo: 'fecha', requerido: true },
    { name: 'motivo', label: 'Sustento de la confirmación', requerido: true },
  ], async (payload) => {
    await api.patch(`/nominas/importaciones-historicas/${loteId}/detalles/${fila.id}/confirmar-cts`, payload);
    message.success('CTS confirmada.');
  });

  const confirmarGratificacion = (fila) => pedirFormulario(`Confirmar gratificación pagada — fila #${fila.fila_numero}`, [
    { name: 'fecha_pago', label: 'Fecha de pago', tipo: 'fecha', requerido: true },
    { name: 'referencia_pago', label: 'Referencia de pago (opcional)' },
    { name: 'motivo', label: 'Sustento de la confirmación', requerido: true },
  ], async (payload) => {
    await api.patch(`/nominas/importaciones-historicas/${loteId}/detalles/${fila.id}/confirmar-gratificacion`, payload);
    message.success('Gratificación confirmada.');
  });

  const marcarIgnorado = (fila) => pedirFormulario(`Marcar como no aplicable — fila #${fila.fila_numero}`, [
    { name: 'motivo', label: 'Motivo', requerido: true },
  ], async (payload) => {
    await api.patch(`/nominas/importaciones-historicas/${loteId}/detalles/${fila.id}/marcar-ignorado`, payload);
    message.success('Fila marcada como ignorada.');
  });

  const esConceptoCts = (fila) => (fila.tipo_calculo_original ?? '').toUpperCase() === 'CTS' && (fila.nombre_concepto_original ?? '').toUpperCase() === 'CTS';
  const esConceptoGratificacion = (fila) => (fila.nombre_concepto_original ?? '').toUpperCase() === 'GRATIFICACION ORDINARIA';

  if (vista === 'lista') {
    return <div className="space-y-4">
      <div className="rounded border p-3 space-y-2">
        <strong>Importar nuevo lote</strong>
        <div className="flex flex-wrap items-end gap-3">
          <Upload accept=".xlsx" maxCount={1} fileList={archivo ? [archivo] : []} disabled={importando}
            beforeUpload={(file) => { setArchivo(file); return false; }} onRemove={() => setArchivo(null)}>
            <Button icon={<UploadOutlined />} disabled={importando}>Seleccionar Excel</Button>
          </Upload>
          <div>
            <div className="mb-1 text-xs font-medium text-gray-600">Fecha de corte</div>
            <DatePicker format="DD/MM/YYYY" value={fechaCorte} onChange={setFechaCorte} disabled={importando} />
          </div>
          <Button type="primary" icon={<FileExcelOutlined />} loading={importando} disabled={!archivo || !fechaCorte} onClick={importar}>
            Importar y clasificar
          </Button>
        </div>
      </div>

      <div className="flex items-center justify-between">
        <strong>Lotes importados</strong>
        <Button icon={<ReloadOutlined />} onClick={() => cargarLotes(paginacionLotes.current, paginacionLotes.pageSize)}>Actualizar</Button>
      </div>
      <Table
        rowKey="id" loading={lotesLoading} dataSource={lotes}
        onRow={(fila) => ({ onClick: () => abrirLote(fila), className: 'cursor-pointer' })}
        pagination={{ ...paginacionLotes, showSizeChanger: true, onChange: (page, pageSize) => cargarLotes(page, pageSize) }}
        locale={{ emptyText: 'Todavía no se ha importado ningún lote de antecedentes históricos.' }}
        columns={[
          { title: 'Archivo', dataIndex: 'archivo_nombre_original' },
          { title: 'Fecha de corte', dataIndex: 'fecha_corte' },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <Tag color={ESTADOS_LOTE[v]}>{v}</Tag> },
          { title: 'Filas totales', dataIndex: 'filas_totales' },
          { title: 'Con errores', dataIndex: 'filas_con_errores' },
          { title: 'Cargado', dataIndex: 'cargado_at' },
        ]}
      />
    </div>;
  }

  return <div className="space-y-4">
    <Button icon={<ArrowLeftOutlined />} onClick={() => setVista('lista')}>Volver al listado</Button>

    {lote && <div className="flex flex-wrap items-center gap-2">
      <strong>{lote.archivo_nombre_original}</strong>
      <Tag color={ESTADOS_LOTE[lote.estado]}>{lote.estado}</Tag>
      <span className="text-xs text-gray-500">Corte: {lote.fecha_corte}</span>
    </div>}

    {resumen && <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-7">
      <Statistic title="Totales" value={resumen.filas_totales} />
      <Statistic title="Válidas" value={resumen.filas_validas} valueStyle={{ color: '#3f8600' }} />
      <Statistic title="Observadas" value={resumen.filas_observadas} valueStyle={{ color: '#d48806' }} />
      <Statistic title="Con errores" value={resumen.filas_con_errores} valueStyle={{ color: '#cf1322' }} />
      <Statistic title="Ignoradas" value={resumen.filas_ignoradas} />
      <Statistic title="Aplicadas" value={resumen.filas_aplicadas} valueStyle={{ color: '#1677ff' }} />
      <Statistic title="Otra empresa" value={resumen.filas_otra_empresa} />
    </div>}

    <Space wrap>
      {lote?.estado === 'validado' && puedeAprobar && (
        <Button icon={<CheckOutlined />} onClick={aprobarLote} disabled={resumen?.filas_con_errores > 0}>Aprobar lote</Button>
      )}
      {lote?.estado === 'aprobado' && puedeAprobar && (
        <Button type="primary" icon={<SendOutlined />} onClick={aplicarLote}>Aplicar lote</Button>
      )}
    </Space>

    <Space wrap>
      <Select className="w-40" allowClear placeholder="Clasificación" value={filtros.clasificacion}
        onChange={(v) => setFiltros((f) => ({ ...f, clasificacion: v }))}
        options={[{ value: 'aplicable', label: 'Aplicable' }, { value: 'no_aplicable', label: 'No aplicable' }, { value: 'observado', label: 'Observado' }, { value: 'error', label: 'Error' }]} />
      <Select className="w-44" allowClear placeholder="Estado de validación" value={filtros.estado_validacion}
        onChange={(v) => setFiltros((f) => ({ ...f, estado_validacion: v }))}
        options={['pendiente', 'valido', 'observado', 'error', 'ignorado', 'aplicado'].map((v) => ({ value: v, label: v }))} />
      <Input.Search className="w-48" allowClear placeholder="N° de documento" onSearch={(v) => setFiltros((f) => ({ ...f, numero_documento: v || undefined }))} />
      <Input.Search className="w-48" allowClear placeholder="Concepto" onSearch={(v) => setFiltros((f) => ({ ...f, concepto: v || undefined }))} />
    </Space>

    <Table
      rowKey="id" loading={detallesLoading} dataSource={detalles} scroll={{ x: 1100 }}
      pagination={{ ...paginacionDetalles, showSizeChanger: true, onChange: (page, pageSize) => cargarDetalle(loteId, page, pageSize) }}
      columns={[
        { title: 'Fila', dataIndex: 'fila_numero', width: 70 },
        { title: 'Documento', dataIndex: 'numero_documento_normalizado', width: 110 },
        { title: 'Colaborador (Excel)', dataIndex: 'colaborador_nombre_original' },
        { title: 'Concepto', dataIndex: 'nombre_concepto_original' },
        { title: 'Tipo antecedente', dataIndex: 'tipo_antecedente' },
        { title: 'Importe', dataIndex: 'importe', render: (v) => (v !== null ? Number(v).toFixed(2) : '—') },
        { title: 'Clasificación', dataIndex: 'clasificacion', render: (v) => <Tag color={CLASIFICACIONES[v]}>{v}</Tag> },
        { title: 'Estado', dataIndex: 'estado_validacion', render: (v) => <Tag color={ESTADOS_VALIDACION[v]}>{v}</Tag> },
        {
          title: 'Acciones', fixed: 'right', width: 210, render: (_, fila) => (
            ['aprobado', 'aplicado', 'anulado'].includes(lote?.estado) ? null : <Space size="small" wrap>
              {puedeGestionarCiclos && <Button size="small" icon={<EditOutlined />} onClick={() => corregir(fila)}>Corregir</Button>}
              {puedeAprobar && esConceptoCts(fila) && <Button size="small" icon={<RiseOutlined />} onClick={() => confirmarCts(fila)}>CTS</Button>}
              {puedeAprobar && esConceptoGratificacion(fila) && <Button size="small" icon={<RiseOutlined />} onClick={() => confirmarGratificacion(fila)}>Gratif.</Button>}
              {puedeGestionarCiclos && <Button size="small" danger icon={<MinusCircleOutlined />} onClick={() => marcarIgnorado(fila)}>Ignorar</Button>}
            </Space>
          ),
        },
      ]}
    />
  </div>;
}
