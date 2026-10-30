import { DownloadOutlined, EyeOutlined, ReloadOutlined, UploadOutlined } from '@ant-design/icons';
import { Alert, App, Button, Card, Empty, Input, Select, Space, Spin, Statistic, Table, Tag, Upload } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { useConsumoComida } from '../hooks/useConsumoComida';
import { consumoComidaApi } from '../services/consumoComidaApi';
import { ESTADO_IMPORTACION, mensajeError, soles } from '../utils/consumoComida';
import ConsumoComidaImportacionDrawer from './ConsumoComidaImportacionDrawer';

const TAMANO_MAXIMO = 10 * 1024 * 1024;

function ConsumosColaborador({ cicloId, colaboradorId }) {
  const [consumos, setConsumos] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    consumoComidaApi.consumosColaborador(cicloId, colaboradorId)
      .then(setConsumos)
      .catch((err) => setError(mensajeError(err, 'No se pudieron cargar los consumos.')));
  }, [cicloId, colaboradorId]);

  if (error) return <Alert type="error" showIcon message={error} />;
  if (!consumos) return <div className="flex justify-center py-4"><Spin size="small" /></div>;

  return (
    <Table
      rowKey="id"
      size="small"
      dataSource={consumos}
      pagination={false}
      columns={[
        { title: 'Fecha', dataIndex: 'fecha_consumo', width: 110 },
        { title: 'Concepto', dataIndex: 'concepto' },
        { title: 'Referencia', dataIndex: 'referencia', render: (v) => v ?? '—' },
        { title: 'Importación', dataIndex: 'importacion_id', width: 110, render: (v) => `#${v}` },
        { title: 'Monto', dataIndex: 'monto', width: 110, align: 'right', render: soles },
      ]}
    />
  );
}

/**
 * Consumo de comida del ciclo: descuentos importados desde la plantilla
 * oficial. El backend escribe el descuento como concepto del período y el
 * motor lo incorpora al recalcular la planilla; mientras tanto el
 * colaborador figura como "pendiente de recálculo".
 */
