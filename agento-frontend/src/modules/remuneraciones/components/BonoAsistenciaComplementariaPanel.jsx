import { CheckCircleOutlined } from '@ant-design/icons';
import { App, Button, Checkbox, Input, InputNumber, Segmented, Select, Table, Tag } from 'antd';
import ReporteBonoAsistenciaPanel from './ReporteBonoAsistenciaPanel';
import { useEffect, useMemo, useState } from 'react';
import { useConceptoDefinicionesPlame } from '../../configuracion/hooks/useConceptoDefinicionesPlame';
import { CONCEPTOS_CON_DEFINICION, CONCEPTOS_REGISTRABLES } from './AgregarConceptoComplementariaModal';

/**
 * Bono por días de asistencia — a diferencia de HorasExtraComplementariaPanel
 * (que agrega líneas a un borrador YA elegido por el usuario), este panel
 * SIEMPRE crea su propia PlanillaComplementaria nueva en un solo POST, así
 * que no hay selector de "borrador de destino": filtra colaboradores por
 * asistencia, se eligen y se aplica el concepto a todos de una vez.
 * Reutiliza el mismo catálogo "registrable" y la misma lógica de
 * clasificación PLAME que AgregarConceptoComplementariaModal.
 */
export default function BonoAsistenciaComplementariaPanel({ ciclo, api, onUpdated, catalogo = [] }) {
  const { message } = App.useApp();
  const { definiciones, loading: definicionesLoading, fetchDefiniciones } = useConceptoDefinicionesPlame();

  const [vista, setVista] = useState('reporte');
  const [aprobacionConfirmada, setAprobacionConfirmada] = useState(false);
  const [dias, setDias] = useState(26);
  const [operador, setOperador] = useState('exacto');
  const [codigo, setCodigo] = useState(undefined);
  const [conceptoDefinicionId, setConceptoDefinicionId] = useState(undefined);
  const [monto, setMonto] = useState(150);
  const [motivo, setMotivo] = useState('');

  const [colaboradores, setColaboradores] = useState([]);
  const [seleccion, setSeleccion] = useState([]);
  const [buscado, setBuscado] = useState(false);
  const [buscando, setBuscando] = useState(false);
  const [aplicando, setAplicando] = useState(false);
  const [catalogoLocal, setCatalogoLocal] = useState(catalogo);
  const [cargandoCatalogo, setCargandoCatalogo] = useState(false);

  useEffect(() => { setCatalogoLocal(catalogo); }, [catalogo]);
  useEffect(() => {
    if (catalogo.length || typeof api.fetchCatalogoConceptos !== 'function') return;
    setCargandoCatalogo(true);
    api.fetchCatalogoConceptos()
      .then(setCatalogoLocal)
      .catch((e) => message.error(e.response?.data?.message ?? 'No se pudo cargar el catálogo de conceptos para el bono.'))
      .finally(() => setCargandoCatalogo(false));
  }, [ciclo?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Mismo catálogo que AgregarConceptoComplementariaModal, restringido a
  // ingresos: un bono siempre suma, nunca descuenta.
  const opcionesDisponibles = useMemo(() => CONCEPTOS_REGISTRABLES.filter(
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

  const buscar = async () => {
    if (!ciclo) return;
    if (!dias || dias < 1) return message.warning('Ingresa la cantidad de días asistidos.');
    if (!codigo) return message.warning('Selecciona el concepto del bono.');
    const concepto = catalogoLocal.find((c) => c.codigo === codigo);
    if (!concepto) return;
    setBuscando(true);
    try {
      const resultado = await api.fetchColaboradoresPorAsistencia(ciclo.id, dias, operador, concepto.id);
      setColaboradores(resultado.colaboradores);
      setSeleccion([]);
      setAprobacionConfirmada(false);
      setBuscado(true);
    } catch (e) {
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudieron cargar los colaboradores por asistencia.');
    } finally { setBuscando(false); }
  };

  const aplicar = async () => {
    if (!seleccion.length || !aprobacionConfirmada) return;
    if (requiereDefinicionPlame && !conceptoDefinicionId) return message.warning('Selecciona la clasificación PLAME del bono.');
    if (!monto || monto <= 0) return message.warning('Ingresa un monto mayor a cero.');
    if (!motivo.trim()) return message.warning('Ingresa el motivo del bono.');
    const concepto = catalogoLocal.find((c) => c.codigo === codigo);
    setAplicando(true);
    try {
      await api.aplicarBonoPorAsistencia(ciclo.id, {
        boleta_ids: seleccion,
        dias,
        operador,
        concepto_id: concepto.id,
        concepto_definicion_id: conceptoDefinicionId ?? null,
        monto,
        motivo: motivo.trim(),
      });
      message.success('Bono aplicado. Se generó una nueva planilla complementaria.');
      setColaboradores([]);
      setSeleccion([]);
      setBuscado(false);
      setMotivo('');
      await onUpdated();
    } catch (e) {
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudo aplicar el bono.');
    } finally { setAplicando(false); }
  };

  return <div className="space-y-3">
    <Segmented value={vista} onChange={setVista} options={[{ value: 'reporte', label: 'Evaluación para Gerencia' }, { value: 'aplicar', label: 'Registrar bonos aprobados' }]} />
    {vista === 'reporte' ? <ReporteBonoAsistenciaPanel ciclo={ciclo} api={api} onUpdated={onUpdated} catalogo={catalogoLocal} /> : <>
    <p>Registra únicamente los colaboradores y montos aprobados por Gerencia. Para porcentajes distintos, registra cada grupo con su importe autorizado.</p>
    <div className="flex flex-wrap items-end gap-3">
      <div>
        <div className="mb-1 text-xs font-medium text-gray-600">Días asistidos</div>
        <InputNumber min={1} max={31} value={dias} onChange={setDias} />
      </div>
      <div>
        <div className="mb-1 text-xs font-medium text-gray-600">Comparación</div>
        <Segmented value={operador} onChange={setOperador} options={[{ label: 'Exactamente', value: 'exacto' }, { label: 'Como mínimo', value: 'minimo' }]} />
      </div>
      <div className="min-w-[220px]">
        <div className="mb-1 text-xs font-medium text-gray-600">Concepto</div>
        <Select className="w-full" placeholder="Selecciona un concepto" value={codigo} onChange={handleCambioConcepto}
          loading={cargandoCatalogo} disabled={cargandoCatalogo}
          notFoundContent={cargandoCatalogo ? 'Cargando conceptos...' : 'No hay conceptos de bono activos'}
          options={opcionesDisponibles.map((c) => ({ value: c.codigo, label: c.nombre }))} />
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
      <div>
        <div className="mb-1 text-xs font-medium text-gray-600">Monto (S/)</div>
        <InputNumber min={0.01} step={0.01} precision={2} value={monto} onChange={(v) => { setMonto(v); setAprobacionConfirmada(false); }} />
      </div>
    </div>
    <Input.TextArea value={motivo} onChange={(e) => setMotivo(e.target.value)} autoSize={{ minRows: 1, maxRows: 3 }}
      placeholder="Motivo: bono por asistencia perfecta de agosto..." />
    <Button onClick={buscar} loading={buscando} disabled={!dias || !codigo}>Buscar colaboradores elegibles</Button>

    <Table
      rowKey="boleta_id"
      size="small"
      loading={buscando}
      dataSource={colaboradores}
      scroll={{ x: 700 }}
      rowSelection={{ selectedRowKeys: seleccion, onChange: (ids) => { setSeleccion(ids); setAprobacionConfirmada(false); }, preserveSelectedRowKeys: true, getCheckboxProps: (c) => ({ disabled: !c.disponible }) }}
      pagination={{ defaultPageSize: 10, showSizeChanger: true }}
      locale={{ emptyText: buscado ? 'Ningún colaborador cumple el filtro de asistencia.' : 'Busca para ver los colaboradores que cumplen el filtro.' }}
      columns={[
        { title: 'Colaborador', dataIndex: 'colaborador' },
        { title: 'Documento', dataIndex: 'documento', width: 120 },
        { title: 'Días asistidos', dataIndex: 'dias_asistidos', width: 120, align: 'center' },
        { title: 'Estado', width: 240, render: (_, c) => c.disponible
          ? <Tag color="green" icon={<CheckCircleOutlined />}>Disponible</Tag>
          : <Tag>{c.motivo ?? 'No disponible'}</Tag> },
      ]}
    />

    <Checkbox checked={aprobacionConfirmada} onChange={(e) => setAprobacionConfirmada(e.target.checked)}>Confirmo que Gerencia aprobó los colaboradores seleccionados y el monto indicado.</Checkbox>
    <Button type="primary" loading={aplicando} disabled={!seleccion.length || !aprobacionConfirmada} onClick={aplicar}>
      Aplicar bono a {seleccion.length} colaborador{seleccion.length === 1 ? '' : 'es'}
    </Button>
    <p className="text-xs text-gray-500">Se crea una nueva planilla complementaria solo con los colaboradores seleccionados; la boleta original ya pagada no se modifica.</p>
    </>}
  </div>;
}
