import { Alert, App, Button, DatePicker, Input, InputNumber, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import ImportarBonoAsistenciaPanel from './ImportarBonoAsistenciaPanel';

export default function ReporteBonoAsistenciaPanel({ ciclo, api, onUpdated, catalogo }) {
  const { message } = App.useApp();
  const [mes, setMes] = useState(dayjs(ciclo.fecha_inicio));
  const [reporte, setReporte] = useState(null);
  const [loading, setLoading] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [monto, setMonto] = useState(150);
  useEffect(() => { setReporte(null); setMes(dayjs(ciclo.fecha_inicio)); }, [ciclo.id, ciclo.fecha_inicio]);
  const cargar = async () => {
    setLoading(true); setReporte(null);
    try {
      setReporte(await api.fetchColaboradoresPorAsistencia(ciclo.id, undefined, undefined, undefined,
        { reporte_gerencia: true, mes: mes.format('YYYY-MM') }));
    } catch (e) { message.error(e.response?.data?.message ?? 'No se pudo generar el reporte.'); }
    finally { setLoading(false); }
  };
  const importe = (porcentaje) => ((monto || 0) * porcentaje / 100).toFixed(2);
  const exportar = async () => {
    setLoading(true);
    try { await api.exportarExcelBono(ciclo.id, mes.format('YYYY-MM'), monto); }
    catch (e) { message.error(e.response?.data?.message ?? 'No se pudo exportar el Excel.'); }
    finally { setLoading(false); }
  };
  const filas = (reporte?.colaboradores ?? []).filter((c) => `${c.colaborador} ${c.documento}`.toLowerCase().includes(busqueda.toLowerCase()));
  return <div className="space-y-3">
    <Alert type="info" showIcon message="Propuesta para revisión de Gerencia"
      description="Incluye a todos los colaboradores del mes, con o sin boleta. Los porcentajes son preliminares: RR. HH. valida sustentos y autorizaciones; Gerencia decide el bono y la recuperación por meta. Exportar no genera pagos ni modifica comisiones." />
    <div className="flex flex-wrap gap-2 items-center">
      <DatePicker picker="month" value={mes} allowClear={false} minDate={dayjs('2026-08-01')} onChange={(v) => { setMes(v); setReporte(null); }} />
      <span>Bono base S/</span><InputNumber min={0} precision={2} value={monto} onChange={setMonto} />
      <Button onClick={cargar} loading={loading}>Evaluar todos los colaboradores</Button>
      <Button onClick={exportar} disabled={!reporte?.colaboradores.length || loading || !monto}>Exportar Excel para Gerencia</Button>
    </div>
    <p className="text-xs text-gray-600">Criterios aplicados desde agosto de 2026: 26 jornadas y 4 descansos. Sin faltas: 100%; 1 justificada: 50% inicial + 50% por meta; 2 justificadas: 0% inicial + 50% por meta. Una falta injustificada o 3 tardanzas: 0%, sin recuperación. Las horas extra no compensan incidencias.</p>
    <Input.Search allowClear placeholder="Buscar colaborador o documento" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} />
    <Table rowKey="colaborador_id" size="small" loading={loading} dataSource={filas} scroll={{ x: 1550 }}
      pagination={{ defaultPageSize: 10, showSizeChanger: true, showTotal: (n) => `${n} colaboradores` }}
      locale={{ emptyText: reporte ? 'Sin colaboradores para este mes.' : 'Evalúa el mes para preparar el reporte.' }}
      columns={[
        { title: 'Colaborador', dataIndex: 'colaborador', fixed: 'left', width: 220 },
        { title: 'Días efectivos', dataIndex: 'dias_efectivos' }, { title: 'Descansos', dataIndex: 'descansos' },
        { title: 'Tardanzas', dataIndex: 'tardanzas' }, { title: 'F. justificadas', dataIndex: 'faltas_justificadas' },
        { title: 'F. injustificadas', dataIndex: 'faltas_injustificadas' },
        { title: 'Evaluación', dataIndex: 'evaluacion', render: (v) => <Tag color={v === 'Candidato' ? 'green' : v === 'No cumple' ? 'red' : 'orange'}>{v}</Tag> },
        { title: 'Inicial provisional', render: (_, c) => `${c.porcentaje_inicial}% · S/ ${importe(c.porcentaje_inicial)}` },
        { title: 'Recuperable por meta', render: (_, c) => `${c.porcentaje_recuperable}% · S/ ${importe(c.porcentaje_recuperable)}` },
        { title: 'Observaciones', dataIndex: 'observaciones', width: 350 },
      ]} />
    <p className="text-xs text-gray-500">Gerencia completa las celdas amarillas del Excel. Importa el archivo devuelto en el ciclo pagado del mismo mes para validar y generar los bonos aprobados.</p>
    <ImportarBonoAsistenciaPanel ciclo={ciclo} api={api} onUpdated={onUpdated} catalogo={catalogo} />
  </div>;
}