export default function ConsumoComidaTab({ ciclo, puedeGestionar }) {
  const { message } = App.useApp();
  const { resumen, importaciones, loading, error, cargar } = useConsumoComida(ciclo?.id);
  const [sistemaOrigen, setSistemaOrigen] = useState('');
  const [reemplazaId, setReemplazaId] = useState(undefined);
  const [subiendo, setSubiendo] = useState(false);
  const [importacionAbierta, setImportacionAbierta] = useState(null);

  useEffect(() => { cargar(); }, [cargar]);

  const subir = async (archivo) => {
    if (!archivo.name.toLowerCase().endsWith('.xlsx')) { message.error('Selecciona un archivo .xlsx.'); return false; }
    if (archivo.size > TAMANO_MAXIMO) { message.error('El archivo no puede superar 10 MB.'); return false; }
    setSubiendo(true);
    try {
      const resultado = await consumoComidaApi.importar(ciclo.id, archivo, { sistemaOrigen: sistemaOrigen.trim(), reemplazaImportacionId: reemplazaId });
      const { importacion } = resultado;
      if (importacion.estado === 'validada') message.success('Archivo validado. Revisa la previsualización y confirma la importación.');
      else message.warning('El archivo tiene errores. Revisa el detalle por fila.');
      setReemplazaId(undefined);
      await cargar();
      setImportacionAbierta(importacion.id);
    } catch (err) {
      message.error(mensajeError(err, 'No se pudo validar el archivo.'));
    } finally {
      setSubiendo(false);
    }
    return false;
  };

  const anuladas = importaciones.filter((i) => i.estado === 'anulada');

  const columnasHistorial = [
    { title: '#', dataIndex: 'id', width: 64 },
    { title: 'Archivo', dataIndex: 'archivo_nombre_original', ellipsis: true },
    { title: 'Usuario', dataIndex: ['importado_por', 'name'], width: 160, render: (v) => v ?? '—' },
    { title: 'Fecha', dataIndex: 'created_at', width: 140, render: (v) => dayjs(v).format('DD/MM/YYYY HH:mm') },
    { title: 'Estado', dataIndex: 'estado', width: 120, render: (v) => <Tag color={ESTADO_IMPORTACION[v]?.color}>{ESTADO_IMPORTACION[v]?.label ?? v}</Tag> },
    { title: 'Colaboradores', dataIndex: 'colaboradores_total', width: 120, align: 'center' },
    { title: 'Total', dataIndex: 'monto_total', width: 120, align: 'right', render: soles },
    {
      title: 'Acciones', width: 120,
      render: (_, fila) => <Button size="small" icon={<EyeOutlined />} onClick={() => setImportacionAbierta(fila.id)}>Ver detalle</Button>,
    },
  ];

  const columnasColaboradores = [
    { title: 'Documento', dataIndex: 'documento', width: 120 },
    { title: 'Colaborador', dataIndex: 'nombre' },
    { title: 'Consumos', dataIndex: 'consumos', width: 100, align: 'center' },
    { title: 'Descuento', dataIndex: 'monto', width: 130, align: 'right', render: soles },
    {
      title: 'En boleta', width: 200,
      render: (_, fila) => (fila.pendiente_recalculo
        ? <Tag color="orange">Pendiente de recálculo</Tag>
        : fila.boleta ? <Tag color="green">{`Incorporado · boleta v${fila.boleta.version}`}</Tag> : <Tag>Sin boleta</Tag>),
    },
  ];

  if (!ciclo) return <Empty description="Selecciona un ciclo remunerativo" className="mt-8" />;

  return (
    <div className="space-y-4">
      {error && <Alert type="error" showIcon message={error} action={<Button size="small" onClick={cargar}>Reintentar</Button>} />}
      {resumen && !resumen.concepto_configurado && (
        <Alert type="warning" showIcon message='El concepto "Adelanto 2" (descuento por consumo de comida) no está configurado como egreso activo: puedes validar archivos, pero no aplicarlos.' />
      )}
      {resumen && !resumen.ciclo_modificable && (
        <Alert type="info" showIcon message="El ciclo está cerrado, pagado o calculándose: no admite aplicar ni anular descuentos." />
      )}
      {resumen?.pendientes_recalculo > 0 && (
        <Alert type="warning" showIcon message={`${resumen.pendientes_recalculo} colaborador(es) con descuentos aplicados o anulados que la planilla todavía no refleja. Recalcula el ciclo antes de aprobar o cerrar.`} />
      )}

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <Card size="small"><Statistic title="Descuento por consumo de comida" value={resumen?.total_monto ?? '0.00'} prefix="S/" loading={loading && !resumen} /></Card>
        <Card size="small"><Statistic title="Colaboradores con descuento" value={resumen?.total_colaboradores ?? 0} loading={loading && !resumen} /></Card>
        <Card size="small"><Statistic title="Pendientes de recálculo" value={resumen?.pendientes_recalculo ?? 0} loading={loading && !resumen} /></Card>
      </div>

      <Card size="small" title="Cargar consumos">
        <div className="flex flex-wrap items-end gap-3">
          <Button icon={<DownloadOutlined />} onClick={() => consumoComidaApi.descargarPlantilla().catch(() => message.error('No se pudo descargar la plantilla.'))}>Descargar plantilla</Button>
          {puedeGestionar && (
            <>
              <Input className="w-56" placeholder="Sistema de origen (opcional)" value={sistemaOrigen} maxLength={60} onChange={(e) => setSistemaOrigen(e.target.value)} />
              {anuladas.length > 0 && (
                <Select
                  allowClear
                  className="w-64"
                  placeholder="Reemplaza a (opcional)"
                  value={reemplazaId}
                  onChange={setReemplazaId}
                  options={anuladas.map((i) => ({ value: i.id, label: `#${i.id} · ${i.archivo_nombre_original}` }))}
                />
              )}
              <Upload accept=".xlsx" showUploadList={false} beforeUpload={(archivo) => { subir(archivo); return false; }} disabled={subiendo}>
                <Button type="primary" icon={<UploadOutlined />} loading={subiendo}>Subir y validar .xlsx</Button>
              </Upload>
            </>
          )}
          <Button icon={<ReloadOutlined />} onClick={cargar} loading={loading}>Actualizar</Button>
        </div>
        <p className="mb-0 mt-2 text-xs text-gray-500">Validar no aplica descuentos: revisa la previsualización y confirma la importación desde su detalle. Máximo 10 MB.</p>
      </Card>

      <Card size="small" title="Descuento por colaborador">
        <Table
          rowKey="colaborador_id"
          size="small"
          loading={loading}
          dataSource={resumen?.colaboradores ?? []}
          columns={columnasColaboradores}
          expandable={{ expandedRowRender: (fila) => <ConsumosColaborador cicloId={ciclo.id} colaboradorId={fila.colaborador_id} /> }}
          pagination={{ pageSize: 20, size: 'small' }}
          locale={{ emptyText: <Empty description="Todavía no hay descuentos por consumo de comida aplicados en este ciclo" /> }}
        />
      </Card>

      <Card size="small" title="Historial de importaciones">
        <Space direction="vertical" className="w-full">
          <Table
            rowKey="id"
            size="small"
            loading={loading}
            dataSource={importaciones}
            columns={columnasHistorial}
            scroll={{ x: 900 }}
            pagination={{ pageSize: 10, size: 'small' }}
            locale={{ emptyText: <Empty description="No hay importaciones en este ciclo" /> }}
          />
        </Space>
      </Card>

      <ConsumoComidaImportacionDrawer
        key={importacionAbierta ?? 'cerrado'}
        importacionId={importacionAbierta}
        open={!!importacionAbierta}
        onClose={() => setImportacionAbierta(null)}
        onCambio={cargar}
        puedeGestionar={puedeGestionar}
      />
    </div>
  );
}
