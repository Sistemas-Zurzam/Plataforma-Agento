import { CheckCircleOutlined, DownloadOutlined, StopOutlined } from '@ant-design/icons';
import { Alert, App, Button, Descriptions, Drawer, Empty, Input, Modal, Segmented, Space, Spin, Table, Tabs, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { useConsumoComidaImportacion } from '../hooks/useConsumoComida';
import { consumoComidaApi } from '../services/consumoComidaApi';
import { ESTADO_FILA, ESTADO_IMPORTACION, mensajeError, soles } from '../utils/consumoComida';

/**
 * Detalle de una importación de consumos: previsualización por colaborador,
 * filas con su resultado de validación, revisión de posibles duplicados,
 * confirmación y anulación. Todos los totales y bloqueos vienen del backend.
 * El padre lo monta con key={importacionId}, así que el estado local
 * (filtro, selección) empieza limpio en cada importación.
 */
export default function ConsumoComidaImportacionDrawer({ importacionId, open, onClose, onCambio, puedeGestionar }) {
  const { message } = App.useApp();
  const { datos, setDatos, loading, error, cargar } = useConsumoComidaImportacion(importacionId);
  const [filtro, setFiltro] = useState('todas');
  const [seleccion, setSeleccion] = useState([]);
  const [procesando, setProcesando] = useState(false);

  useEffect(() => {
    if (open) cargar();
  }, [open, cargar]);

  const actualizar = (nuevo) => {
    setDatos(nuevo);
    setSeleccion([]);
    onCambio?.();
  };

  const importacion = datos?.importacion;
  const previsualizacion = datos?.previsualizacion;
  const detalles = useMemo(() => datos?.detalles ?? [], [datos]);
  const filas = useMemo(() => (filtro === 'todas' ? detalles : detalles.filter((d) => d.estado === filtro)), [detalles, filtro]);
  const pendientesDuplicado = detalles.filter((d) => d.estado === 'posible_duplicado' && !d.duplicado_admitido_at);
  const esValidada = importacion?.estado === 'validada';
  const puedeConfirmar = puedeGestionar && esValidada && (previsualizacion?.bloqueos ?? []).length === 0;

  const admitirDuplicados = () => {
    const valores = { motivo: '' };
    Modal.confirm({
      title: `Admitir ${seleccion.length} posible(s) duplicado(s)`,
      content: (
        <div className="mt-3 space-y-3">
          <p>Confirma que estos consumos son legítimos aunque coincidan en documento, fecha, concepto y monto. Quedará registrado en la auditoría.</p>
          <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(e) => { valores.motivo = e.target.value; }} />
        </div>
      ),
      okText: 'Admitir',
      cancelText: 'Cancelar',
      onOk: async () => {
        if (valores.motivo.trim().length < 5) { message.warning('Ingresa un motivo (mínimo 5 caracteres).'); throw new Error('motivo'); }
        try {
          actualizar(await consumoComidaApi.admitirDuplicados(importacion.id, seleccion, valores.motivo.trim()));
          message.success('Duplicados revisados');
        } catch (err) {
          message.error(mensajeError(err, 'No se pudieron admitir los duplicados.'));
          throw err;
        }
      },
    });
  };

  const ejecutarConConfirmacionDeAprobadas = async (accion, exito) => {
    setProcesando(true);
    try {
      actualizar(await accion(false));
      message.success(exito);
    } catch (err) {
      if (err.response?.data?.errors?.confirmar_boletas_aprobadas) {
        Modal.confirm({
          title: 'Hay boletas aprobadas afectadas',
          content: err.response.data.errors.confirmar_boletas_aprobadas[0],
          okText: 'Continuar',
          cancelText: 'Cancelar',
          onOk: async () => {
            try {
              actualizar(await accion(true));
              message.success(exito);
            } catch (segundo) {
              message.error(mensajeError(segundo, 'No se pudo completar la operación.'));
            }
          },
        });
      } else {
        message.error(mensajeError(err, 'No se pudo completar la operación.'));
        cargar();
      }
    } finally {
      setProcesando(false);
    }
  };

  const confirmar = () => {
    const afectadas = (previsualizacion?.colaboradores ?? []).filter((c) => c.boleta).length;
    Modal.confirm({
      title: 'Confirmar importación',
      width: 520,
      content: (
        <div className="mt-3 space-y-2">
          <p>Se aplicará un descuento total de <strong>{soles(previsualizacion?.total_monto)}</strong> a <strong>{previsualizacion?.total_colaboradores}</strong> colaborador(es).</p>
          <p>Boletas ya calculadas que se verán afectadas: <strong>{afectadas}</strong>. Quedarán pendientes de recálculo hasta que se recalcule la planilla del ciclo.</p>
        </div>
      ),
      okText: 'Confirmar y aplicar',
      cancelText: 'Cancelar',
      onOk: () => ejecutarConConfirmacionDeAprobadas((confirmarAprobadas) => consumoComidaApi.aplicar(importacion.id, confirmarAprobadas), 'Importación aplicada'),
    });
  };

  const anular = () => {
    const valores = { motivo: '' };
    Modal.confirm({
      title: 'Anular importación',
      content: (
        <div className="mt-3 space-y-3">
          {importacion.estado === 'aplicada' && <Alert type="warning" showIcon message="Se retirará el descuento de esta importación. Los consumos y el historial se conservan." />}
          <Input.TextArea rows={3} placeholder="Motivo obligatorio" onChange={(e) => { valores.motivo = e.target.value; }} />
        </div>
      ),
      okText: 'Anular',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        if (valores.motivo.trim().length < 5) { message.warning('Ingresa un motivo (mínimo 5 caracteres).'); throw new Error('motivo'); }
        await ejecutarConConfirmacionDeAprobadas((confirmarAprobadas) => consumoComidaApi.anular(importacion.id, valores.motivo.trim(), confirmarAprobadas), 'Importación anulada');
      },
    });
  };

  const columnasColaboradores = [
    { title: 'Documento', dataIndex: 'documento', width: 120 },
    { title: 'Colaborador', dataIndex: 'nombre' },
    { title: 'Consumos', dataIndex: 'consumos', width: 90, align: 'center' },
    { title: 'Descuento', dataIndex: 'monto', width: 120, align: 'right', render: soles },
    { title: 'Boleta vigente', dataIndex: 'boleta', width: 150, render: (b) => (b ? <Tag>{`v${b.version} · ${b.estado}`}</Tag> : <span className="text-gray-400">Sin calcular</span>) },
    {
      title: 'Neto estimado', dataIndex: 'neto_estimado', width: 150, align: 'right',
      render: (valor, fila) => (valor === null ? '—' : (
        <Space size={4}>
          {soles(valor)}
          {fila.conflicto_neto && <Tooltip title="El descuento supera el neto vigente: la boleta no podrá aprobarse hasta resolverlo."><Tag color="red">Conflicto</Tag></Tooltip>}
        </Space>
      )),
    },
  ];

  const columnasFilas = [
    { title: 'Fila', dataIndex: 'fila', width: 64 },
    { title: 'Documento', dataIndex: 'documento', width: 110, render: (v, f) => v ?? String(f.valores_originales?.documento ?? '') },
    { title: 'Colaborador', dataIndex: 'colaborador_nombre_snapshot', render: (v) => v ?? '—' },
    { title: 'Fecha', dataIndex: 'fecha_consumo', width: 104, render: (v, f) => v ?? String(f.valores_originales?.fecha_consumo ?? '') },
    { title: 'Concepto', dataIndex: 'concepto', render: (v, f) => v ?? String(f.valores_originales?.concepto ?? '') },
    { title: 'Referencia', dataIndex: 'referencia', width: 120, render: (v) => v ?? '—' },
    { title: 'Monto', dataIndex: 'monto', width: 100, align: 'right', render: (v, f) => (v !== null ? soles(v) : String(f.valores_originales?.monto ?? '')) },
    {
      title: 'Estado', dataIndex: 'estado', width: 150,
      render: (estado, f) => (
        <Space direction="vertical" size={2}>
          <Tag color={ESTADO_FILA[estado]?.color}>{ESTADO_FILA[estado]?.label ?? estado}</Tag>
          {f.duplicado_admitido_at && <Tooltip title={f.duplicado_motivo}><Tag color="cyan">Admitido</Tag></Tooltip>}
        </Space>
      ),
    },
    { title: 'Observaciones', dataIndex: 'errores', render: (e) => (e?.length ? <ul className="m-0 list-disc pl-4 text-xs">{e.map((x) => <li key={x}>{x}</li>)}</ul> : '—') },
  ];

  return (
    <Drawer
      title={importacion ? `Importación #${importacion.id} — ${importacion.archivo_nombre_original}` : 'Importación'}
      width={1100}
      open={open}
      onClose={onClose}
      destroyOnHidden
      extra={importacion && (
        <Space>
          <Button icon={<DownloadOutlined />} onClick={() => consumoComidaApi.descargarArchivo(importacion).catch(() => message.error('No se pudo descargar el archivo.'))}>Archivo original</Button>
          {(importacion.filas_con_error > 0 || importacion.errores_generales?.length > 0 || importacion.filas_posible_duplicado > 0) && (
            <Button icon={<DownloadOutlined />} onClick={() => consumoComidaApi.descargarErrores(importacion.id).catch(() => message.error('No se pudo descargar el reporte.'))}>Reporte de errores</Button>
          )}
          {puedeGestionar && importacion.estado !== 'anulada' && <Button danger icon={<StopOutlined />} loading={procesando} onClick={anular}>Anular</Button>}
          {puedeGestionar && esValidada && <Button type="primary" icon={<CheckCircleOutlined />} loading={procesando} disabled={!puedeConfirmar} onClick={confirmar}>Confirmar importación</Button>}
        </Space>
      )}
    >
      {loading && !datos && <div className="flex justify-center py-16"><Spin /></div>}
      {error && <Alert type="error" showIcon message={error} action={<Button size="small" onClick={cargar}>Reintentar</Button>} />}
      {importacion && (
        <div className="space-y-4">
          <Descriptions size="small" column={3} bordered>
            <Descriptions.Item label="Estado"><Tag color={ESTADO_IMPORTACION[importacion.estado]?.color}>{ESTADO_IMPORTACION[importacion.estado]?.label}</Tag></Descriptions.Item>
            <Descriptions.Item label="Sistema de origen">{importacion.sistema_origen}</Descriptions.Item>
            <Descriptions.Item label="Importado por">{importacion.importado_por?.name ?? '—'} · {dayjs(importacion.created_at).format('DD/MM/YYYY HH:mm')}</Descriptions.Item>
            <Descriptions.Item label="Filas">{importacion.filas_totales}</Descriptions.Item>
            <Descriptions.Item label="Colaboradores">{importacion.colaboradores_total}</Descriptions.Item>
            <Descriptions.Item label="Importe">{soles(importacion.monto_total)}</Descriptions.Item>
            {importacion.aplicado_at && <Descriptions.Item label="Aplicado">{importacion.aplicado_por?.name ?? '—'} · {dayjs(importacion.aplicado_at).format('DD/MM/YYYY HH:mm')}</Descriptions.Item>}
            {importacion.anulado_at && <Descriptions.Item label="Anulado" span={2}>{importacion.anulado_por?.name ?? '—'} · {dayjs(importacion.anulado_at).format('DD/MM/YYYY HH:mm')} — {importacion.motivo_anulacion}</Descriptions.Item>}
          </Descriptions>

          {(importacion.errores_generales ?? []).map((e) => <Alert key={e} type="error" showIcon message={e} />)}
          {esValidada && (previsualizacion?.bloqueos ?? []).length > 0 && (
            <Alert type="warning" showIcon message="No se puede confirmar todavía" description={<ul className="m-0 list-disc pl-4">{previsualizacion.bloqueos.map((b) => <li key={b}>{b}</li>)}</ul>} />
          )}

          <Tabs
            items={[
              {
                key: 'filas',
                label: `Consumos (${detalles.length})`,
                children: (
                  <div className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <Segmented
                        value={filtro}
                        onChange={setFiltro}
                        options={[
                          { value: 'todas', label: `Todas (${detalles.length})` },
                          { value: 'error', label: `Errores (${importacion.filas_con_error})` },
                          { value: 'posible_duplicado', label: `Posibles duplicados (${detalles.filter((d) => d.estado === 'posible_duplicado').length})` },
                          { value: 'valido', label: 'Válidos' },
                        ]}
                      />
                      {puedeGestionar && esValidada && pendientesDuplicado.length > 0 && (
                        <Button disabled={seleccion.length === 0} onClick={admitirDuplicados}>Admitir {seleccion.length} duplicado(s) seleccionado(s)</Button>
                      )}
                    </div>
                    <Table
                      rowKey="id"
                      size="small"
                      dataSource={filas}
                      columns={columnasFilas}
                      scroll={{ x: 1000 }}
                      pagination={{ pageSize: 50, size: 'small' }}
                      rowSelection={puedeGestionar && esValidada && pendientesDuplicado.length > 0 ? {
                        selectedRowKeys: seleccion,
                        onChange: setSeleccion,
                        getCheckboxProps: (fila) => ({ disabled: fila.estado !== 'posible_duplicado' || !!fila.duplicado_admitido_at }),
                      } : undefined}
                      locale={{ emptyText: <Empty description="No hay filas con este filtro" /> }}
                    />
                  </div>
                ),
              },
              {
                key: 'colaboradores',
                label: `Resumen por colaborador (${previsualizacion?.total_colaboradores ?? 0})`,
                children: (
                  <Table
                    rowKey="colaborador_id"
                    size="small"
                    dataSource={previsualizacion?.colaboradores ?? []}
                    columns={columnasColaboradores}
                    pagination={{ pageSize: 50, size: 'small' }}
                    summary={() => (
                      <Table.Summary.Row>
                        <Table.Summary.Cell index={0} colSpan={3}><strong>Total</strong></Table.Summary.Cell>
                        <Table.Summary.Cell index={3} align="right"><strong>{soles(previsualizacion?.total_monto)}</strong></Table.Summary.Cell>
                        <Table.Summary.Cell index={4} colSpan={2} />
                      </Table.Summary.Row>
                    )}
                    locale={{ emptyText: <Empty description="Ningún consumo válido asociado a un colaborador" /> }}
                  />
                ),
              },
              {
                key: 'historial',
                label: 'Auditoría',
                children: (
                  <Table
                    rowKey="id"
                    size="small"
                    dataSource={importacion.eventos ?? []}
                    pagination={false}
                    columns={[
                      { title: 'Fecha', dataIndex: 'created_at', width: 160, render: (v) => dayjs(v).format('DD/MM/YYYY HH:mm:ss') },
                      { title: 'Acción', dataIndex: 'accion', render: (v) => v.replaceAll('_', ' ') },
                      { title: 'Usuario', dataIndex: ['usuario', 'name'], render: (v) => v ?? '—' },
                      { title: 'Motivo', dataIndex: 'motivo', render: (v) => v ?? '—' },
                    ]}
                  />
                ),
              },
            ]}
          />
        </div>
      )}
    </Drawer>
  );
}
