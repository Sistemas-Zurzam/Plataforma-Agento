import { Alert, App, Button, Input, Select, Table, Upload } from 'antd';
import { useEffect, useState } from 'react';
import { useConceptoDefinicionesPlame } from '../../configuracion/hooks/useConceptoDefinicionesPlame';

export default function ImportarBonoAsistenciaPanel({ ciclo, api, onUpdated, catalogo = [] }) {
  const { message } = App.useApp();
  const { definiciones, fetchDefiniciones, loading: cargandoDefiniciones } = useConceptoDefinicionesPlame();
  const [archivo, setArchivo] = useState(null);
  const [validacion, setValidacion] = useState(null);
  const [loading, setLoading] = useState(false);
  const [concepto, setConcepto] = useState(null);
  const [definicion, setDefinicion] = useState(null);
  const [motivo, setMotivo] = useState('Bonos por asistencia aprobados por Gerencia');
  useEffect(() => { setArchivo(null); setValidacion(null); }, [ciclo.id]);
  const procesar = async (generar) => {
    setLoading(true);
    try {
      const resultado = await api.importarExcelBono(ciclo.id, archivo,
        generar ? { generar: 1, concepto_id: concepto, concepto_definicion_id: definicion, motivo } : {});
      if (generar) {
        setArchivo(null); setValidacion(null);
        message.success('Complementaria de bonos generada. Revísala y apruébala para exportar el TXT.');
        await onUpdated();
      } else setValidacion(resultado);
    } catch (e) {
      setValidacion(null);
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudo procesar el Excel.');
    } finally { setLoading(false); }
  };
  return <div className="space-y-3 border-t pt-3">
    <strong>Importar decisiones de Gerencia</strong>
    <p>Elige el Excel .xlsx devuelto. Primero se muestran los errores por fila y el total aprobado. Generar vuelve a comprobar el archivo y crea un borrador con los bonos brutos aprobados, tanto de planilla como de honorarios.</p>
    <Upload accept=".xlsx" maxCount={1} fileList={archivo ? [archivo] : []} disabled={loading}
      beforeUpload={(file) => { setArchivo(file); setValidacion(null); return false; }}
      onRemove={() => { setArchivo(null); setValidacion(null); }}>
      <Button disabled={loading}>Seleccionar Excel devuelto</Button>
    </Upload>
    <Button onClick={() => procesar(false)} disabled={!archivo || loading} loading={loading}>Validar Excel</Button>
    {validacion && <>
      <Alert type={validacion.listo ? 'success' : 'warning'} showIcon
        message={`${validacion.aprobados} aprobados · Total bruto S/ ${Number(validacion.total_bruto).toFixed(2)} · ${validacion.errores.length} errores`}
        description="No se genera ningún pago si hay errores. Las filas pendientes o rechazadas no se incluyen en la complementaria." />
      {validacion.errores.length > 0 && <Table size="small" rowKey="fila" dataSource={validacion.errores} columns={[{ title: 'Fila Excel', dataIndex: 'fila' }, { title: 'Corregir', dataIndex: 'mensaje' }]} />}
      <Table size="small" rowKey="fila" dataSource={validacion.filas} pagination={{ defaultPageSize: 10 }} columns={[
        { title: 'Colaborador', dataIndex: 'colaborador' }, { title: 'Decisión', dataIndex: 'decision' },
        { title: 'Meta', dataIndex: 'meta' }, { title: 'Bono bruto S/', dataIndex: 'monto', render: (v) => Number(v).toFixed(2) },
        { title: 'Aprobó', dataIndex: 'responsable' },
      ]} />
      <p className="text-xs text-gray-500">Para trabajadores en planilla, selecciona concepto y clasificación PLAME. Los honorarios se registran como adicional de honorario y no usan Tabla 22. El TXT se genera por categoría después de aprobar la complementaria.</p>
      <Select className="w-full" allowClear placeholder="Concepto para colaboradores en planilla" value={concepto}
        onChange={(id) => { setConcepto(id); setDefinicion(null); const c = catalogo.find((x) => x.id === id); if (c) fetchDefiniciones(c.id, c.codigo); }}
        options={catalogo.filter((c) => ['BONIFICACION', 'BONO_NO_REMUNERATIVO'].includes(c.codigo)).map((c) => ({ value: c.id, label: c.nombre }))} />
      <Select className="w-full" allowClear placeholder="Clasificación PLAME para planilla" value={definicion} onChange={setDefinicion}
        disabled={!concepto} loading={cargandoDefiniciones} options={definiciones.filter((d) => d.activo).map((d) => ({ value: d.id, label: `${d.nombre} (${d.codigo_plame})` }))} />
      <Input value={motivo} onChange={(e) => setMotivo(e.target.value)} maxLength={255} placeholder="Motivo de la complementaria" />
      <Button type="primary" loading={loading} disabled={!validacion.listo || !motivo.trim() || loading} onClick={() => procesar(true)}>Generar complementaria de bonos aprobados</Button>
    </>}
  </div>;
}
