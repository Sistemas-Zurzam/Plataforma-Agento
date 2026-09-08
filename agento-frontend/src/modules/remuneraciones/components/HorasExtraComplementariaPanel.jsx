import { App, Button, DatePicker, Form, Input, InputNumber, Select, Table, Tabs, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';

const duracion = (minutos) => `${Math.floor(Number(minutos || 0) / 60)}h ${Number(minutos || 0) % 60}m`;

export default function HorasExtraComplementariaPanel({ ciclo, items, api, onUpdated }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [datos, setDatos] = useState({ horas: [], colaboradores: [] });
  const [seleccion, setSeleccion] = useState([]);
  const [minutos, setMinutos] = useState({});
  const [borradorId, setBorradorId] = useState(null);
  const [busqueda, setBusqueda] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const borradores = items.filter((item) => item.estado === 'calculada');

  const cargar = async () => {
    if (!ciclo) return;
    setLoading(true);
    try {
      const resultado = await api.fetchHorasExtraPendientesComplementaria(ciclo.id);
      setDatos(resultado);
      setMinutos(Object.fromEntries(resultado.horas.map((hora) => [hora.id, hora.minutos_pendientes])));
    } catch (e) {
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudieron cargar las horas extra pendientes.');
    } finally { setLoading(false); }
  };

  useEffect(() => { cargar(); }, [ciclo?.id]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!borradores.some((item) => item.id === borradorId)) setBorradorId(borradores[0]?.id ?? null);
  }, [items, borradorId]); // eslint-disable-line react-hooks/exhaustive-deps

  const filtradas = useMemo(() => {
    const termino = busqueda.trim().toLocaleLowerCase();
    return termino ? datos.horas.filter((h) => h.colaborador.toLocaleLowerCase().includes(termino)) : datos.horas;
  }, [datos.horas, busqueda]);

  const guardar = async (detectadas = [], manuales = []) => {
    if (!borradorId) return message.warning('Primero debe existir una complementaria calculada. Puedes usar cualquier reintegro en borrador como lote único.');
    setSaving(true);
    try {
      await api.agregarHorasExtraComplementaria(borradorId, detectadas, manuales);
      message.success('Horas extra agregadas al borrador complementario.');
      setSeleccion([]);
      form.resetFields(['colaborador_id', 'fecha', 'minutos', 'tasa', 'motivo']);
      await onUpdated();
      await cargar();
    } catch (e) {
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudieron agregar las horas extra.');
    } finally { setSaving(false); }
  };

  const pagarDetectadas = () => guardar(seleccion.map((id) => ({ hora_extra_id: id, minutos: Number(minutos[id]) })), []);
  const registrarManual = async () => {
    const values = await form.validateFields();
    const colaborador = datos.colaboradores.find((c) => c.colaborador_id === values.colaborador_id);
    return guardar([], [{ boleta_id: colaborador.boleta_id, fecha: values.fecha.format('YYYY-MM-DD'), minutos: values.minutos, tasa: values.tasa, motivo: values.motivo }]);
  };

  const selectorBorrador = <Select className="w-full" placeholder="Selecciona el borrador de destino" value={borradorId} onChange={setBorradorId}
    options={borradores.map((item) => ({ value: item.id, label: `${item.nombre} — ${item.motivo}` }))} />;

  return <div className="space-y-3">
    <div><div className="mb-1 text-xs font-medium text-gray-600">Complementaria calculada de destino</div>{selectorBorrador}</div>
    {!borradores.length && <p className="rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-700">No hay un borrador calculado. Genera primero un reintegro y luego podrás añadirle las horas extra.</p>}
    <Tabs items={[
      { key: 'huellero', label: 'Registradas por huellero', children: <div className="space-y-2">
        <Input.Search allowClear placeholder="Buscar colaborador" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} />
        <Table rowKey="id" size="small" loading={loading} dataSource={filtradas} scroll={{ x: 700 }}
          rowSelection={{ selectedRowKeys: seleccion, onChange: setSeleccion, preserveSelectedRowKeys: true }}
          pagination={{ defaultPageSize: 10, showSizeChanger: true }} locale={{ emptyText: 'No hay horas extra aprobadas pendientes de pago.' }}
          columns={[
            { title: 'Colaborador', dataIndex: 'colaborador' },
            { title: 'Fecha', dataIndex: 'fecha', width: 105, render: (v) => dayjs(v).format('DD/MM/YYYY') },
            { title: 'Tasa', dataIndex: 'tasa', width: 70, render: (v) => <Tag>{v}%</Tag> },
            { title: 'Pendiente', dataIndex: 'minutos_pendientes', width: 95, render: duracion },
            { title: 'A pagar (min)', width: 125, render: (_, h) => <InputNumber min={1} max={h.minutos_pendientes} value={minutos[h.id]} onChange={(v) => setMinutos((prev) => ({ ...prev, [h.id]: v }))} /> },
          ]} />
        <Button type="primary" loading={saving} disabled={!seleccion.length || !borradorId} onClick={pagarDetectadas}>Agregar seleccionadas ({seleccion.length})</Button>
        <p className="text-xs text-gray-500">Solo aparecen horas aprobadas en Asistencia y todavía no cubiertas por la boleta original ni otra complementaria.</p>
      </div> },
      { key: 'manual', label: 'Ingresar manualmente', children: <Form form={form} layout="vertical">
        <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={datos.colaboradores.map((c) => ({ value: c.colaborador_id, label: `${c.colaborador} · ${c.documento}` }))} /></Form.Item>
        <div className="grid grid-cols-3 gap-2">
          <Form.Item name="fecha" label="Fecha" rules={[{ required: true }]}><DatePicker className="w-full" format="DD/MM/YYYY" minDate={dayjs(ciclo?.fecha_inicio)} maxDate={dayjs(ciclo?.fecha_fin)} /></Form.Item>
          <Form.Item name="minutos" label="Minutos" rules={[{ required: true }]}><InputNumber className="w-full" min={1} max={1440} /></Form.Item>
          <Form.Item name="tasa" label="Tasa" rules={[{ required: true }]}><Select options={[{ value: '25', label: '25%' }, { value: '35', label: '35%' }, { value: '100', label: '100%' }]} /></Form.Item>
        </div>
        <Form.Item name="motivo" label="Motivo / sustento" rules={[{ required: true, min: 5 }]}><Input.TextArea maxLength={255} rows={2} placeholder="Ej. Trabajo autorizado sin marcación biométrica" /></Form.Item>
        <Button type="primary" loading={saving} disabled={!borradorId} onClick={registrarManual}>Calcular y agregar</Button>
        <p className="mt-2 text-xs text-gray-500">El importe se calcula con el sueldo histórico vigente en la fecha. El motivo es obligatorio porque no existe evidencia del huellero.</p>
      </Form> },
    ]} />
  </div>;
}
