import { BankOutlined, CreditCardOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { App, Button, Checkbox, DatePicker, Descriptions, Form, Input, Modal, Popconfirm, Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { useCuentasBancariasEmpresa } from '../../configuracion/hooks/useCuentasBancariasEmpresa';
import AgregarConceptoComplementariaModal, { CONCEPTOS_REGISTRABLES } from './AgregarConceptoComplementariaModal';

const soles = (valor) => `S/ ${Number(valor || 0).toFixed(2)}`;
const nombreConcepto = (codigo) => CONCEPTOS_REGISTRABLES.find((c) => c.codigo === codigo)?.nombre ?? codigo;

export default function PlanillasComplementariasModal({ open, onCancel, ciclo, boletaIds, api, permisos, catalogoConceptos = [] }) {
  const { message } = App.useApp();
  const { cuentas, fetchCuentas } = useCuentasBancariasEmpresa(ciclo?.empresa?.id);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [creando, setCreando] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [tipoRegularizacion, setTipoRegularizacion] = useState('diferencia_ciclo');
  const [fechaFeriado, setFechaFeriado] = useState(null);
  const [feriadosDisponibles, setFeriadosDisponibles] = useState([]);
  const [sinDescansoSustitutorio, setSinDescansoSustitutorio] = useState(false);
  const [cuentaBcp, setCuentaBcp] = useState(null);
  const [categoria, setCategoria] = useState('5');
  const [detalleParaConcepto, setDetalleParaConcepto] = useState(null);
  const [agregandoConcepto, setAgregandoConcepto] = useState(false);

  const cargar = async () => {
    if (!ciclo) return;
    setLoading(true);
    try { setItems(await api.fetchComplementarias(ciclo.id)); } finally { setLoading(false); }
  };

  useEffect(() => {
    if (!open || !ciclo) return;
    Promise.resolve().then(() => {
      cargar();
      fetchCuentas();
      setMotivo('');
      setTipoRegularizacion('diferencia_ciclo');
      setFechaFeriado(null);
      setFeriadosDisponibles([]);
      setSinDescansoSustitutorio(false);
      setCategoria('5');
      api.fetchFeriadosHistoricos(ciclo.id).then(setFeriadosDisponibles);
    });
  }, [open, ciclo?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const ejecutar = async (accion, exito) => {
    try { await accion(); message.success(exito); await cargar(); } catch (e) { message.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? 'No se pudo completar la operación'); }
  };

  const crear = async () => {
    if (!motivo.trim()) return message.warning('Ingresa el motivo de la regularización.');
    if (!boletaIds.length) return message.warning('Selecciona las boletas pagadas que deseas regularizar.');
    if (tipoRegularizacion === 'feriado_historico' && !fechaFeriado) return message.warning('Selecciona la fecha del feriado histórico.');
    if (tipoRegularizacion === 'feriado_historico' && !sinDescansoSustitutorio) return message.warning('Confirma que no se otorgó descanso sustitutorio.');
    setCreando(true);
    try {
      const accion = tipoRegularizacion === 'feriado_historico'
        ? () => api.crearRegularizacionFeriadoHistorico(ciclo.id, boletaIds, fechaFeriado.format('YYYY-MM-DD'), motivo.trim())
        : () => api.crearComplementaria(ciclo.id, boletaIds, motivo.trim());
      await ejecutar(accion, tipoRegularizacion === 'feriado_historico' ? 'Regularización histórica calculada.' : 'Planilla complementaria calculada.');
    } finally {
      setCreando(false);
    }
  };

  const exportar = async (item, banco) => {
    const esBcp = banco === 'telecredito-bcp';
    if (esBcp && !cuentaBcp) return message.warning('Selecciona una cuenta BCP de cargo.');
    const parametros = esBcp
      ? { cuenta_cargo_id: cuentaBcp, fecha_proceso: dayjs().format('YYYY-MM-DD'), subtipo: categoria === '4' ? '4' : 'X' }
      : { subtipo: categoria };
    await ejecutar(() => api.exportarComplementaria(item.id, banco, parametros), 'Archivo complementario descargado.');
  };

  const agregarConcepto = async (detalleId, conceptoId, conceptoDefinicionId, monto, motivoConcepto) => {
    setAgregandoConcepto(true);
    try {
      await api.agregarConceptoComplementaria(detalleId, conceptoId, conceptoDefinicionId, monto, motivoConcepto);
      message.success('Concepto agregado a la complementaria.');
      setDetalleParaConcepto(null);
      await cargar();
    } catch (e) {
      message.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? 'No se pudo agregar el concepto.');
    } finally {
      setAgregandoConcepto(false);
    }
  };

  const eliminarConcepto = async (detalleId, lineaId) => {
    await ejecutar(() => api.eliminarConceptoComplementaria(detalleId, lineaId), 'Concepto eliminado.');
  };

  const columnasDetalle = (item) => [
    { title: 'Colaborador', dataIndex: 'colaborador' },
    { title: 'Neto ya cubierto', dataIndex: 'neto_original', render: soles },
    { title: 'Neto corregido', dataIndex: 'neto_recalculado', render: soles },
    { title: 'Diferencia', dataIndex: 'diferencia_neta', render: (v) => <span className={Number(v) >= 0 ? 'text-green-600' : 'text-red-500'}>{soles(v)}</span> },
    ...(item.estado === 'calculada' && permisos.calcular ? [{
      title: '',
      key: 'acciones',
      width: 40,
      render: (_, detalle) => (
        <Button
          size="small"
          type="text"
          icon={<PlusOutlined />}
          title="Agregar concepto (bono/comisión/descuento)"
          onClick={() => setDetalleParaConcepto({ detalleId: detalle.id, colaboradorNombre: detalle.colaborador })}
        />
      ),
    }] : []),
  ];

  const cuentasBcp = cuentas.filter((c) => c.activo && c.uso === 'haberes' && c.banco?.codigo === 'bcp');

  return (
    <Modal title="Planillas complementarias" open={open} onCancel={onCancel} footer={null} width={980} destroyOnHidden>
      <div className="space-y-4">
        <Descriptions size="small" bordered column={3}>
          <Descriptions.Item label="Empresa">{ciclo?.empresa?.nombre_comercial}</Descriptions.Item>
          <Descriptions.Item label="Ciclo original">{ciclo?.nombre}</Descriptions.Item>
          <Descriptions.Item label="Estado"><Tag color="green">{ciclo?.estado}</Tag></Descriptions.Item>
        </Descriptions>

        <div className="rounded-lg border border-blue-200 bg-blue-50 p-3">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <Select className="w-72" value={tipoRegularizacion} onChange={setTipoRegularizacion} options={[
              { value: 'diferencia_ciclo', label: 'Diferencia del ciclo pagado' },
              { value: 'feriado_historico', label: 'Feriado histórico no pagado' },
            ]} />
            {tipoRegularizacion === 'feriado_historico' && (
              <DatePicker value={fechaFeriado} onChange={setFechaFeriado} format="DD/MM/YYYY" placeholder="Fecha del feriado"
                disabledDate={(fecha) => !feriadosDisponibles.includes(fecha.format('YYYY-MM-DD'))}
                defaultPickerValue={ciclo?.fecha_inicio ? dayjs(ciclo.fecha_inicio).subtract(1, 'day') : undefined} />
            )}
          </div>
          <div className="mb-2 text-sm">
            {tipoRegularizacion === 'feriado_historico'
              ? <>Se usará el sueldo vigente en el feriado y se calculará automáticamente <strong>sueldo / 30 × 2</strong> para las <strong>{boletaIds.length}</strong> personas seleccionadas.</>
              : <>Se calculará únicamente la diferencia de las <strong>{boletaIds.length}</strong> boletas seleccionadas. La boleta pagada no se modifica.</>}
          </div>
          {tipoRegularizacion === 'feriado_historico' && (
            <Checkbox className="mb-2" checked={sinDescansoSustitutorio} onChange={(event) => setSinDescansoSustitutorio(event.target.checked)}>
              Confirmo que trabajaron el feriado y no recibieron descanso sustitutorio
            </Checkbox>
          )}
          <div className="flex gap-2">
            <Input.TextArea value={motivo} onChange={(e) => setMotivo(e.target.value)} autoSize={{ minRows: 1, maxRows: 3 }} placeholder="Motivo: regularización de asistencia del 29/08..." />
            <Button type="primary" icon={<PlusOutlined />} loading={creando} disabled={ciclo?.estado !== 'pagado' || !permisos.calcular} onClick={crear}>
              {tipoRegularizacion === 'feriado_historico' ? 'Calcular feriado' : 'Calcular diferencia'}
            </Button>
          </div>
        </div>

        <div className="flex gap-2">
          <Select className="w-56" value={categoria} onChange={setCategoria} options={[{ value: '5', label: '5ta categoría' }, { value: '4', label: '4ta categoría' }]} />
          <Select className="w-80" allowClear placeholder="Cuenta BCP para Telecrédito" value={cuentaBcp} onChange={setCuentaBcp}
            options={cuentasBcp.map((c) => ({ value: c.id, label: `${c.banco?.nombre ?? 'BCP'} • ${c.numero_cuenta}` }))} />
        </div>

        <div className="max-h-[55vh] space-y-4 overflow-y-auto pr-1">
        {items.map((item) => (
          <div key={item.id} className="rounded-lg border p-3">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div><strong>{item.nombre}</strong> <Tag>{item.estado}</Tag><div className="text-xs text-gray-500">{item.motivo}</div></div>
              <div className="flex gap-2">
                <span className="self-center text-sm text-green-700">A pagar: {soles(item.total_a_pagar)}</span>
                {Number(item.saldo_a_descontar) > 0 && <span className="self-center text-sm text-red-600">A descontar: {soles(item.saldo_a_descontar)}</span>}
                {item.estado === 'calculada' && permisos.calcular && (
                  <Popconfirm
                    title="¿Eliminar esta complementaria?"
                    description="Se borra por completo, incluyendo los conceptos que hayas agregado. La boleta original no se ve afectada."
                    onConfirm={() => ejecutar(() => api.eliminarComplementaria(item.id), 'Complementaria eliminada.')}
                  >
                    <Button danger icon={<DeleteOutlined />}>Eliminar</Button>
                  </Popconfirm>
                )}
                {item.estado === 'calculada' && permisos.aprobar && <Button onClick={() => ejecutar(() => api.aprobarComplementaria(item.id), 'Complementaria aprobada.')}>Aprobar</Button>}
                {item.estado === 'aprobada' && permisos.telecredito && <Button icon={<CreditCardOutlined />} onClick={() => exportar(item, 'telecredito-bcp')}>Telecrédito</Button>}
                {item.estado === 'aprobada' && permisos.bbva && <Button icon={<BankOutlined />} onClick={() => exportar(item, 'bbva-netcash')}>Net Cash</Button>}
                {item.estado === 'aprobada' && permisos.pagar && <Popconfirm title="Referencia del pago" description={<Form><Input id={`ref-${item.id}`} placeholder="Operación o lote" /></Form>} onConfirm={() => { const ref = document.getElementById(`ref-${item.id}`)?.value; if (ref) ejecutar(() => api.pagarComplementaria(item.id, ref), 'Complementaria marcada como pagada.'); }}><Button>Marcar pagada</Button></Popconfirm>}
              </div>
            </div>
            <Table
              rowKey="id"
              size="small"
              pagination={{ pageSize: 10, size: 'small', hideOnSinglePage: true }}
              columns={columnasDetalle(item)}
              dataSource={item.detalles}
              expandable={{
                rowExpandable: (detalle) => detalle.conceptos_manuales?.length > 0,
                expandedRowRender: (detalle) => (
                  <div className="space-y-1.5 py-1">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Conceptos agregados a mano</p>
                    {detalle.conceptos_manuales.map((c, i) => (
                      <div key={c.id ?? i} className="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-3 py-1.5 text-xs">
                        <div>
                          <Tag color={c.tipo === 'egreso' ? 'red' : 'green'}>{nombreConcepto(c.codigo)}</Tag>
                          <span className="font-medium">{soles(c.monto)}</span>
                          {c.motivo && <span className="ml-1 text-gray-500">— {c.motivo}</span>}
                        </div>
                        {item.estado === 'calculada' && permisos.calcular && (
                          c.id ? (
                            <Popconfirm title="¿Eliminar este concepto?" onConfirm={() => eliminarConcepto(detalle.id, c.id)}>
                              <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                            </Popconfirm>
                          ) : (
                            <span title="Se agregó antes de que existiera esta función — no se puede eliminar individualmente.">
                              <Button size="small" type="text" danger icon={<DeleteOutlined />} disabled />
                            </span>
                          )
                        )}
                      </div>
                    ))}
                  </div>
                ),
              }}
            />
          </div>
        ))}
        {!items.length && !loading && <div className="py-8 text-center text-gray-400">Aún no hay regularizaciones para este ciclo.</div>}
        </div>
      </div>

      <AgregarConceptoComplementariaModal
        open={!!detalleParaConcepto}
        onCancel={() => setDetalleParaConcepto(null)}
        onSubmit={agregarConcepto}
        loading={agregandoConcepto}
        detalle={detalleParaConcepto}
        catalogo={catalogoConceptos}
      />
    </Modal>
  );
}
