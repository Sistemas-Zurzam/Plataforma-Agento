import { Alert, App, Button, DatePicker, Modal, Table, Upload } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';

export default function ImportarComprobantesRhModal({ open, ciclo, importar, onCancel }) {
  const { message } = App.useApp();
  const [archivo, setArchivo] = useState(null);
  const [fechaPago, setFechaPago] = useState(null);
  const [revision, setRevision] = useState(null);
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!open) return;
    setArchivo(null); setRevision(null); setFechaPago(ciclo?.fecha_pago ? dayjs(ciclo.fecha_pago) : dayjs(ciclo?.fecha_fin));
  }, [open, ciclo]);
  const ejecutar = async (confirmar = false) => {
    if (!archivo || !fechaPago) return message.warning('Selecciona el Excel y la fecha de pago.');
    setLoading(true);
    try {
      const data = await importar(ciclo.id, archivo, fechaPago.format('YYYY-MM-DD'), confirmar);
      if (confirmar) { message.success(`${data.validos} comprobante(s) RH importado(s).`); onCancel(); }
      else setRevision(data);
    } catch (e) { message.error(e.response?.data?.message ?? 'No se pudo procesar el Excel.'); }
    finally { setLoading(false); }
  };
  return <Modal title="Importar comprobantes de recibos por honorarios" open={open} onCancel={onCancel} footer={null} width={900} destroyOnHidden>
    <Alert type="info" showIcon className="mb-4" message={`Empresa: ${ciclo?.empresa?.nombre_comercial ?? ''} · Ciclo: ${ciclo?.nombre ?? ''}`}
      description="El match se realiza únicamente contra las boletas RH de esta empresa y ciclo, usando el número de documento del emisor." />
    <div className="mb-4 flex flex-wrap items-end gap-3">
      <Upload accept=".xlsx,.xls" maxCount={1} fileList={archivo ? [archivo] : []} beforeUpload={(f) => { setArchivo(f); setRevision(null); return false; }} onRemove={() => { setArchivo(null); setRevision(null); }}>
        <Button icon={<UploadOutlined />}>Seleccionar Excel RH</Button>
      </Upload>
      <div><p className="mb-1 text-xs text-gray-500">Fecha de pago</p><DatePicker value={fechaPago} onChange={setFechaPago} format="DD/MM/YYYY" /></div>
      <Button type="primary" loading={loading} onClick={() => ejecutar(false)}>Revisar archivo</Button>
    </div>
    {revision && <>
      <Alert className="mb-3" type={revision.listo ? 'success' : 'error'} showIcon message={`${revision.resumen.validos} válidos · ${revision.resumen.omitidos} anulados/revertidos · ${revision.resumen.errores} errores`} />
      <Table size="small" pagination={{ pageSize: 8 }} rowKey={(r) => `${r.fila}-${r.serie}-${r.numero}`} dataSource={revision.filas}
        columns={[{ title: 'Fila', dataIndex: 'fila' }, { title: 'Documento', dataIndex: 'documento' }, { title: 'Colaborador', dataIndex: 'colaborador' }, { title: 'Comprobante', render: (_, r) => `${r.serie}-${r.numero}` }, { title: 'Emisión', dataIndex: 'fecha_emision' }, { title: 'Renta bruta', dataIndex: 'monto_total_servicio', render: (v) => `S/ ${Number(v).toFixed(2)}` }]} />
      {revision.errores?.map((e) => <Alert key={`${e.fila}-${e.mensaje}`} className="mt-2" type="error" message={`Fila ${e.fila}: ${e.mensaje}`} />)}
      <div className="mt-4 flex justify-end"><Button type="primary" disabled={!revision.listo} loading={loading} onClick={() => ejecutar(true)}>Confirmar importación</Button></div>
    </>}
  </Modal>;
}
