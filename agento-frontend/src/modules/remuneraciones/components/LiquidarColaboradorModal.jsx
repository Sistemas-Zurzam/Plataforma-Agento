import { CalculatorOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, Collapse, DatePicker, Input, InputNumber, Modal, Segmented, Select, Spin, Switch, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import api from '../../../services/api';

const soles = (valor) => new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' }).format(Number(valor ?? 0));
const fecha = (valor) => (valor ? String(valor).slice(0, 10).split('-').reverse().join('/') : '—');
const mensajeError = (error, porDefecto) => {
  const errores = error.response?.data?.errors;
  return errores ? Object.values(errores)[0]?.[0] : (error.response?.data?.message ?? porDefecto);
};

let siguienteClave = 1;
const nuevaClave = () => `linea-${siguienteClave++}`;

const desdePropuesta = (conceptos) => conceptos.map((c) => ({
  clave: nuevaClave(), codigo: c.codigo, nombre: c.nombre, tipo: c.tipo, monto: c.monto,
  montoCalculado: c.monto, formula: c.formula_texto, motivo: '', propuesta: true,
}));

const valoresIniciales = (datosCalculo) => Object.fromEntries(Object.entries(datosCalculo ?? {}).map(([clave, d]) => [clave, d.valor]));

const iguales = (a, b, tipo) => {
  if (a === null || a === undefined || b === null || b === undefined) return a == b; // eslint-disable-line eqeqeq
  if (tipo === 'si_no') return Boolean(a) === Boolean(b);
  if (tipo === 'fecha') return String(a).slice(0, 10) === String(b).slice(0, 10);
  if (tipo === 'opcion') return String(a) === String(b);
  return Math.abs(Number(a) - Number(b)) < 0.00005;
};

// Solo se envían los datos que difieren del valor del sistema.
const datosModificados = (valores, datosCalculo) => Object.fromEntries(Object.entries(datosCalculo ?? {})
  .filter(([clave, d]) => !iguales(valores[clave], d.valor_sistema, d.tipo) && valores[clave] !== null && valores[clave] !== undefined)
  .map(([clave]) => [clave, valores[clave]]));

const ESTADO_DATO = {
  sistema: ['Sistema', 'default'],
  manual: ['Ingresado', 'blue'],
  faltante: ['Falta', 'red'],
  revisar: ['Revisar', 'gold'],
};

const TIPO_LINEA = { ingreso: ['Ingreso', 'green'], egreso: ['Descuento', 'red'], aportacion: ['Aporte empleador', 'blue'] };
const ORIGEN_TRAMO = { historial: 'Historial', ficha: 'Ficha', supuesto: 'Supuesto', decision: 'Decisión' };
const NATURALEZA = { complementaria_variable: 'Complementaria variable', principal_imprecisa: 'Principal (comisionista)' };
const REGIMEN_VALIDACIONES = ['decision_regimen_superpuesto', 'decision_regimen_vacio', 'decision_tramo_locacion', 'decision_clasificacion_vinculo',
  'decision_mype_sin_remype', 'decision_mype_anterior_remype', 'decision_categoria_mype'];
const conSigno = (valor) => `${Number(valor) > 0 ? '+' : ''}${soles(valor)}`;

/**
 * Registra la liquidación de beneficios de un colaborador ya cesado.
 * Con datos del sistema: muestra los datos de entrada del cálculo; si falta
 * alguno (sin historial), RR.HH. lo completa y el sistema recalcula. Las
 * líneas resultantes se pueden ajustar con motivo.
 * Montos directos: último recurso, cuando la liquidación ya se calculó fuera
 * del sistema; se registran los montos con su sustento.
 */
export default function LiquidarColaboradorModal({ open, colaborador, onCancel, onRegistrada }) {
  const { message } = App.useApp();
  const [modo, setModo] = useState('asistido');
  const [propuesta, setPropuesta] = useState(null);
  const [errorPropuesta, setErrorPropuesta] = useState(null);
  const [catalogo, setCatalogo] = useState([]);
  const [lineas, setLineas] = useState([]);
  const [valores, setValores] = useState({});
  const [datosAplicados, setDatosAplicados] = useState({});
  const [sustento, setSustento] = useState('');
  const [codigoNuevo, setCodigoNuevo] = useState(undefined);
  const [cargando, setCargando] = useState(false);
  const [recalculando, setRecalculando] = useState(false);
  const [guardando, setGuardando] = useState(false);

  const aplicarPropuesta = (data, datos) => {
    setPropuesta(data);
    setValores(valoresIniciales(data.datos_calculo));
    setDatosAplicados(datos);
    setLineas(desdePropuesta(data.conceptos));
  };

  useEffect(() => {
    if (!open || !colaborador) return;
    let activo = true;
    setCargando(true);
    setPropuesta(null);
    setErrorPropuesta(null);
    setSustento('');
    setCodigoNuevo(undefined);
    setModo('asistido');
    // Al reabrir un borrador se recalcula con sus datos y decisiones manuales.
    const previos = colaborador.datos_previos ?? {};
    const hayPrevios = Object.keys(previos).length > 0;
    Promise.all([
      api.get('/liquidaciones-cese/catalogo').then(({ data }) => data.data),
      (hayPrevios
        ? api.post(`/liquidaciones-cese/propuesta/${colaborador.id}`, { datos: previos })
        : api.get(`/liquidaciones-cese/propuesta/${colaborador.id}`)).then(({ data }) => data.data).catch((error) => ({ error })),
    ]).then(([catalogoData, propuestaData]) => {
      if (!activo) return;
      setCatalogo(catalogoData);
      if (propuestaData?.error) {
        setErrorPropuesta(mensajeError(propuestaData.error, 'No se pudo calcular la propuesta.'));
        setModo('manual');
        setLineas([]);
      } else {
        aplicarPropuesta(propuestaData, previos);
        if (hayPrevios) setValores({ ...valoresIniciales(propuestaData.datos_calculo), ...previos });
      }
    }).catch((error) => {
      if (activo) message.error(mensajeError(error, 'No se pudo cargar la liquidación'));
    }).finally(() => { if (activo) setCargando(false); });
    return () => { activo = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, colaborador?.id]);

  const datosCalculo = propuesta?.datos_calculo;
  const modificados = useMemo(() => datosModificados(valores, datosCalculo), [valores, datosCalculo]);
  const ordenado = (datos) => JSON.stringify(Object.entries(datos).sort(([a], [b]) => a.localeCompare(b)));
  const pendienteRecalcular = ordenado(modificados) !== ordenado(datosAplicados);
  const hayDatosManuales = Object.keys(datosAplicados).length > 0;

  const recalcular = async () => {
    setRecalculando(true);
    try {
      const { data } = await api.post(`/liquidaciones-cese/propuesta/${colaborador.id}`, { datos: modificados });
      aplicarPropuesta(data.data, modificados);
      // Conserva lo que RR.HH. tipeó aunque coincida con el valor del sistema.
      setValores((actuales) => ({ ...valoresIniciales(data.data.datos_calculo), ...modificados, ...actuales }));
      message.success('Liquidación recalculada con los datos ingresados');
    } catch (error) {
      message.error(mensajeError(error, 'No se pudo recalcular'));
    } finally { setRecalculando(false); }
  };

  const cambiarModo = (valor) => {
    setModo(valor);
    setLineas(valor === 'asistido' && propuesta ? desdePropuesta(propuesta.conceptos) : []);
  };

  const actualizar = (clave, cambios) => setLineas((actuales) => actuales.map((l) => (l.clave === clave ? { ...l, ...cambios } : l)));
  const quitar = (clave) => setLineas((actuales) => actuales.filter((l) => l.clave !== clave));

  const agregar = () => {
    const concepto = catalogo.find((c) => c.codigo === codigoNuevo);
    if (!concepto) return;
    setLineas((actuales) => [...actuales, {
      clave: nuevaClave(), codigo: concepto.codigo, nombre: concepto.nombre, tipo: concepto.tipo, monto: 0,
      montoCalculado: null, formula: null, motivo: '', propuesta: false, nombreLibre: concepto.nombre_libre,
    }]);
    setCodigoNuevo(undefined);
  };

  const modificada = (l) => l.propuesta && Math.abs(Number(l.monto ?? 0) - Number(l.montoCalculado ?? 0)) >= 0.005;
  const exigeMotivo = (l) => modificada(l) || (modo === 'asistido' && !l.propuesta && Number(l.monto) > 0);
  const exigeSustento = modo === 'manual' || (modo === 'asistido' && hayDatosManuales);

  const totales = useMemo(() => {
    const suma = (tipo) => lineas.filter((l) => l.tipo === tipo).reduce((s, l) => s + Number(l.monto || 0), 0);
    const ingresos = suma('ingreso');
    const egresos = suma('egreso');
    return { ingresos, egresos, aportaciones: suma('aportacion'), neto: ingresos - egresos };
  }, [lineas]);

  const validaciones = propuesta?.validaciones ?? [];
  const regimenPorValidar = validaciones.some((v) => REGIMEN_VALIDACIONES.includes(v.clave) && !v.resuelta);

  const guardar = async () => {
    if (modo === 'asistido' && pendienteRecalcular) { message.warning('Cambiaste datos del cálculo: presiona "Recalcular" antes de registrar.'); return; }
    if (modo === 'asistido' && !propuesta?.puede_calcular) { message.warning('Completa los datos faltantes y recalcula.'); return; }
    const faltaMotivo = lineas.find((l) => exigeMotivo(l) && !l.motivo.trim());
    if (faltaMotivo) { message.warning(`Indica el motivo de ${faltaMotivo.nombre}.`); return; }
    if (exigeSustento && sustento.trim().length < 5) { message.warning('Indica el sustento de los datos o montos ingresados a mano.'); return; }
    if (totales.ingresos <= 0) { message.warning('Agrega al menos un ingreso mayor a cero.'); return; }
    if (totales.neto < 0) { message.warning('Los descuentos superan a los ingresos.'); return; }

    setGuardando(true);
    try {
      await api.post('/liquidaciones-cese', {
        colaborador_id: colaborador.id,
        modo,
        sustento: sustento.trim() || null,
        datos: modo === 'asistido' ? datosAplicados : undefined,
        conceptos: lineas.map((l) => ({
          codigo: l.codigo, monto: Number(l.monto || 0),
          nombre: l.nombreLibre ? l.nombre : undefined,
          motivo_ajuste: l.motivo.trim() || undefined,
        })),
      });
      message.success('Liquidación registrada');
      onRegistrada?.();
    } catch (error) {
      message.error(mensajeError(error, 'No se pudo registrar la liquidación'));
    } finally { setGuardando(false); }
  };

  const editorDato = (clave, d) => {
    const valor = valores[clave];
    const cambiar = (v) => setValores((actuales) => ({ ...actuales, [clave]: v }));
    if (d.tipo === 'fecha') {
      return <DatePicker size="small" className="w-full" format="DD/MM/YYYY" allowClear={false} value={valor ? dayjs(String(valor).slice(0, 10)) : null}
        maxDate={dayjs(colaborador?.fecha_cese)} onChange={(v) => cambiar(v ? v.format('YYYY-MM-DD') : null)} />;
    }
    if (d.tipo === 'si_no') return <Switch size="small" checked={Boolean(valor)} onChange={cambiar} checkedChildren="Sí" unCheckedChildren="No" />;
    if (d.tipo === 'opcion') {
      return <Select size="small" className="w-full" allowClear placeholder="Seleccionar" value={valor ?? undefined} options={d.opciones}
        status={d.estado !== 'ok' && (valor === null || valor === undefined) ? 'warning' : undefined} onChange={(v) => cambiar(v ?? null)} />;
    }
    return <InputNumber size="small" className="w-full" min={d.tipo === 'dias' ? undefined : 0} precision={2}
      prefix={d.tipo === 'monto' ? 'S/' : undefined} suffix={d.tipo === 'dias' ? 'días' : d.tipo === 'numero' ? 'meses' : undefined}
      value={valor} status={d.estado === 'faltante' && (valor === null || valor === undefined) ? 'error' : undefined} onChange={cambiar} />;
  };

  const estadoDato = (clave, d) => {
    if (!iguales(valores[clave], d.valor_sistema, d.tipo) && valores[clave] !== null && valores[clave] !== undefined) return 'manual';
    return d.estado === 'ok' ? 'sistema' : d.estado;
  };

  const columnasDatos = [
    { title: 'Dato', render: (_, [, d]) => <div><span>{d.etiqueta}</span>{d.estado !== 'ok' && d.ayuda && <p className="text-[11px] text-gray-400">{d.ayuda}</p>}</div> },
    { title: 'Valor', width: 200, render: (_, [clave, d]) => editorDato(clave, d) },
    { title: 'Origen', width: 100, render: (_, [clave, d]) => { const [texto, color] = ESTADO_DATO[estadoDato(clave, d)]; return <Tag color={color}>{texto}</Tag>; } },
  ];

  const columnas = [
    {
      title: 'Concepto',
      render: (_, l) => <div>
        {l.nombreLibre
          ? <Input size="small" value={l.nombre} maxLength={160} onChange={(e) => actualizar(l.clave, { nombre: e.target.value })} />
          : <span>{l.nombre}</span>}
        {l.formula && <p className="text-[11px] text-gray-400">{l.formula}</p>}
      </div>,
    },
    { title: 'Tipo', width: 110, render: (_, l) => <Tag color={TIPO_LINEA[l.tipo]?.[1]}>{TIPO_LINEA[l.tipo]?.[0] ?? l.tipo}</Tag> },
    ...(modo === 'asistido' ? [{ title: 'Calculado', width: 110, align: 'right', render: (_, l) => (l.propuesta ? soles(l.montoCalculado) : <span className="text-gray-400">—</span>) }] : []),
    {
      title: 'Monto', width: 140,
      render: (_, l) => <InputNumber size="small" className="w-full" min={0} precision={2} value={l.monto} onChange={(v) => actualizar(l.clave, { monto: v ?? 0 })} status={modificada(l) ? 'warning' : undefined} />,
    },
    {
      title: modo === 'asistido' ? 'Motivo del ajuste' : 'Detalle (opcional)',
      render: (_, l) => (exigeMotivo(l) || modo === 'manual' || l.motivo
        ? <Input size="small" value={l.motivo} maxLength={1000} placeholder={exigeMotivo(l) ? 'Obligatorio' : ''} status={exigeMotivo(l) && !l.motivo.trim() ? 'error' : undefined} onChange={(e) => actualizar(l.clave, { motivo: e.target.value })} />
        : <span className="text-xs text-gray-400">Sin cambios</span>),
    },
    { title: '', width: 40, render: (_, l) => (!l.propuesta ? <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => quitar(l.clave)} aria-label="Quitar concepto" /> : null) },
  ];

  return (
    <Modal
      title={`Liquidar a ${colaborador?.nombre ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={guardar}
      okText="Registrar liquidación"
      okButtonProps={{ disabled: cargando || lineas.length === 0 }}
      confirmLoading={guardando}
      width={{ xs: '96%', md: 1000 }}
      destroyOnHidden
    >
      {cargando ? <div className="py-10 text-center"><Spin /></div> : (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
            <span>Ingreso {fecha(colaborador?.fecha_ingreso)} · Cese {fecha(colaborador?.fecha_cese)} · {colaborador?.motivo_cese}</span>
            <Segmented value={modo} onChange={cambiarModo} options={[
              { value: 'asistido', label: 'Calcular con datos del sistema', disabled: !propuesta },
              { value: 'manual', label: 'Ingresar montos directamente' },
            ]} />
          </div>

          {errorPropuesta && <Alert type="warning" showIcon message="No se pudo generar el cálculo" description={`${errorPropuesta} Puedes ingresar los montos directamente.`} />}

          {modo === 'asistido' && propuesta && <>
            <div className="rounded-lg border border-gray-200 p-3">
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="font-medium">Datos para el cálculo</p>
                  <p className="text-xs text-gray-500">Vienen del sistema. Si falta alguno o no es correcto (por ejemplo, historial anterior al sistema), corrígelo y presiona "Recalcular".</p>
                </div>
                <Button type={pendienteRecalcular ? 'primary' : 'default'} icon={<CalculatorOutlined />} loading={recalculando} disabled={!pendienteRecalcular} onClick={recalcular}>Recalcular</Button>
              </div>
              <Table size="small" pagination={false} rowKey={([clave]) => clave} dataSource={Object.entries(datosCalculo ?? {}).filter(([, d]) => !d.es_decision)} columns={columnasDatos} />
            </div>

            <div className="rounded-lg border border-gray-200 p-3">
              <div className="mb-2 flex flex-wrap items-center gap-2">
                <p className="font-medium">Régimen laboral por período</p>
                {regimenPorValidar && <Tag color="orange">Régimen laboral por validar</Tag>}
              </div>
              <Table size="small" pagination={false} rowKey={(t) => `${t.desde}-${t.regimen}`} dataSource={propuesta.regimen_tramos ?? []} columns={[
                { title: 'Período', render: (_, t) => `${fecha(t.desde)} – ${fecha(t.hasta)}` },
                { title: 'Régimen', dataIndex: 'regimen' },
                { title: 'Vacaciones', width: 110, render: (_, t) => `${t.vacaciones_dias} d/año` },
                { title: 'Origen', width: 100, render: (_, t) => <Tag>{ORIGEN_TRAMO[t.origen] ?? t.origen}</Tag> },
              ]} />
            </div>

            {validaciones.length > 0 && (
              <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                <div>
                  <p className="font-medium">Decisiones laborales</p>
                  <p className="text-xs text-gray-600">La norma no resuelve estos puntos para este caso. Elige una opción, recalcula e indica el sustento. Con alguna pendiente, la liquidación se guarda como borrador y no se puede aprobar.</p>
                </div>
                {validaciones.map((v) => (
                  <div key={v.clave} className="rounded-md border border-amber-100 bg-white p-2 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                      <strong>{v.titulo}</strong>
                      <Tag color={v.resuelta ? 'green' : 'orange'}>{v.resuelta ? 'Resuelta' : 'Pendiente'}</Tag>
                    </div>
                    <p className="mt-1">{v.pregunta}</p>
                    <p className="text-[11px] text-gray-500">Conceptos: {v.conceptos?.join(', ')} · Fuente: {v.fuente}</p>
                    {v.diferencias?.length > 0 && (
                      <ul className="mt-1 text-[11px] text-gray-600">
                        {v.diferencias.map((d) => <li key={d.opcion}>{d.etiqueta}: neto {soles(d.neto_pagar)} ({conSigno(d.diferencia_neto)} frente al cálculo mostrado)</li>)}
                      </ul>
                    )}
                    <Select size="small" className="mt-2 w-full md:w-2/3" allowClear placeholder="Elegir decisión" options={v.opciones}
                      value={valores[v.clave] ?? undefined} onChange={(valor) => setValores((actuales) => ({ ...actuales, [v.clave]: valor ?? null }))} />
                  </div>
                ))}
              </div>
            )}

            {propuesta.bases_variables?.some((b) => b.componentes?.length > 0) && (
              <Collapse size="small" items={[{
                key: 'variables', label: 'Remuneraciones variables incluidas en las bases',
                children: <div className="space-y-3">
                  {propuesta.bases_variables.filter((b) => b.componentes?.length > 0).map((b) => (
                    <div key={b.beneficio}>
                      <p className="text-sm font-medium">{b.beneficio}: {soles(b.total)} <span className="text-xs font-normal text-gray-500">({fecha(b.desde)} – {fecha(b.hasta)})</span></p>
                      <p className="text-[11px] text-gray-500">{b.regla}</p>
                      <Table size="small" pagination={false} rowKey="codigo" dataSource={b.componentes} columns={[
                        { title: 'Concepto', dataIndex: 'nombre' },
                        { title: 'Naturaleza', render: (_, c) => NATURALEZA[c.naturaleza] ?? c.naturaleza },
                        { title: 'Meses con pago', align: 'right', dataIndex: 'meses_con_pago' },
                        { title: 'Suma', align: 'right', render: (_, c) => soles(c.suma) },
                        { title: 'Divisor', align: 'right', dataIndex: 'divisor' },
                        { title: 'Incluido', align: 'right', render: (_, c) => (c.regular ? soles(c.monto) : <span className="text-gray-400">No regular</span>) },
                      ]} />
                    </div>
                  ))}
                </div>,
              }]} />
            )}

            {!propuesta.puede_calcular
              ? <Alert type="error" showIcon message="Faltan datos para calcular" description="Completa los datos marcados como Falta y presiona Recalcular." />
              : <>
                <p className="text-xs text-gray-500">
                  Remuneración computable: {soles(propuesta.remuneracion_computable)}. Puedes ajustar cualquier monto indicando el motivo. Si ajustas vacaciones, revisa también el AFP/ONP calculado sobre ellas.
                </p>
                {propuesta.requiere_validacion && <Alert type="warning" showIcon message="Hay decisiones laborales pendientes: puedes registrar la liquidación como borrador, pero no se podrá aprobar hasta resolverlas." />}
                {propuesta.alertas?.map((alerta) => <Alert key={alerta} type="info" showIcon message={alerta} />)}
              </>}
          </>}

          {modo === 'manual' && <Alert type="info" showIcon message="Usa esta opción solo si la liquidación ya se calculó fuera del sistema: registra los montos y su sustento para poder aprobarla y pagarla." />}

          {(modo === 'manual' || propuesta?.puede_calcular) && <>
            <Table size="small" rowKey="clave" pagination={false} dataSource={lineas} columns={columnas} scroll={{ x: 760 }} locale={{ emptyText: 'Agrega los conceptos de la liquidación' }} />

            <div className="flex flex-wrap items-center gap-2">
              <Select className="w-72" showSearch optionFilterProp="label" placeholder="Agregar concepto" value={codigoNuevo} onChange={setCodigoNuevo}
                options={catalogo.map((c) => ({ value: c.codigo, label: `${c.nombre} (${c.tipo === 'ingreso' ? 'ingreso' : 'descuento'})` }))} />
              <Button icon={<PlusOutlined />} disabled={!codigoNuevo} onClick={agregar}>Agregar</Button>
            </div>

            <div className="space-y-1 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
              <div className="flex justify-between"><span>Total ingresos</span><strong className="text-green-600">{soles(totales.ingresos)}</strong></div>
              <div className="flex justify-between"><span>Total descuentos</span><strong className="text-red-500">{soles(totales.egresos)}</strong></div>
              <div className="flex justify-between text-base"><span className="font-semibold">Neto a pagar</span><strong>{soles(totales.neto)}</strong></div>
              {totales.aportaciones > 0 && <div className="flex justify-between text-xs text-gray-500"><span>Aporte del empleador (no se descuenta al trabajador)</span><span>{soles(totales.aportaciones)}</span></div>}
            </div>

            <div>
              <p className="mb-1 text-sm">{exigeSustento ? 'Sustento de los datos ingresados (obligatorio)' : 'Observaciones (opcional)'}</p>
              <Input.TextArea rows={2} maxLength={2000} value={sustento} onChange={(e) => setSustento(e.target.value)}
                placeholder={exigeSustento ? 'Ej.: sueldo y fecha de ingreso según contrato físico, vacaciones gozadas según planilla anterior…' : ''} />
            </div>
          </>}
        </div>
      )}
    </Modal>
  );
}
