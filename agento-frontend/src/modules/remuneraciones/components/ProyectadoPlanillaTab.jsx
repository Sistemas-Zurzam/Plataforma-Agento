import { FileExcelOutlined, FilePdfOutlined } from '@ant-design/icons';
import { App, Avatar, Button, DatePicker, Input, Select, Table, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { useEmpresas } from '../../configuracion/hooks/useEmpresas';
import { colorForName, initialsForName } from '../../../utils/avatarColor';
import ReporteEjecutivoImprimibleModal from './ReporteEjecutivoImprimibleModal';

const TODAS = 'todas';
const MAX_DIAS_RANGO = 31;

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Nunca muestra un 0 de asistencia como resultado confirmado si Asistencia aún no procesó el período. */
function celdaAsistencia(valor, asistenciaProcesada) {
  if (!asistenciaProcesada) {
    return <span className="text-xs text-gray-400 italic">Sin procesar</span>;
  }
  return valor ?? '—';
}

const montoSiCalculable = (clase) => (v, f) => (f.estado === 'calculable' ? <span className={clase}>{soles(v)}</span> : '—');

const COLUMNAS_COLABORADOR = [
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
  { title: 'Sueldo base', dataIndex: 'sueldo_basico', width: 120, render: montoSiCalculable('') },
  { title: 'Faltas', key: 'faltas', width: 80, render: (_, f) => celdaAsistencia(f.dias_falta, f.asistencia_procesada) },
  { title: 'Tardanza (min)', key: 'tardanza', width: 110, render: (_, f) => celdaAsistencia(f.minutos_tardanza, f.asistencia_procesada) },
  {
    title: 'Asistencia al',
    dataIndex: 'fecha_corte_asistencia',
    width: 110,
    render: (v) => (v
      ? <Tooltip title="Hasta esta fecha se usan los resultados de asistencia; los días siguientes del rango se proyectan al sueldo.">{dayjs(v).format('DD/MM')}</Tooltip>
      : <span className="text-gray-400">Sin procesar</span>),
  },
  { title: 'Ingresos', dataIndex: 'total_ingresos', width: 120, render: montoSiCalculable('text-green-600') },
  { title: 'Descuentos', dataIndex: 'total_egresos', width: 120, render: montoSiCalculable('text-red-500') },
  { title: 'Aportaciones', dataIndex: 'total_aportaciones', width: 120, render: montoSiCalculable('') },
  { title: 'Neto proyectado', dataIndex: 'neto_a_pagar', width: 130, render: montoSiCalculable('font-semibold text-gray-900') },
  {
    title: 'Básico devengado a hoy',
    key: 'basico_devengado',
    width: 150,
    render: (_, f) => {
      if (f.estado !== 'calculable') return '—';
      if (f.basico_devengado == null) {
        return <Tooltip title="No aplica a recibos por honorarios">—</Tooltip>;
      }
      return (
        <div>
          <p className="font-semibold text-gray-900">{soles(f.basico_devengado)}</p>
          <p className="text-xs text-gray-400">{f.dias_devengados} días</p>
        </div>
      );
    },
  },
  {
    title: 'Estado',
    key: 'estado',
    width: 130,
    render: (_, f) => (
      f.estado === 'calculable'
        ? <Tag color="blue">Calculable</Tag>
        : <Tooltip title={f.motivo}><Tag color="red">No calculable</Tag></Tooltip>
    ),
  },
];

const COLUMNAS_EMPRESA = [
  { title: 'Empresa', dataIndex: 'empresa', render: (v) => <strong>{v}</strong> },
  {
    title: 'Colaboradores',
    dataIndex: 'colaboradores',
    align: 'right',
    width: 130,
    render: (v, fila) => (fila.no_calculables
      ? <Tooltip title={`${fila.no_calculables} no calculable(s): no suman en los totales`}>{v} <Tag color="red" className="ml-1">{fila.no_calculables}</Tag></Tooltip>
      : v),
  },
  { title: 'Ingresos', dataIndex: 'total_ingresos', align: 'right', render: soles },
  { title: 'Descuentos', dataIndex: 'total_egresos', align: 'right', render: soles },
  { title: 'Aportaciones', dataIndex: 'total_aportaciones', align: 'right', render: soles },
  { title: 'Neto proyectado', dataIndex: 'neto_a_pagar', align: 'right', render: (v) => <strong>{soles(v)}</strong> },
];

const fecha = (valor) => (valor ? dayjs(valor).format('DD/MM/YYYY') : '—');

/**
 * Detalle del modal de exportación: rango, fecha del proyectado y, por
 * empresa, hasta qué día se usó asistencia real (fecha de corte) — los
 * días posteriores del rango se proyectaron al sueldo.
 */
function ResumenExportacion({ datos, archivo }) {
  const cortes = (datos?.empresas ?? []).map((empresa) => {
    const filas = (datos.filas ?? []).filter((fila) => fila.empresa_id === empresa.empresa_id);
    const fechas = filas.map((fila) => fila.fecha_corte_asistencia).filter(Boolean).sort();
    return {
      empresa: empresa.empresa,
      corte: fechas[fechas.length - 1] ?? null,
      sinProcesar: filas.length - fechas.length,
    };
  });

  return (
    <div className="space-y-2 text-sm">
      <p>
        Rango: <strong>{fecha(datos?.fecha_desde)} al {fecha(datos?.fecha_hasta)}</strong> ({datos?.dias ?? '—'} días de 30)
        <br />
        Proyectado al: <strong>{dayjs().format('DD/MM/YYYY')}</strong>
      </p>
      <div>
        <p className="font-semibold">Fecha de corte de asistencia</p>
        <ul className="list-disc pl-5">
          {cortes.map((c) => (
            <li key={c.empresa}>
              {c.empresa}: <strong>{c.corte ? fecha(c.corte) : 'sin asistencia procesada'}</strong>
              {c.corte && c.sinProcesar > 0 && <span className="text-gray-500"> · {c.sinProcesar} colaborador(es) sin procesar</span>}
            </li>
          ))}
        </ul>
        <p className="mt-1 text-xs text-gray-500">Hasta esa fecha se usaron las faltas y tardanzas reales; desde el día siguiente el rango se proyectó con el sueldo.</p>
      </div>
      {archivo && <p className="text-xs text-gray-500">Archivo: {archivo}</p>}
    </div>
  );
}

/**
 * Proyectado de planilla por rango de fechas (máx. 31 días) y por empresa —
 * una empresa autorizada o todas, con totales por empresa (mismos campos que
 * el Resumen contable) y el detalle por colaborador al desplegar cada una.
 * Solo lectura: no crea ni modifica ciclos.
 */
export default function ProyectadoPlanillaTab({ previsualizacion, previsualizacionLoading, fetchPrevisualizacion, exportarPrevisualizacionExcel, fetchPrevisualizacionDatosReporte }) {
  const { message, modal } = App.useApp();
  const { empresas, fetchEmpresas } = useEmpresas();
  const [rango, setRango] = useState(() => [dayjs().startOf('month'), dayjs().endOf('month')]);
  const [empresaId, setEmpresaId] = useState(TODAS);
  // null = automático: el último día procesado de cada colaborador.
  const [corteAsistencia, setCorteAsistencia] = useState(null);
  const [busqueda, setBusqueda] = useState('');
  const [exportando, setExportando] = useState(false);
  const [pdfModalOpen, setPdfModalOpen] = useState(false);

  useEffect(() => {
    fetchEmpresas();
  }, [fetchEmpresas]);

  const parametros = useMemo(() => ({
    fecha_desde: rango[0].format('YYYY-MM-DD'),
    fecha_hasta: rango[1].format('YYYY-MM-DD'),
    empresa_id: empresaId === TODAS ? undefined : empresaId,
    fecha_corte_asistencia: corteAsistencia ? corteAsistencia.format('YYYY-MM-DD') : undefined,
  }), [rango, empresaId, corteAsistencia]);

  useEffect(() => {
    fetchPrevisualizacion(parametros).catch((err) => {
      message.error(err.response?.data?.message ?? 'No se pudo calcular el proyectado de planilla');
    });
  }, [parametros, fetchPrevisualizacion, message]);

  const filasFiltradas = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    const filas = previsualizacion?.filas ?? [];
    if (!texto) return filas;
    return filas.filter((fila) => fila.nombre?.toLowerCase().includes(texto) || fila.cargo?.toLowerCase().includes(texto));
  }, [previsualizacion, busqueda]);

  const empresasConFilas = useMemo(() => {
    const ids = new Set(filasFiltradas.map((fila) => fila.empresa_id));
    return (previsualizacion?.empresas ?? []).filter((empresa) => ids.has(empresa.empresa_id));
  }, [previsualizacion, filasFiltradas]);

  const handleExportar = async () => {
    setExportando(true);
    try {
      const archivo = await exportarPrevisualizacionExcel(parametros);
      modal.success({
        title: 'Exportación exitosa',
        width: 520,
        content: <ResumenExportacion datos={previsualizacion} archivo={archivo} />,
      });
    } catch {
      message.error('No se pudo generar el Excel del proyectado de planilla');
    } finally {
      setExportando(false);
    }
  };

  const totales = previsualizacion?.totales;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <DatePicker.RangePicker
          format="DD/MM/YYYY"
          allowClear={false}
          value={rango}
          onChange={(valores) => {
            if (!valores) return;
            setRango(valores);
            // El corte siempre debe caer dentro del rango.
            if (corteAsistencia && (corteAsistencia.isBefore(valores[0], 'day') || corteAsistencia.isAfter(valores[1], 'day'))) {
              setCorteAsistencia(null);
            }
          }}
          disabledDate={(fecha, { from }) => Boolean(from) && Math.abs(fecha.diff(from, 'day')) >= MAX_DIAS_RANGO}
        />
        <Tooltip title="Hasta este día se cuentan faltas, tardanzas y demás incidencias reales; desde el día siguiente se proyecta con el sueldo. Vacío: el último día con asistencia procesada de cada colaborador.">
          <DatePicker
            format="DD/MM/YYYY"
            placeholder="Corte de asistencia: automático"
            className="w-60"
            value={corteAsistencia}
            onChange={setCorteAsistencia}
            disabledDate={(fecha) => fecha.isBefore(rango[0], 'day') || fecha.isAfter(rango[1], 'day')}
          />
        </Tooltip>
        <Select
          className="w-56"
          showSearch
          optionFilterProp="label"
          value={empresaId}
          onChange={setEmpresaId}
          options={[
            { value: TODAS, label: 'Todas las empresas' },
            ...empresas.filter((empresa) => empresa.activa).map((empresa) => ({ value: empresa.id, label: empresa.nombre_comercial })),
          ]}
        />
        <Input.Search
          allowClear
          placeholder="Buscar colaborador por nombre o cargo"
          className="w-72"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
        />
        <Button
          icon={<FileExcelOutlined />}
          loading={exportando}
          disabled={previsualizacionLoading || !previsualizacion?.filas?.length}
          onClick={handleExportar}
        >
          Exportar Excel
        </Button>
        <Button
          icon={<FilePdfOutlined />}
          disabled={previsualizacionLoading || !previsualizacion?.filas?.length}
          onClick={() => setPdfModalOpen(true)}
        >
          PDF
        </Button>
      </div>
      <p className="text-xs text-gray-400">
        Proyección del rango ({previsualizacion?.dias ?? '—'} días de 30) — no crea ni modifica ningún ciclo. Hasta el corte de asistencia
        {corteAsistencia ? ` (${corteAsistencia.format('DD/MM/YYYY')})` : ' (automático: último día procesado de cada colaborador)'} cuenta
        faltas, tardanzas y demás incidencias reales; desde el día siguiente proyecta con el sueldo (sueldo/30 × días). Los bonos y descuentos
        registrados en un ciclo se incluyen solo si el rango cubre el ciclo completo.
      </p>

      <Table
        rowKey="empresa_id"
        loading={previsualizacionLoading}
        dataSource={empresasConFilas}
        columns={COLUMNAS_EMPRESA}
        pagination={false}
        scroll={{ x: 800 }}
        locale={{ emptyText: 'No hay colaboradores elegibles para este rango' }}
        expandable={{
          defaultExpandAllRows: empresaId !== TODAS,
          expandedRowRender: (empresa) => (
            <Table
              rowKey="colaborador_id"
              size="small"
              dataSource={filasFiltradas.filter((fila) => fila.empresa_id === empresa.empresa_id)}
              columns={COLUMNAS_COLABORADOR}
              pagination={false}
              scroll={{ x: 1300 }}
            />
          ),
        }}
        summary={() => (totales && empresasConFilas.length > 1 ? (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0} />
            <Table.Summary.Cell index={1}><strong>Totales ({totales.empresas} empresas)</strong></Table.Summary.Cell>
            <Table.Summary.Cell index={2} align="right">{totales.colaboradores}</Table.Summary.Cell>
            <Table.Summary.Cell index={3} align="right">{soles(totales.total_ingresos)}</Table.Summary.Cell>
            <Table.Summary.Cell index={4} align="right">{soles(totales.total_egresos)}</Table.Summary.Cell>
            <Table.Summary.Cell index={5} align="right">{soles(totales.total_aportaciones)}</Table.Summary.Cell>
            <Table.Summary.Cell index={6} align="right"><strong>{soles(totales.neto_a_pagar)}</strong></Table.Summary.Cell>
          </Table.Summary.Row>
        ) : null)}
      />

      <ReporteEjecutivoImprimibleModal
        open={pdfModalOpen}
        onCancel={() => setPdfModalOpen(false)}
        titulo="Proyectado de planilla"
        parametros={parametros}
        cargarDatos={fetchPrevisualizacionDatosReporte}
      />
    </div>
  );
}
