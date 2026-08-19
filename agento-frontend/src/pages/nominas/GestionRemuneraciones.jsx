import {
  BankOutlined,
  BarChartOutlined,
  CalendarOutlined,
  CheckCircleOutlined,
  DollarCircleOutlined,
  DownloadOutlined,
  FileDoneOutlined,
  FilePdfOutlined,
  FileProtectOutlined,
  FileTextOutlined,
  LockOutlined,
  PlusOutlined,
  ReloadOutlined,
  SendOutlined,
  SettingOutlined,
  TeamOutlined,
  UnlockOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { App, Avatar, Button, Empty, Input, Select, Table, Tabs, Tag, Tooltip } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import EmpresaActivaFiltro from '../../modules/configuracion/components/EmpresaActivaFiltro';
import BoletaImprimibleModal from '../../modules/remuneraciones/components/BoletaImprimibleModal';
import ConfiguracionNominaModal from '../../modules/remuneraciones/components/ConfiguracionNominaModal';
import CtsGratificacionesTab from '../../modules/remuneraciones/components/CtsGratificacionesTab';
import NuevoCicloModal from '../../modules/remuneraciones/components/NuevoCicloModal';
import RegistrarConceptoModal from '../../modules/remuneraciones/components/RegistrarConceptoModal';
import { useRemuneraciones } from '../../modules/remuneraciones/hooks/useRemuneraciones';
import { colorForName, initialsForName } from '../../utils/avatarColor';

const ESTADO_COLOR = {
  calculada: 'blue',
  observada: 'orange',
  aprobada: 'geekblue',
  pagada: 'green',
  anulada: 'red',
};

const CICLO_ESTADO_COLOR = {
  abierto: 'blue',
  calculado: 'gold',
  cerrado: 'default',
  reabierto: 'orange',
  pagado: 'green',
};

/** Botones que el mockup de referencia muestra pero que no tienen todavía
 * una implementación real detrás (archivo bancario, formatos oficiales
 * SUNAT/AFP, generación de contratos, etc.) — se muestran deshabilitados
 * con un tooltip, igual que el resto de módulos "próximamente" del
 * Sidebar, en vez de fingir una funcionalidad que no existe. */
const ACCIONES_PROXIMAMENTE = [
  { key: 'proyeccion', label: 'Proyección Semanal', icon: <CalendarOutlined /> },
  { key: 'pagos', label: 'Pagos Masivos', icon: <SendOutlined /> },
  { key: 'liquidaciones', label: 'Liquidaciones', icon: <FileDoneOutlined /> },
  { key: 'contratos', label: 'Contratos', icon: <FileTextOutlined /> },
  { key: 'plame', label: 'PDT PLAME', icon: <FilePdfOutlined /> },
  { key: 'afpnet', label: 'AFP NET', icon: <FileProtectOutlined /> },
  { key: 'contable', label: 'Resumen Contable', icon: <BarChartOutlined /> },
];

function regimenLabel(regimen) {
  if (regimen === 'Locacion de Servicios') return 'Honorarios';
  if (regimen === 'Micro Empresa') return 'Micro';
  if (regimen === 'Pequeña Empresa') return 'Pequeña';
  return regimen ?? '—';
}

function TarjetaStat({ icono, valor, etiqueta, color }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-lg ${color}`}>
        {icono}
      </span>
      <div className="min-w-0">
        <p className="truncate text-lg font-semibold text-gray-900">{valor}</p>
        <p className="truncate text-xs text-gray-500">{etiqueta}</p>
      </div>
    </div>
  );
}

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function BloqueConceptos({ titulo, lineas, tono }) {
  if (!lineas?.length) {
    return null;
  }

  return (
    <div className="rounded-lg border border-gray-100 bg-white p-2.5">
      <p className={`mb-1.5 text-[11px] font-semibold uppercase tracking-wide ${tono}`}>{titulo}</p>
      <div className="divide-y divide-gray-50">
        {lineas.map((linea) => (
          <div key={linea.id} className="flex items-baseline justify-between gap-2 py-1 first:pt-0 last:pb-0">
            <div className="min-w-0">
              <span className="text-xs font-medium text-gray-800">{linea.nombre}</span>
              {linea.formula_texto && (
                <span className="block truncate text-[10px] leading-tight text-gray-400" title={linea.formula_texto}>
                  {linea.formula_texto}
                </span>
              )}
            </div>
            <span className="shrink-0 text-xs font-semibold text-gray-900">{soles(linea.monto)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function DetalleBoleta({ boletaId, verBoleta }) {
  const [detalle, setDetalle] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let activo = true;
    setLoading(true);
    verBoleta(boletaId).then((data) => {
      if (activo) setDetalle(data);
    }).finally(() => activo && setLoading(false));
    return () => { activo = false; };
  }, [boletaId, verBoleta]);

  if (loading) {
    return <p className="p-4 text-sm text-gray-400">Cargando detalle...</p>;
  }

  if (!detalle) {
    return <p className="p-4 text-sm text-gray-400">No se pudo cargar el detalle de esta boleta.</p>;
  }

  const conceptos = detalle.conceptos ?? [];
  const ingresos = conceptos.filter((c) => c.tipo === 'ingreso');
  const egresos = conceptos.filter((c) => c.tipo === 'egreso');
  const aportaciones = conceptos.filter((c) => c.tipo === 'aportacion');

  return (
    <div className="space-y-2 bg-gray-50 p-2.5">
      {detalle.alertas?.length > 0 && (
        <div className="rounded-lg border border-orange-200 bg-orange-50 px-2.5 py-1.5 text-[11px] text-orange-700">
          {detalle.alertas.map((alerta, i) => <p key={i}>⚠ {alerta}</p>)}
        </div>
      )}
      <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">
        <BloqueConceptos titulo="Ingresos" lineas={ingresos} tono="text-green-600" />
        <BloqueConceptos titulo="Egresos / descuentos" lineas={egresos} tono="text-red-600" />
        <BloqueConceptos titulo="Aportaciones del empleador" lineas={aportaciones} tono="text-blue-600" />
      </div>
      <div className="flex flex-wrap gap-x-4 gap-y-0.5 rounded-lg border border-gray-100 bg-white px-2.5 py-1.5 text-[10px] text-gray-400">
        <span>Versión {detalle.version}</span>
        <span>Régimen: {detalle.regimen_laboral}</span>
        <span>Parámetros: {detalle.snapshot_parametros_version}</span>
        <span>Reglas: {detalle.snapshot_reglas_version}</span>
        {detalle.motivo_recalculo && <span>Motivo recálculo: {detalle.motivo_recalculo}</span>}
      </div>
    </div>
  );
}

export default function GestionRemuneraciones({ user, onUserRefresh }) {
  const { message, modal } = App.useApp();
  const {
    ciclos, ciclosLoading, fetchCiclos, crearCiclo, calcularPlanilla, cerrarCiclo, reabrirCiclo,
    boletas, boletasLoading, pagination, fetchBoletas,
    resumen, fetchResumen,
    verBoleta, aprobarBoleta, pagarBoleta,
    afps, fetchAfps,
    catalogoConceptos, fetchCatalogoConceptos,
    resumenBeneficio, resumenBeneficioLoading, fetchResumenBeneficio, calcularBeneficio, pagarBeneficio,
    actualizarConfiguracionNomina,
    fetchConceptosPeriodo, registrarConceptoPeriodo,
  } = useRemuneraciones();

  const [cicloId, setCicloId] = useState(null);
  const [tabActiva, setTabActiva] = useState('ciclos');
  const [nuevoCicloOpen, setNuevoCicloOpen] = useState(false);
  const [creandoCiclo, setCreandoCiclo] = useState(false);
  const [calculando, setCalculando] = useState(false);

  const [configuracionColaborador, setConfiguracionColaborador] = useState(null);
  const [guardandoConfiguracion, setGuardandoConfiguracion] = useState(false);

  const [conceptoColaborador, setConceptoColaborador] = useState(null);
  const [conceptosPeriodo, setConceptosPeriodo] = useState([]);
  const [conceptosPeriodoLoading, setConceptosPeriodoLoading] = useState(false);
  const [registrandoConcepto, setRegistrandoConcepto] = useState(false);

  const [tipoFiltro, setTipoFiltro] = useState(null);
  const [boletaImprimirId, setBoletaImprimirId] = useState(null);

  const puedeVer = user?.permisos?.includes('nominas.ver');
  const puedeGestionarCiclos = user?.permisos?.includes('nominas.gestionar_ciclos');
  const puedeCalcular = user?.permisos?.includes('nominas.calcular');
  const puedeCerrarPeriodo = user?.permisos?.includes('nominas.cerrar_periodo');
  const puedeAprobar = user?.permisos?.includes('nominas.aprobar');
  const puedePagar = user?.permisos?.includes('nominas.pagar');

  useEffect(() => {
    if (!puedeVer) return;
    fetchCiclos().then((data) => {
      if (data.length > 0) {
        setCicloId((actual) => actual ?? data[0].id);
      }
    });
    fetchAfps();
    fetchCatalogoConceptos();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [puedeVer, user?.empresa?.id]);

  useEffect(() => {
    if (!cicloId) return;
    fetchBoletas(cicloId, 1, pagination.pageSize, tipoFiltro);
    fetchResumen(cicloId, tipoFiltro);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cicloId, tipoFiltro]);

  const cicloActivo = ciclos.find((c) => c.id === cicloId);

  const recargar = () => {
    if (cicloId) {
      fetchBoletas(cicloId, pagination.current, pagination.pageSize, tipoFiltro);
      fetchResumen(cicloId, tipoFiltro);
    }
  };

  const handleCrearCiclo = async (values) => {
    setCreandoCiclo(true);
    try {
      const ciclo = await crearCiclo(values);
      message.success('Ciclo remunerativo creado correctamente');
      setNuevoCicloOpen(false);
      await fetchCiclos();
      setCicloId(ciclo.id);
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo crear el ciclo');
    } finally {
      setCreandoCiclo(false);
    }
  };

  const handleCalcular = (ciclo, motivoRecalculo) => {
    modal.confirm({
      title: motivoRecalculo ? 'Recalcular planilla' : 'Calcular planilla',
      content: 'Se calculará (o recalculará) la boleta de cada colaborador elegible de este ciclo. Las versiones anteriores se conservan en el historial.',
      okText: 'Confirmar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setCalculando(true);
        try {
          const resultado = await calcularPlanilla(ciclo.id, motivoRecalculo);
          message.success(`${resultado.procesadas} boletas calculadas. ${resultado.omitidas.length} omitidas.`);
          if (resultado.omitidas.length > 0) {
            modal.warning({
              title: 'Colaboradores omitidos',
              content: (
                <ul className="list-disc pl-4 text-xs">
                  {resultado.omitidas.map((o) => <li key={o.colaborador_id}>Colaborador #{o.colaborador_id}: {o.motivo}</li>)}
                </ul>
              ),
            });
          }
          await fetchCiclos();
          if (ciclo.id === cicloId) {
            recargar();
          } else {
            setCicloId(ciclo.id);
            setTabActiva('planilla');
          }
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo calcular la planilla');
        } finally {
          setCalculando(false);
        }
      },
    });
  };

  const handleCerrar = (ciclo) => {
    modal.confirm({
      title: 'Cerrar período',
      content: 'No se podrá recalcular la planilla mientras el período esté cerrado. Requiere que todas las boletas estén aprobadas o pagadas.',
      okText: 'Cerrar período',
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await cerrarCiclo(ciclo.id);
          message.success('Período cerrado correctamente');
          fetchCiclos();
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo cerrar el período');
        }
      },
    });
  };

  const handleReabrir = (ciclo) => {
    modal.confirm({
      title: 'Reabrir período',
      content: 'Esto permitirá volver a recalcular la planilla de este ciclo.',
      okText: 'Reabrir',
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await reabrirCiclo(ciclo.id);
          message.success('Período reabierto');
          fetchCiclos();
        } catch (err) {
          message.error('No se pudo reabrir el período');
        }
      },
    });
  };

  const handleAprobar = async (boleta) => {
    try {
      await aprobarBoleta(boleta.id);
      message.success('Boleta aprobada');
      recargar();
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo aprobar la boleta');
    }
  };

  const handlePagar = (boleta) => {
    let referencia = '';
    modal.confirm({
      title: 'Marcar boleta como pagada',
      content: (
        <div className="mt-2">
          <p className="mb-2 text-xs text-gray-500">Ingresa una referencia de pago real (N.º de operación, lote bancario, constancia).</p>
          <Input placeholder="Ej. OP-2026-0819-004" onChange={(e) => { referencia = e.target.value; }} />
        </div>
      ),
      okText: 'Marcar como pagada',
      cancelText: 'Cancelar',
      onOk: async () => {
        if (!referencia.trim()) {
          message.error('La referencia de pago es obligatoria');
          return Promise.reject();
        }
        try {
          await pagarBoleta(boleta.id, referencia.trim());
          message.success('Boleta marcada como pagada');
          recargar();
        } catch (err) {
          message.error('No se pudo marcar la boleta como pagada');
        }
      },
    });
  };

  const abrirConfiguracion = (boleta) => setConfiguracionColaborador(boleta.colaborador);

  const handleGuardarConfiguracion = async (colaboradorId, values) => {
    setGuardandoConfiguracion(true);
    try {
      await actualizarConfiguracionNomina(colaboradorId, values);
      message.success('Configuración de planilla actualizada');
      setConfiguracionColaborador(null);
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo guardar la configuración');
    } finally {
      setGuardandoConfiguracion(false);
    }
  };

  const abrirConceptos = async (boleta) => {
    setConceptoColaborador(boleta.colaborador);
    setConceptosPeriodoLoading(true);
    try {
      const data = await fetchConceptosPeriodo(cicloId, boleta.colaborador.id);
      setConceptosPeriodo(data);
    } finally {
      setConceptosPeriodoLoading(false);
    }
  };

  const handleRegistrarConcepto = async (colaboradorId, values) => {
    setRegistrandoConcepto(true);
    try {
      await registrarConceptoPeriodo(cicloId, colaboradorId, values);
      message.success('Concepto registrado. Se incluirá en el próximo cálculo de la planilla.');
      const data = await fetchConceptosPeriodo(cicloId, colaboradorId);
      setConceptosPeriodo(data);
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo registrar el concepto');
    } finally {
      setRegistrandoConcepto(false);
    }
  };

  const columnasCiclos = useMemo(() => [
    { title: 'Nombre', dataIndex: 'nombre', width: 200 },
    {
      title: 'Período',
      key: 'periodo',
      width: 200,
      render: (_, c) => `${c.fecha_inicio} — ${c.fecha_fin}`,
    },
    { title: 'Fecha de pago', dataIndex: 'fecha_pago', width: 130 },
    {
      title: 'Estado',
      dataIndex: 'estado',
      width: 110,
      render: (estado) => <Tag color={CICLO_ESTADO_COLOR[estado] ?? 'default'}>{estado}</Tag>,
    },
    {
      title: 'Boletas',
      dataIndex: 'boletas_count',
      width: 90,
      render: (v) => v ?? 0,
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 260,
      render: (_, ciclo) => (
        <div className="flex flex-wrap items-center gap-1">
          <Button size="small" onClick={() => { setCicloId(ciclo.id); setTabActiva('planilla'); }}>Ver planilla</Button>
          {puedeCalcular && ['abierto', 'calculado', 'reabierto'].includes(ciclo.estado) && (
            <Tooltip title={ciclo.estado !== 'abierto' ? 'Recalcular planilla' : 'Calcular planilla'}>
              <Button size="small" icon={<ReloadOutlined />} onClick={() => handleCalcular(ciclo, ciclo.estado !== 'abierto' ? 'Recálculo de planilla' : undefined)} />
            </Tooltip>
          )}
          {puedeCerrarPeriodo && ['abierto', 'calculado', 'reabierto'].includes(ciclo.estado) && (
            <Tooltip title="Cerrar período">
              <Button size="small" icon={<LockOutlined />} onClick={() => handleCerrar(ciclo)} />
            </Tooltip>
          )}
          {puedeCerrarPeriodo && ciclo.estado === 'cerrado' && (
            <Tooltip title="Reabrir período">
              <Button size="small" icon={<UnlockOutlined />} onClick={() => handleReabrir(ciclo)} />
            </Tooltip>
          )}
        </div>
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [puedeCalcular, puedeCerrarPeriodo, cicloId]);

  const columnas = useMemo(() => [
    {
      title: 'Colaborador',
      key: 'colaborador',
      width: 240,
      render: (_, boleta) => (
        <div className="flex items-center gap-3">
          <Avatar style={{ backgroundColor: colorForName(boleta.colaborador?.nombre_completo) }}>
            {initialsForName(boleta.colaborador?.nombre_completo)}
          </Avatar>
          <div className="min-w-0">
            <p className="truncate font-semibold text-gray-900">{boleta.colaborador?.nombre_completo}</p>
            <p className="truncate text-xs text-gray-400">{boleta.colaborador?.legajo}</p>
          </div>
        </div>
      ),
    },
    { title: 'Empresa', key: 'empresa', width: 160, render: (_, b) => <span className="font-semibold text-gray-700">{b.colaborador?.empresa}</span> },
    {
      title: 'Tipo',
      key: 'tipo',
      width: 110,
      render: (_, b) => (
        <Tag color={b.regimen_laboral === 'Locacion de Servicios' ? 'purple' : 'blue'}>
          {regimenLabel(b.regimen_laboral)}
        </Tag>
      ),
    },
    { title: 'Sueldo base', dataIndex: 'sueldo_basico', width: 120, render: soles },
    { title: 'Total ingresos', dataIndex: 'total_ingresos', width: 130, render: (v) => <span className="text-green-600">{soles(v)}</span> },
    { title: 'Descuentos', dataIndex: 'total_egresos', width: 120, render: (v) => <span className="text-red-500">{soles(v)}</span> },
    { title: 'Neto', dataIndex: 'neto_a_pagar', width: 130, render: (v) => <span className="font-semibold text-gray-900">{soles(v)}</span> },
    {
      title: 'Estado',
      dataIndex: 'estado',
      width: 110,
      render: (estado) => <Tag color={ESTADO_COLOR[estado] ?? 'default'}>{estado}</Tag>,
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 200,
      render: (_, boleta) => (
        <div className="flex items-center gap-1">
          <Tooltip title="Descargar / imprimir boleta">
            <Button size="small" type="text" icon={<DownloadOutlined />} onClick={() => setBoletaImprimirId(boleta.id)} />
          </Tooltip>
          <Tooltip title="Configuración de planilla">
            <Button size="small" type="text" icon={<SettingOutlined />} onClick={() => abrirConfiguracion(boleta)} disabled={!puedeGestionarCiclos} />
          </Tooltip>
          {boleta.regimen_laboral !== 'Locacion de Servicios' && (
            <Tooltip title="Registrar comisión / bono / adelanto">
              <Button size="small" type="text" icon={<WalletOutlined />} onClick={() => abrirConceptos(boleta)} disabled={!puedeGestionarCiclos} />
            </Tooltip>
          )}
          {boleta.estado === 'calculada' && puedeAprobar && (
            <Tooltip title="Aprobar boleta">
              <Button size="small" type="text" icon={<CheckCircleOutlined />} onClick={() => handleAprobar(boleta)} />
            </Tooltip>
          )}
          {boleta.estado === 'aprobada' && puedePagar && (
            <Tooltip title="Marcar como pagada">
              <Button size="small" type="text" icon={<BankOutlined />} onClick={() => handlePagar(boleta)} />
            </Tooltip>
          )}
        </div>
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [puedeGestionarCiclos, puedeAprobar, puedePagar]);

  if (!puedeVer) {
    return <Empty description="No tienes permiso para ver Gestión de Remuneraciones" className="mt-16" />;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <EmpresaActivaFiltro user={user} onUserRefresh={onUserRefresh} />
          <Select
            size="middle"
            className="w-64"
            loading={ciclosLoading}
            placeholder="Selecciona un ciclo remunerativo"
            value={cicloId}
            onChange={setCicloId}
            options={ciclos.map((c) => ({
              value: c.id,
              label: (
                <span className="flex items-center gap-2">
                  {c.nombre}
                  <Tag color={CICLO_ESTADO_COLOR[c.estado] ?? 'default'} className="!m-0">{c.estado}</Tag>
                </span>
              ),
            }))}
          />
          {puedeGestionarCiclos && (
            <Button icon={<PlusOutlined />} onClick={() => setNuevoCicloOpen(true)}>Nuevo ciclo</Button>
          )}
          <Select
            size="middle"
            className="w-44"
            value={tipoFiltro ?? 'todos'}
            onChange={(value) => setTipoFiltro(value === 'todos' ? null : value)}
            options={[
              { value: 'todos', label: 'Todos' },
              { value: 'planilla', label: 'Planilla dependiente' },
              { value: 'honorarios', label: 'Recibos por honorarios' },
            ]}
          />
        </div>
        {cicloActivo && (
          <div className="flex flex-wrap items-center gap-2">
            {puedeCalcular && ['abierto', 'calculado', 'reabierto'].includes(cicloActivo.estado) && (
              <Button type="primary" icon={<ReloadOutlined />} loading={calculando} onClick={() => handleCalcular(cicloActivo, cicloActivo.estado !== 'abierto' ? 'Recálculo de planilla' : undefined)}>
                {cicloActivo.estado !== 'abierto' ? 'Recalcular planilla' : 'Calcular planilla'}
              </Button>
            )}
            {puedeCerrarPeriodo && ['abierto', 'calculado', 'reabierto'].includes(cicloActivo.estado) && (
              <Button icon={<LockOutlined />} onClick={() => handleCerrar(cicloActivo)}>Cerrar período</Button>
            )}
            {puedeCerrarPeriodo && cicloActivo.estado === 'cerrado' && (
              <Button icon={<UnlockOutlined />} onClick={() => handleReabrir(cicloActivo)}>Reabrir período</Button>
            )}
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {ACCIONES_PROXIMAMENTE.map((accion) => (
          <Tooltip key={accion.key} title="Próximamente">
            <Button disabled icon={accion.icon}>{accion.label}</Button>
          </Tooltip>
        ))}
      </div>

      {resumen && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <TarjetaStat icono={<TeamOutlined />} valor={resumen.total_colaboradores} etiqueta="Colaboradores en planilla" color="bg-agento-blue-light text-agento-blue" />
          <TarjetaStat icono={<DollarCircleOutlined />} valor={soles(resumen.total_ingresos)} etiqueta="Total ingresos" color="bg-green-50 text-green-600" />
          <TarjetaStat icono={<WalletOutlined />} valor={soles(resumen.total_egresos)} etiqueta="Total descuentos" color="bg-red-50 text-red-500" />
          <TarjetaStat icono={<BankOutlined />} valor={soles(resumen.total_aportaciones)} etiqueta="Aportaciones empleador" color="bg-blue-50 text-blue-600" />
          <TarjetaStat icono={<CheckCircleOutlined />} valor={soles(resumen.neto_a_pagar)} etiqueta="Neto a pagar" color="bg-gray-100 text-gray-700" />
        </div>
      )}

      <Tabs
        activeKey={tabActiva}
        onChange={setTabActiva}
        items={[
          {
            key: 'ciclos',
            label: 'Ciclos y cálculos',
            children: (
              <Table
                rowKey="id"
                loading={ciclosLoading}
                dataSource={ciclos}
                columns={columnasCiclos}
                scroll={{ x: 900 }}
                pagination={false}
                locale={{ emptyText: 'Todavía no hay ciclos remunerativos — crea el primero con "Nuevo ciclo"' }}
              />
            ),
          },
          {
            key: 'planilla',
            label: 'Planilla mensual',
            children: cicloId ? (
              <Table
                rowKey="id"
                loading={boletasLoading}
                dataSource={boletas}
                columns={columnas}
                scroll={{ x: 1100 }}
                pagination={{
                  current: pagination.current,
                  pageSize: pagination.pageSize,
                  total: pagination.total,
                  onChange: (page, pageSize) => fetchBoletas(cicloId, page, pageSize, tipoFiltro),
                }}
                expandable={{
                  expandedRowRender: (boleta) => <DetalleBoleta boletaId={boleta.id} verBoleta={verBoleta} />,
                }}
                summary={() => resumen && boletas.length > 0 && (
                  <Table.Summary fixed>
                    <Table.Summary.Row>
                      <Table.Summary.Cell index={0} colSpan={4}><strong>Totales</strong></Table.Summary.Cell>
                      <Table.Summary.Cell index={1}><strong className="text-green-600">{soles(resumen.total_ingresos)}</strong></Table.Summary.Cell>
                      <Table.Summary.Cell index={2}><strong className="text-red-500">{soles(resumen.total_egresos)}</strong></Table.Summary.Cell>
                      <Table.Summary.Cell index={3}><strong>{soles(resumen.neto_a_pagar)}</strong></Table.Summary.Cell>
                      <Table.Summary.Cell index={4} colSpan={2} />
                    </Table.Summary.Row>
                  </Table.Summary>
                )}
                locale={{ emptyText: 'Este ciclo todavía no tiene boletas calculadas' }}
              />
            ) : (
              <Empty description="Selecciona o crea un ciclo remunerativo para comenzar" className="mt-8" />
            ),
          },
          {
            key: 'cts-gratificaciones',
            label: 'CTS y gratificaciones',
            children: (
              <CtsGratificacionesTab
                fetchResumenBeneficio={fetchResumenBeneficio}
                calcularBeneficio={calcularBeneficio}
                pagarBeneficio={pagarBeneficio}
                resumen={resumenBeneficio}
                loading={resumenBeneficioLoading}
                puedeCalcular={puedeCalcular}
                puedePagar={puedePagar}
              />
            ),
          },
          {
            key: 'documentacion',
            label: 'Documentación',
            disabled: true,
            children: <Empty description="Próximamente" className="mt-8" />,
          },
        ]}
      />

      <NuevoCicloModal
        open={nuevoCicloOpen}
        onCancel={() => setNuevoCicloOpen(false)}
        onSubmit={handleCrearCiclo}
        loading={creandoCiclo}
      />

      <ConfiguracionNominaModal
        open={!!configuracionColaborador}
        onCancel={() => setConfiguracionColaborador(null)}
        onSubmit={handleGuardarConfiguracion}
        loading={guardandoConfiguracion}
        colaborador={configuracionColaborador}
        afps={afps}
      />

      <RegistrarConceptoModal
        open={!!conceptoColaborador}
        onCancel={() => setConceptoColaborador(null)}
        onSubmit={handleRegistrarConcepto}
        loading={registrandoConcepto}
        colaborador={conceptoColaborador}
        conceptos={conceptosPeriodo}
        conceptosLoading={conceptosPeriodoLoading}
        catalogo={catalogoConceptos}
      />

      <BoletaImprimibleModal
        open={!!boletaImprimirId}
        onCancel={() => setBoletaImprimirId(null)}
        boletaId={boletaImprimirId}
        verBoleta={verBoleta}
      />
    </div>
  );
}
