import {
  BankOutlined,
  BarChartOutlined,
  CalendarOutlined,
  CheckCircleOutlined,
  CreditCardOutlined,
  DeleteOutlined,
  DollarCircleOutlined,
  DownloadOutlined,
  EditOutlined,
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
import { App, Avatar, Button, DatePicker, Empty, Input, Select, Table, Tabs, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import EmpresaActivaFiltro from '../../modules/configuracion/components/EmpresaActivaFiltro';
import BoletaImprimibleModal from '../../modules/remuneraciones/components/BoletaImprimibleModal';
import ComprobanteRhModal from '../../modules/remuneraciones/components/ComprobanteRhModal';
import ConfiguracionNominaModal from '../../modules/remuneraciones/components/ConfiguracionNominaModal';
import CtsGratificacionesTab from '../../modules/remuneraciones/components/CtsGratificacionesTab';
import LiquidacionesCeseTab from '../../modules/remuneraciones/components/LiquidacionesCeseTab';
import AfpNetModal from '../../modules/remuneraciones/components/AfpNetModal';
import BbvaNetCashModal from '../../modules/remuneraciones/components/BbvaNetCashModal';
import NuevoCicloModal from '../../modules/remuneraciones/components/NuevoCicloModal';
import PdtPlameModal from '../../modules/remuneraciones/components/PdtPlameModal';
import RegistrarConceptoModal from '../../modules/remuneraciones/components/RegistrarConceptoModal';
import TelecreditoBcpModal from '../../modules/remuneraciones/components/TelecreditoBcpModal';
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
  validado: 'cyan',
  cerrado: 'default',
  reabierto: 'orange',
  pagado: 'green',
};

/** "Validado" no es un estado persistido — es una lectura derivada de que
 * todas las boletas vigentes del ciclo ya fueron aprobadas/pagadas, la
 * misma señal que ya exige cerrar() para permitir el cierre. Evita crear
 * una segunda fuente de verdad que habría que mantener sincronizada. */
const estadoVisualCiclo = (ciclo) => {
  if (
    ['calculado', 'reabierto'].includes(ciclo.estado)
    && ciclo.boletas_count > 0
    && ciclo.boletas_pendientes_aprobacion_count === 0
  ) {
    return 'validado';
  }
  return ciclo.estado;
};

/** Botones que el mockup de referencia muestra pero que no tienen todavía
 * una implementación real detrás (archivo bancario, formatos oficiales
 * SUNAT/AFP, generación de contratos, etc.) — se muestran deshabilitados
 * con un tooltip, igual que el resto de módulos "próximamente" del
 * Sidebar, en vez de fingir una funcionalidad que no existe. */
const ACCIONES_PROXIMAMENTE = [
  { key: 'proyeccion', label: 'Proyección Semanal', icon: <CalendarOutlined /> },
  { key: 'pagos', label: 'Pagos Masivos', icon: <SendOutlined /> },
  { key: 'contratos', label: 'Contratos', icon: <FileTextOutlined /> },
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
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * Nunca renderiza un 0 de asistencia como si fuera un resultado confirmado
 * cuando el período todavía no fue procesado por Asistencia (Sección 36).
 */
function celdaAsistencia(valor, asistenciaProcesada) {
  if (!asistenciaProcesada) {
    return <span className="text-xs text-gray-400 italic">Sin procesar</span>;
  }
  return valor ?? '—';
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
    ciclos, ciclosLoading, fetchCiclos, crearCiclo, actualizarCiclo, eliminarCiclo, calcularPlanilla, fetchEstadoCalculo, cerrarCiclo, reabrirCiclo, marcarCicloPagado,
    boletas, boletasLoading, pagination, fetchBoletas, fetchBoletasExportablesIds,
    resumen, fetchResumen,
    verBoleta, aprobarBoleta, aprobarBoletasMasivo, pagarBoleta, guardarComprobanteRh,
    afps, fetchAfps,
    catalogoConceptos, fetchCatalogoConceptos,
    resumenBeneficio, resumenBeneficioLoading, fetchResumenBeneficio, calcularBeneficio, pagarBeneficio,
    actualizarConfiguracionNomina,
    fetchConceptosPeriodo, registrarConceptoPeriodo, actualizarConceptoPeriodo, eliminarConceptoPeriodo,
    previsualizacion, previsualizacionLoading, fetchPrevisualizacion,
    fetchPlameValidacion, exportarPlame,
    fetchAfpNetValidacion, exportarAfpNet,
    fetchTelecreditoBcpValidacion, exportarTelecreditoBcp,
    fetchBbvaNetCashValidacion, exportarBbvaNetCash,
  } = useRemuneraciones();

  const [cicloId, setCicloId] = useState(null);
  const [tabActiva, setTabActiva] = useState('previsualizacion');
  const [mesPrevisualizacion, setMesPrevisualizacion] = useState(() => dayjs());
  const [busquedaPrevisualizacion, setBusquedaPrevisualizacion] = useState('');
  const [nuevoCicloOpen, setNuevoCicloOpen] = useState(false);
  const [cicloEditar, setCicloEditar] = useState(null);
  const [creandoCiclo, setCreandoCiclo] = useState(false);
  const [calculando, setCalculando] = useState(false);

  const [configuracionColaborador, setConfiguracionColaborador] = useState(null);
  const [guardandoConfiguracion, setGuardandoConfiguracion] = useState(false);

  const [conceptoColaborador, setConceptoColaborador] = useState(null);
  const [conceptoCicloId, setConceptoCicloId] = useState(null);
  const [conceptoEsHonorarios, setConceptoEsHonorarios] = useState(false);
  const [conceptosPeriodo, setConceptosPeriodo] = useState([]);
  const [conceptosPeriodoLoading, setConceptosPeriodoLoading] = useState(false);
  const [registrandoConcepto, setRegistrandoConcepto] = useState(false);

  const [tipoFiltro, setTipoFiltro] = useState(null);
  const [busquedaPlanilla, setBusquedaPlanilla] = useState('');
  const [boletasSeleccionadas, setBoletasSeleccionadas] = useState([]);
  const [seleccionandoTodas, setSeleccionandoTodas] = useState(false);
  const [boletaImprimirId, setBoletaImprimirId] = useState(null);
  const [comprobanteRhBoletaId, setComprobanteRhBoletaId] = useState(null);
  const [guardandoComprobanteRh, setGuardandoComprobanteRh] = useState(false);
  const [plameModalOpen, setPlameModalOpen] = useState(false);
  const [afpNetModalOpen, setAfpNetModalOpen] = useState(false);
  const [telecreditoBcpModalOpen, setTelecreditoBcpModalOpen] = useState(false);
  const [bbvaNetCashModalOpen, setBbvaNetCashModalOpen] = useState(false);

  const puedeVer = user?.permisos?.includes('nominas.ver');
  const puedeGestionarCiclos = user?.permisos?.includes('nominas.gestionar_ciclos');
  const puedeCalcular = user?.permisos?.includes('nominas.calcular');
  const puedeCerrarPeriodo = user?.permisos?.includes('nominas.cerrar_periodo');
  const puedeAprobar = user?.permisos?.includes('nominas.aprobar');
  const puedePagar = user?.permisos?.includes('nominas.pagar');
  const puedeExportarTelecredito = user?.permisos?.includes('nominas.telecredito_exportar');
  const puedeExportarBbvaNetCash = user?.permisos?.includes('nominas.bbva_netcash_exportar');
  const puedeExportarBanco = puedeExportarTelecredito || puedeExportarBbvaNetCash;
  const puedeSeleccionarBoletas = puedeAprobar || puedeExportarTelecredito || puedeExportarBbvaNetCash;
  const boletasCalculadasSeleccionadas = boletas
    .filter((boleta) => boleta.estado === 'calculada' && boletasSeleccionadas.includes(boleta.id))
    .map((boleta) => boleta.id);

  /**
   * El backend lista los ciclos de TODAS las empresas que el usuario
   * administra (ver CicloRemunerativoController::empresaIdsAutorizadas),
   * agrupados por empresa en el selector — pero al cambiar la empresa
   * activa (arriba, mismo selector que el resto del sistema) el ciclo
   * seleccionado debe seguirla, igual que en cualquier otro módulo: se
   * salta automáticamente al ciclo más reciente de la empresa recién
   * activada. El selector de ciclo sigue permitiendo elegir a mano uno de
   * OTRA empresa autorizada sin cambiar la activa, para una consulta rápida.
   */
  useEffect(() => {
    if (!puedeVer) return;
    fetchCiclos().then((data) => {
      const cicloDeEmpresaActiva = data.find((c) => c.empresa?.id === user?.empresa?.id);
      setCicloId((cicloDeEmpresaActiva ?? data[0])?.id ?? null);
    });
    fetchAfps();
    fetchCatalogoConceptos();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [puedeVer, user?.empresa?.id]);

  useEffect(() => {
    if (!cicloId) return;
    fetchBoletas(cicloId, 1, pagination.pageSize, tipoFiltro, busquedaPlanilla);
    fetchResumen(cicloId, tipoFiltro, busquedaPlanilla);
    // Evita arrastrar ids seleccionados de otro ciclo/filtro al aprobar masivo.
    setBoletasSeleccionadas([]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cicloId, tipoFiltro, busquedaPlanilla]);

  useEffect(() => {
    if (!puedeVer) return;
    fetchPrevisualizacion(mesPrevisualizacion.year(), mesPrevisualizacion.month() + 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [puedeVer, mesPrevisualizacion, user?.empresa?.id]);

  const cicloActivo = ciclos.find((c) => c.id === cicloId);

  /**
   * El selector de ciclo lista los de TODAS las empresas que el usuario
   * administra (no solo la empresa activa), agrupados por empresa — así se
   * puede operar sobre la planilla de cualquiera de ellas sin tener que
   * cambiar de empresa activa primero.
   */
  const opcionesCiclo = useMemo(() => {
    const grupos = new Map();
    for (const c of ciclos) {
      const nombreEmpresa = c.empresa?.nombre_comercial ?? 'Sin empresa';
      if (!grupos.has(nombreEmpresa)) grupos.set(nombreEmpresa, []);
      grupos.get(nombreEmpresa).push({
        value: c.id,
        title: `${nombreEmpresa} ${c.nombre}`,
        label: (
          <span className="flex items-center gap-2">
            {c.nombre}
            <Tag color={CICLO_ESTADO_COLOR[estadoVisualCiclo(c)] ?? 'default'} className="!m-0">{estadoVisualCiclo(c)}</Tag>
          </span>
        ),
      });
    }
    return Array.from(grupos.entries())
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([nombreEmpresa, options]) => ({ label: nombreEmpresa, title: nombreEmpresa, options }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ciclos]);

  const recargar = () => {
    if (cicloId) {
      fetchBoletas(cicloId, pagination.current, pagination.pageSize, tipoFiltro, busquedaPlanilla);
      fetchResumen(cicloId, tipoFiltro, busquedaPlanilla);
    }
  };

  const handleSeleccionarTodasExportables = async () => {
    setSeleccionandoTodas(true);
    try {
      const ids = await fetchBoletasExportablesIds(cicloId, tipoFiltro, busquedaPlanilla);
      setBoletasSeleccionadas(ids);
      if (ids.length === 0) message.info('No hay boletas aprobadas o pagadas con el filtro actual');
    } catch {
      message.error('No se pudo seleccionar todas las boletas del filtro');
    } finally {
      setSeleccionandoTodas(false);
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

  const handleActualizarCiclo = async (values) => {
    setCreandoCiclo(true);
    try {
      await actualizarCiclo(cicloEditar.id, values);
      message.success('Ciclo remunerativo actualizado');
      setCicloEditar(null);
      fetchCiclos();
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo actualizar el ciclo');
    } finally {
      setCreandoCiclo(false);
    }
  };

  const handleEliminarCiclo = (ciclo) => {
    modal.confirm({
      title: 'Eliminar ciclo remunerativo',
      content: `¿Eliminar "${ciclo.nombre}"? Esta acción no se puede deshacer. Solo es posible mientras el ciclo no tenga boletas calculadas.`,
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await eliminarCiclo(ciclo.id);
          message.success('Ciclo remunerativo eliminado');
          if (cicloId === ciclo.id) setCicloId(null);
          await fetchCiclos();
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo eliminar el ciclo');
        }
      },
    });
  };

  /**
   * El cálculo ahora corre en una cola (CalcularPlanillaJob) en vez de
   * bloquear el request — con una planilla grande, esperar la respuesta en
   * vivo dejaría de ser viable. Aquí solo se dispara y se hace polling del
   * estado hasta que el worker termine.
   */
  const pollEstadoCalculo = async (ciclo, intentos = 0) => {
    const estado = await fetchEstadoCalculo(ciclo.id);

    if (estado.calculo_estado === 'en_proceso') {
      if (intentos > 100) {
        message.info('El cálculo sigue en proceso. La planilla se actualizará automáticamente cuando termine — puedes seguir usando el sistema mientras tanto.');
        setCalculando(false);
        return;
      }
      setTimeout(() => pollEstadoCalculo(ciclo, intentos + 1), 3000);
      return;
    }

    setCalculando(false);

    if (estado.calculo_estado === 'error') {
      message.error(`No se pudo calcular la planilla: ${estado.calculo_resultado?.error ?? 'error desconocido'}`);
      return;
    }

    const resultado = estado.calculo_resultado ?? { procesadas: 0, omitidas: [] };
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
  };

  const handleCalcular = (ciclo, motivoRecalculo) => {
    modal.confirm({
      title: motivoRecalculo ? 'Recalcular planilla' : 'Calcular planilla',
      content: 'Se calculará (o recalculará) la boleta de cada colaborador elegible de este ciclo, en segundo plano. Las versiones anteriores se conservan en el historial.',
      okText: 'Confirmar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setCalculando(true);
        try {
          await calcularPlanilla(ciclo.id, motivoRecalculo);
          message.info('Cálculo iniciado — esto puede tardar unos segundos según el tamaño de la planilla.');
          pollEstadoCalculo(ciclo);
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo iniciar el cálculo de la planilla');
          setCalculando(false);
        }
      },
    });
  };

  // Si el ciclo activo ya estaba "en_proceso" (ej. lo disparó otro usuario,
  // o se recargó la página), retoma el polling en vez de dejarlo huérfano.
  useEffect(() => {
    if (cicloActivo?.calculo_estado === 'en_proceso' && !calculando) {
      setCalculando(true);
      pollEstadoCalculo(cicloActivo);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cicloActivo?.id, cicloActivo?.calculo_estado]);

  const handleCerrar = async (ciclo) => {
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

  const handleMarcarPagado = (ciclo) => {
    modal.confirm({
      title: 'Marcar período como pagado',
      content: 'Esto formaliza el período como pagado y ya no se podrá reabrir. Requiere que todas las boletas del ciclo estén pagadas.',
      okText: 'Marcar como pagado',
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await marcarCicloPagado(ciclo.id);
          message.success('Período marcado como pagado');
          fetchCiclos();
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo marcar el período como pagado');
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

  const handleAprobarMasivo = async () => {
    const idsAprobar = boletasCalculadasSeleccionadas;
    const cantidad = idsAprobar.length;
    modal.confirm({
      title: 'Aprobar boletas seleccionadas',
      content: `Se aprobarán ${cantidad} boleta(s) en estado "calculada". Esto ayuda a completar la aprobación antes de cerrar el ciclo.`,
      okText: 'Aprobar',
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          const resultado = await aprobarBoletasMasivo(cicloId, idsAprobar);
          message.success(`${resultado.procesadas} boleta(s) aprobada(s).`);
          setBoletasSeleccionadas([]);
          recargar();
          fetchCiclos();
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudieron aprobar las boletas seleccionadas');
        }
      },
    });
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

  /**
   * Usa boleta.ciclo_id (el ciclo real al que pertenece ESTA boleta), nunca
   * el `cicloId` del selector superior: `columnas` está memoizado con deps
   * que no incluyen `cicloId`, así que una función que capturara ese estado
   * por clausura quedaría congelada con su valor inicial (null, antes de
   * que se cargue el primer ciclo) — eso hacía que la petición siempre
   * fuera a ".../ciclos-remunerativos/null/..." y devolviera 404.
   */
  const abrirConceptos = async (boleta) => {
    setConceptoColaborador(boleta.colaborador);
    setConceptoCicloId(boleta.ciclo_id);
    setConceptoEsHonorarios(boleta.regimen_laboral === 'Locacion de Servicios');
    setConceptosPeriodoLoading(true);
    try {
      const data = await fetchConceptosPeriodo(boleta.ciclo_id, boleta.colaborador.id);
      setConceptosPeriodo(data);
    } catch {
      message.error('No se pudieron cargar los conceptos ya registrados de este colaborador');
    } finally {
      setConceptosPeriodoLoading(false);
    }
  };

  const handleRegistrarConcepto = async (colaboradorId, values) => {
    setRegistrandoConcepto(true);
    try {
      await registrarConceptoPeriodo(conceptoCicloId, colaboradorId, values);
      message.success('Concepto registrado. Se incluirá en el próximo cálculo de la planilla.');
      const data = await fetchConceptosPeriodo(conceptoCicloId, colaboradorId);
      setConceptosPeriodo(data);
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo registrar el concepto');
    } finally {
      setRegistrandoConcepto(false);
    }
  };

  const handleActualizarConcepto = async (colaboradorId, conceptoPeriodoId, values) => {
    setRegistrandoConcepto(true);
    try {
      await actualizarConceptoPeriodo(conceptoCicloId, colaboradorId, conceptoPeriodoId, values);
      message.success('Concepto actualizado. Se reflejará en el próximo cálculo de la planilla.');
      const data = await fetchConceptosPeriodo(conceptoCicloId, colaboradorId);
      setConceptosPeriodo(data);
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo actualizar el concepto');
    } finally {
      setRegistrandoConcepto(false);
    }
  };

  const handleEliminarConcepto = (item) => {
    modal.confirm({
      title: 'Eliminar concepto',
      content: `¿Eliminar "${item.nombre}" por S/ ${Number(item.monto).toFixed(2)}? Esta acción no se puede deshacer.`,
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await eliminarConceptoPeriodo(conceptoCicloId, conceptoColaborador.id, item.id);
          message.success('Concepto eliminado.');
          const data = await fetchConceptosPeriodo(conceptoCicloId, conceptoColaborador.id);
          setConceptosPeriodo(data);
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo eliminar el concepto');
        }
      },
    });
  };

  const handleGuardarComprobanteRh = async (boletaId, values) => {
    setGuardandoComprobanteRh(true);
    try {
      await guardarComprobanteRh(boletaId, values);
      message.success('Comprobante de honorarios guardado');
      setComprobanteRhBoletaId(null);
    } catch (err) {
      message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo guardar el comprobante');
    } finally {
      setGuardandoComprobanteRh(false);
    }
  };

  const columnasCiclos = useMemo(() => [
    { title: 'Nombre', dataIndex: 'nombre', width: 200 },
    {
      title: 'Empresa',
      key: 'empresa',
      width: 160,
      render: (_, c) => <span className="font-semibold text-gray-700">{c.empresa?.nombre_comercial ?? '—'}</span>,
    },
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
      width: 150,
      render: (estado, ciclo) => (
        <div className="flex items-center gap-1">
          <Tag color={CICLO_ESTADO_COLOR[estadoVisualCiclo(ciclo)] ?? 'default'}>{estadoVisualCiclo(ciclo)}</Tag>
          {ciclo.calculo_estado === 'en_proceso' && <Tag icon={<ReloadOutlined spin />} color="processing">calculando</Tag>}
        </div>
      ),
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
      width: 320,
      render: (_, ciclo) => (
        <div className="flex flex-wrap items-center gap-1">
          <Button size="small" onClick={() => { setCicloId(ciclo.id); setTabActiva('planilla'); }}>Ver planilla</Button>
          {puedeGestionarCiclos && (ciclo.boletas_count ?? 0) === 0 && (
            <Tooltip title="Editar fechas del ciclo">
              <Button size="small" icon={<EditOutlined />} onClick={() => setCicloEditar(ciclo)} />
            </Tooltip>
          )}
          {puedeGestionarCiclos && (ciclo.boletas_count ?? 0) === 0 && (
            <Tooltip title="Eliminar ciclo">
              <Button size="small" danger icon={<DeleteOutlined />} onClick={() => handleEliminarCiclo(ciclo)} />
            </Tooltip>
          )}
          {puedeCalcular && ['abierto', 'calculado', 'reabierto'].includes(ciclo.estado) && (
            <Tooltip title={ciclo.calculo_estado === 'en_proceso' ? 'Ya hay un cálculo en curso' : (ciclo.estado !== 'abierto' ? 'Recalcular planilla' : 'Calcular planilla')}>
              <Button
                size="small"
                icon={<ReloadOutlined spin={ciclo.calculo_estado === 'en_proceso'} />}
                disabled={ciclo.calculo_estado === 'en_proceso'}
                onClick={() => handleCalcular(ciclo, ciclo.estado !== 'abierto' ? 'Recálculo de planilla' : undefined)}
              />
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
          {puedePagar && ciclo.estado === 'cerrado' && (
            <Tooltip title="Marcar como pagado">
              <Button size="small" icon={<DollarCircleOutlined />} onClick={() => handleMarcarPagado(ciclo)} />
            </Tooltip>
          )}
        </div>
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [puedeGestionarCiclos, puedeCalcular, puedeCerrarPeriodo, puedePagar, cicloId]);

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
            <p className="truncate text-xs text-gray-400">{boleta.colaborador?.cargo ?? boleta.colaborador?.legajo}</p>
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
    {
      title: 'Faltas',
      key: 'faltas',
      width: 90,
      render: (_, b) => celdaAsistencia(b.dias_falta, b.asistencia_procesada),
    },
    {
      title: 'Tardanza (min)',
      key: 'tardanza',
      width: 110,
      render: (_, b) => celdaAsistencia(b.minutos_tardanza, b.asistencia_procesada),
    },
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
          <Tooltip title={boleta.estado === 'pagada' ? 'Descargar boleta oficial' : 'Vista previa — documento no oficial (aún no está pagada)'}>
            <Button
              size="small"
              type="text"
              icon={<DownloadOutlined />}
              onClick={() => setBoletaImprimirId(boleta.id)}
            />
          </Tooltip>
          <Tooltip title="Configuración de planilla">
            <Button size="small" type="text" icon={<SettingOutlined />} onClick={() => abrirConfiguracion(boleta)} disabled={!puedeGestionarCiclos} />
          </Tooltip>
          <Tooltip title={boleta.regimen_laboral === 'Locacion de Servicios' ? 'Registrar descuento (adelanto, error operativo, compra de mercadería)' : 'Registrar comisión / bono / adelanto / descuento'}>
            <Button size="small" type="text" icon={<WalletOutlined />} onClick={() => abrirConceptos(boleta)} disabled={!puedeGestionarCiclos} />
          </Tooltip>
          {boleta.regimen_laboral === 'Locacion de Servicios' && (
            <Tooltip title="Comprobante de honorarios (RH)">
              <Button size="small" type="text" icon={<FileTextOutlined />} onClick={() => setComprobanteRhBoletaId(boleta.id)} disabled={!puedeGestionarCiclos} />
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

  const columnasPrevisualizacion = useMemo(() => [
    {
      title: 'Colaborador',
      key: 'colaborador',
      width: 220,
      render: (_, fila) => (
        <div className="flex items-center gap-3">
          <Avatar style={{ backgroundColor: colorForName(fila.nombre) }}>{initialsForName(fila.nombre)}</Avatar>
          <div className="min-w-0">
            <p className="truncate font-semibold text-gray-900">{fila.nombre}</p>
            <p className="truncate text-xs text-gray-400">{fila.cargo ?? '—'}</p>
          </div>
        </div>
      ),
    },
    { title: 'Sueldo base', dataIndex: 'sueldo_basico', width: 120, render: (v, f) => (f.estado === 'calculable' ? soles(v) : '—') },
    { title: 'Faltas', key: 'faltas', width: 90, render: (_, f) => celdaAsistencia(f.dias_falta, f.asistencia_procesada) },
    { title: 'Tardanza (min)', key: 'tardanza', width: 110, render: (_, f) => celdaAsistencia(f.minutos_tardanza, f.asistencia_procesada) },
    { title: 'Total ingresos', dataIndex: 'total_ingresos', width: 130, render: (v, f) => (f.estado === 'calculable' ? <span className="text-green-600">{soles(v)}</span> : '—') },
    { title: 'Descuentos', dataIndex: 'total_egresos', width: 120, render: (v, f) => (f.estado === 'calculable' ? <span className="text-red-500">{soles(v)}</span> : '—') },
    { title: 'Neto estimado', dataIndex: 'neto_a_pagar', width: 130, render: (v, f) => (f.estado === 'calculable' ? <span className="font-semibold text-gray-900">{soles(v)}</span> : '—') },
    {
      title: 'Estado',
      key: 'estado',
      width: 220,
      render: (_, f) => (
        f.estado === 'calculable'
          ? <Tag color="blue">Calculable</Tag>
          : <Tooltip title={f.motivo}><Tag color="red">No calculable</Tag></Tooltip>
      ),
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], []);

  const previsualizacionFiltrada = useMemo(() => {
    const texto = busquedaPrevisualizacion.trim().toLowerCase();
    if (!texto) return previsualizacion;
    return (previsualizacion ?? []).filter((fila) => (
      fila.nombre?.toLowerCase().includes(texto) || fila.cargo?.toLowerCase().includes(texto)
    ));
  }, [previsualizacion, busquedaPrevisualizacion]);

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
            className="w-72"
            loading={ciclosLoading}
            placeholder="Selecciona un ciclo remunerativo"
            showSearch={{
              optionFilterProp: 'title',
              filterOption: (input, option) =>
                option?.options ? undefined : `${option?.title ?? ''}`.toLowerCase().includes(input.toLowerCase()),
            }}
            value={cicloId}
            onChange={setCicloId}
            options={opcionesCiclo}
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
            {puedePagar && cicloActivo.estado === 'cerrado' && (
              <Button icon={<DollarCircleOutlined />} onClick={() => handleMarcarPagado(cicloActivo)}>Marcar como pagado</Button>
            )}
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Tooltip title={cicloActivo ? '' : 'Selecciona un ciclo remunerativo primero'}>
          <Button icon={<FilePdfOutlined />} disabled={!cicloActivo} onClick={() => setPlameModalOpen(true)}>
            PDT PLAME
          </Button>
        </Tooltip>
        <Tooltip title={cicloActivo ? '' : 'Selecciona un ciclo remunerativo primero'}>
          <Button icon={<FileProtectOutlined />} disabled={!cicloActivo} onClick={() => setAfpNetModalOpen(true)}>
            AFPnet
          </Button>
        </Tooltip>
        <Tooltip title={cicloActivo ? '' : 'Selecciona un ciclo remunerativo primero'}>
          <Button icon={<CreditCardOutlined />} disabled={!cicloActivo} onClick={() => setTelecreditoBcpModalOpen(true)}>
            Telecrédito BCP
          </Button>
        </Tooltip>
        <Tooltip title={cicloActivo ? '' : 'Selecciona un ciclo remunerativo primero'}>
          <Button icon={<BankOutlined />} disabled={!cicloActivo} onClick={() => setBbvaNetCashModalOpen(true)}>
            BBVA Net Cash
          </Button>
        </Tooltip>
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
            key: 'previsualizacion',
            label: 'Previsualización mensual',
            children: (
              <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                  <DatePicker
                    picker="month"
                    format="MMMM YYYY"
                    allowClear={false}
                    value={mesPrevisualizacion}
                    onChange={(valor) => setMesPrevisualizacion(valor ?? dayjs())}
                  />
                  <Input.Search
                    allowClear
                    placeholder="Buscar colaborador por nombre o cargo"
                    className="w-72"
                    value={busquedaPrevisualizacion}
                    onChange={(e) => setBusquedaPrevisualizacion(e.target.value)}
                  />
                  <span className="text-xs text-gray-400">
                    Vista preliminar del período — no crea ni modifica ningún ciclo. Corrige aquí lo que haga falta antes de calcular/cerrar el ciclo formal.
                  </span>
                </div>
                <Table
                  rowKey="colaborador_id"
                  loading={previsualizacionLoading}
                  dataSource={previsualizacionFiltrada}
                  columns={columnasPrevisualizacion}
                  scroll={{ x: 1000 }}
                  pagination={false}
                  locale={{ emptyText: 'No hay colaboradores elegibles para este período' }}
                />
              </div>
            ),
          },
          {
            key: 'ciclos',
            label: 'Ciclos y cálculos',
            children: (
              <Table
                rowKey="id"
                loading={ciclosLoading}
                dataSource={ciclos}
                columns={columnasCiclos}
                scroll={{ x: 1060 }}
                pagination={false}
                locale={{ emptyText: 'Todavía no hay ciclos remunerativos — crea el primero con "Nuevo ciclo"' }}
              />
            ),
          },
          {
            key: 'planilla',
            label: 'Planilla mensual',
            children: cicloId ? (
              <div className="space-y-3">
                <Input.Search
                  allowClear
                  placeholder="Buscar colaborador por nombre o cargo"
                  className="w-72"
                  value={busquedaPlanilla}
                  onChange={(e) => setBusquedaPlanilla(e.target.value)}
                />
                {(boletasSeleccionadas.length > 0 || puedeExportarBanco) && (
                  <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5">
                    <span className="text-sm font-medium text-blue-900">{boletasSeleccionadas.length} boleta(s) seleccionada(s)</span>
                    <div className="flex flex-wrap gap-2">
                      {puedeExportarBanco && (
                        <Button size="small" loading={seleccionandoTodas} onClick={handleSeleccionarTodasExportables}>
                          Seleccionar todas las boletas del filtro
                        </Button>
                      )}
                      {boletasSeleccionadas.length > 0 && (
                        <Button size="small" onClick={() => setBoletasSeleccionadas([])}>Limpiar seleccion</Button>
                      )}
                      {puedeAprobar && boletasCalculadasSeleccionadas.length > 0 && (
                        <Button type="primary" size="small" icon={<CheckCircleOutlined />} onClick={handleAprobarMasivo}>
                          Aprobar {boletasCalculadasSeleccionadas.length} calculada(s)
                        </Button>
                      )}
                    </div>
                  </div>
                )}
                <Table
                  rowKey="id"
                  loading={boletasLoading}
                  dataSource={boletas}
                  columns={columnas}
                  scroll={{ x: 1100 }}
                  rowSelection={puedeSeleccionarBoletas ? {
                    selectedRowKeys: boletasSeleccionadas,
                    onChange: setBoletasSeleccionadas,
                    preserveSelectedRowKeys: true,
                    getCheckboxProps: (boleta) => ({
                      disabled: boleta.estado === 'calculada'
                        ? !puedeAprobar
                        : !puedeExportarBanco || !['aprobada', 'pagada'].includes(boleta.estado),
                    }),
                  } : undefined}
                  pagination={{
                    current: pagination.current,
                    pageSize: pagination.pageSize,
                    total: pagination.total,
                    onChange: (page, pageSize) => fetchBoletas(cicloId, page, pageSize, tipoFiltro, busquedaPlanilla),
                  }}
                  expandable={{
                    expandedRowRender: (boleta) => <DetalleBoleta boletaId={boleta.id} verBoleta={verBoleta} />,
                  }}
                  summary={() => resumen && boletas.length > 0 && (
                    <Table.Summary fixed>
                      <Table.Summary.Row>
                        <Table.Summary.Cell index={0} colSpan={7}><strong>Totales</strong></Table.Summary.Cell>
                        <Table.Summary.Cell index={1}><strong className="text-green-600">{soles(resumen.total_ingresos)}</strong></Table.Summary.Cell>
                        <Table.Summary.Cell index={2}><strong className="text-red-500">{soles(resumen.total_egresos)}</strong></Table.Summary.Cell>
                        <Table.Summary.Cell index={3}><strong>{soles(resumen.neto_a_pagar)}</strong></Table.Summary.Cell>
                        <Table.Summary.Cell index={4} colSpan={2} />
                      </Table.Summary.Row>
                    </Table.Summary>
                  )}
                  locale={{ emptyText: 'Este ciclo todavía no tiene boletas calculadas' }}
                />
              </div>
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
            key: 'liquidaciones',
            label: 'Liquidaciones',
            children: <LiquidacionesCeseTab empresaId={user?.empresa?.id} puedeAprobar={puedeAprobar} puedePagar={puedePagar} />,
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
        open={nuevoCicloOpen || !!cicloEditar}
        ciclo={cicloEditar}
        onCancel={() => { setNuevoCicloOpen(false); setCicloEditar(null); }}
        onSubmit={cicloEditar ? handleActualizarCiclo : handleCrearCiclo}
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
        onCancel={() => { setConceptoColaborador(null); setConceptoCicloId(null); }}
        onSubmit={handleRegistrarConcepto}
        onUpdate={handleActualizarConcepto}
        onDelete={handleEliminarConcepto}
        loading={registrandoConcepto}
        colaborador={conceptoColaborador}
        conceptos={conceptosPeriodo}
        conceptosLoading={conceptosPeriodoLoading}
        catalogo={catalogoConceptos}
        esHonorarios={conceptoEsHonorarios}
      />

      <BoletaImprimibleModal
        open={!!boletaImprimirId}
        onCancel={() => setBoletaImprimirId(null)}
        boletaId={boletaImprimirId}
        verBoleta={verBoleta}
      />

      <ComprobanteRhModal
        open={!!comprobanteRhBoletaId}
        onCancel={() => setComprobanteRhBoletaId(null)}
        boletaId={comprobanteRhBoletaId}
        verBoleta={verBoleta}
        submitting={guardandoComprobanteRh}
        onGuardar={handleGuardarComprobanteRh}
      />

      <PdtPlameModal
        open={plameModalOpen}
        onCancel={() => setPlameModalOpen(false)}
        ciclo={cicloActivo}
        fetchValidacion={fetchPlameValidacion}
        exportarPlame={exportarPlame}
      />

      <AfpNetModal
        open={afpNetModalOpen}
        onCancel={() => setAfpNetModalOpen(false)}
        ciclo={cicloActivo}
        fetchValidacion={fetchAfpNetValidacion}
        exportarAfpNet={exportarAfpNet}
      />

      <TelecreditoBcpModal
        open={telecreditoBcpModalOpen}
        onCancel={() => setTelecreditoBcpModalOpen(false)}
        ciclo={cicloActivo}
        fetchValidacion={fetchTelecreditoBcpValidacion}
        exportarTelecreditoBcp={exportarTelecreditoBcp}
      />

      <BbvaNetCashModal
        open={bbvaNetCashModalOpen}
        onCancel={() => setBbvaNetCashModalOpen(false)}
        ciclo={cicloActivo}
        boletaIds={boletasSeleccionadas}
        boletaIds={boletasSeleccionadas}
        fetchValidacion={fetchBbvaNetCashValidacion}
        exportarBbvaNetCash={exportarBbvaNetCash}
      />
    </div>
  );
}
