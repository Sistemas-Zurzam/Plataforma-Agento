import { BankOutlined, CalculatorOutlined, CheckCircleOutlined, ClockCircleOutlined, GiftOutlined, TeamOutlined } from '@ant-design/icons';
import { App, Button, Empty, Input, Select, Table, Tag, Tooltip } from 'antd';
import { useEffect, useState } from 'react';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

const PERIODOS = [
  { key: 'gratificacion_julio', titulo: 'Gratificación Julio', subtitulo: 'Enero – Junio', icon: <GiftOutlined /> },
  { key: 'gratificacion_diciembre', titulo: 'Gratificación Diciembre', subtitulo: 'Julio – Diciembre', icon: <GiftOutlined /> },
  { key: 'cts_mayo', titulo: 'CTS Mayo', subtitulo: 'Noviembre (año anterior) – Abril', icon: <BankOutlined /> },
  { key: 'cts_noviembre', titulo: 'CTS Noviembre', subtitulo: 'Mayo – Octubre', icon: <BankOutlined /> },
];

const ESTADO_COLOR = { sin_calcular: 'default', calculado: 'gold', pagado: 'green' };
const ESTADO_LABEL = { sin_calcular: 'Sin calcular', calculado: 'Calculado', pagado: 'Pagado' };

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function TarjetaStat({ icono, valor, etiqueta, color }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-lg ${color}`}>{icono}</span>
      <div className="min-w-0">
        <p className="truncate text-lg font-semibold text-gray-900">{valor}</p>
        <p className="truncate text-xs text-gray-500">{etiqueta}</p>
      </div>
    </div>
  );
}

/**
 * Consume el mismo catálogo/motor que Planilla Mensual — nunca recalcula:
 * suma las líneas GRATIFICACION_LEGAL/BONIFICACION_EXTRAORDINARIA/
 * CTS_PROVISION ya persistidas en boleta_conceptos para el rango de meses
 * del período elegido. "Calcular" congela esa suma en un snapshot
 * versionado (igual que una Boleta) para que quede reproducible aunque las
 * boletas mensuales se recalculen después.
 */
export default function CtsGratificacionesTab({ fetchResumenBeneficio, calcularBeneficio, pagarBeneficio, resumen, loading, puedeCalcular, puedePagar }) {
  const { message, modal } = App.useApp();
  const [periodo, setPeriodo] = useState('gratificacion_julio');
  const [anio, setAnio] = useState(new Date().getFullYear());
  const [calculando, setCalculando] = useState(false);

  useEffect(() => {
    fetchResumenBeneficio(periodo, anio);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodo, anio]);

  const periodoActivo = PERIODOS.find((p) => p.key === periodo);
  const esGratificacion = periodo.startsWith('gratificacion');

  const handleCalcular = () => {
    modal.confirm({
      title: `Calcular ${periodoActivo?.titulo} ${anio}`,
      content: 'Se congelará un snapshot con los montos ya provisionados en las boletas mensuales de este período. Si vuelves a calcular más adelante, se crea una nueva versión — la anterior queda en el historial.',
      okText: 'Calcular',
      cancelText: 'Cancelar',
      onOk: async () => {
        setCalculando(true);
        try {
          await calcularBeneficio(periodo, anio);
          message.success('Cálculo generado correctamente');
        } catch (err) {
          message.error(err.response?.data?.errors ? Object.values(err.response.data.errors)[0][0] : 'No se pudo calcular');
        } finally {
          setCalculando(false);
        }
      },
    });
  };

  const handlePagar = () => {
    let referencia = '';
    modal.confirm({
      title: 'Marcar como pagado',
      content: (
        <div className="mt-2">
          <p className="mb-2 text-xs text-gray-500">Ingresa una referencia de pago real (N.º de operación, lote bancario, constancia).</p>
          <Input placeholder="Ej. OP-2026-0715-001" onChange={(e) => { referencia = e.target.value; }} />
        </div>
      ),
      okText: 'Marcar como pagado',
      cancelText: 'Cancelar',
      onOk: async () => {
        if (!referencia.trim()) {
          message.error('La referencia de pago es obligatoria');
          return Promise.reject();
        }
        try {
          await pagarBeneficio(resumen.id, referencia.trim());
          message.success('Marcado como pagado');
        } catch (err) {
          message.error('No se pudo marcar como pagado');
        }
      },
    });
  };

  const columnas = [
    {
      title: 'Colaborador',
      key: 'colaborador',
      render: (_, fila) => (
        <div className="flex items-center gap-3">
          <span
            className="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold text-white"
            style={{ backgroundColor: colorForName(fila.colaborador) }}
          >
            {initialsForName(fila.colaborador)}
          </span>
          <div className="min-w-0">
            <p className="truncate font-medium text-gray-900">{fila.colaborador}</p>
            <p className="truncate text-xs text-gray-400">{fila.legajo}</p>
          </div>
        </div>
      ),
    },
    { title: 'Empresa', dataIndex: 'empresa' },
    { title: 'Sueldo base', dataIndex: 'sueldo_basico', render: soles },
    { title: 'Meses', dataIndex: 'meses', align: 'center' },
    { title: esGratificacion ? 'Grat. bruta' : 'CTS bruta', dataIndex: 'bruta', render: soles },
    ...(esGratificacion ? [{ title: 'Bonif. extraordinaria', dataIndex: 'bonificacion_extraordinaria', render: (v) => <span className="text-blue-500">{soles(v)}</span> }] : []),
    { title: esGratificacion ? 'Grat. neta' : 'CTS neta', dataIndex: 'neta', render: (v) => <span className="font-semibold text-green-600">{soles(v)}</span> },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (estado) => <Tag color={ESTADO_COLOR[estado] ?? 'default'}>{ESTADO_LABEL[estado] ?? estado}</Tag>,
    },
  ];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {PERIODOS.map((p) => (
          <button
            key={p.key}
            type="button"
            onClick={() => setPeriodo(p.key)}
            className={`flex items-start gap-3 rounded-2xl border p-4 text-left transition ${
              periodo === p.key ? 'border-agento-blue bg-agento-blue-light/40' : 'border-gray-100 bg-white hover:border-gray-200'
            }`}
          >
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${periodo === p.key ? 'bg-agento-blue text-white' : 'bg-gray-100 text-gray-500'}`}>
              {p.icon}
            </span>
            <div className="min-w-0">
              <p className="truncate font-semibold text-gray-900">{p.titulo}</p>
              <p className="truncate text-xs text-gray-400">{p.subtitulo}</p>
              {resumen && periodo === p.key && <p className="text-xs text-gray-400">Vence: {resumen.vence}</p>}
            </div>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-500">Año:</span>
          <Select
            size="small"
            className="w-28"
            value={anio}
            onChange={setAnio}
            options={[anio - 1, anio, anio + 1].map((a) => ({ value: a, label: a }))}
          />
          {resumen && (
            <Tag color={ESTADO_COLOR[resumen.calculado ? resumen.estado : 'sin_calcular']}>
              {resumen.calculado ? `${ESTADO_LABEL[resumen.estado]} · versión ${resumen.version}` : 'Vista previa — todavía no calculado'}
            </Tag>
          )}
        </div>
        <div className="flex items-center gap-2">
          {puedeCalcular && (
            <Tooltip title={resumen?.total_colaboradores === 0 ? 'No hay boletas calculadas en este período' : undefined}>
              <Button type="primary" icon={<CalculatorOutlined />} loading={calculando} disabled={!resumen || resumen.total_colaboradores === 0} onClick={handleCalcular}>
                {resumen?.calculado ? `Recalcular ${esGratificacion ? 'gratificación' : 'CTS'}` : `Calcular ${esGratificacion ? 'gratificación' : 'CTS'}`}
              </Button>
            </Tooltip>
          )}
          {puedePagar && resumen?.calculado && resumen.estado === 'calculado' && (
            <Button icon={<BankOutlined />} onClick={handlePagar}>Marcar como pagado</Button>
          )}
        </div>
      </div>

      {resumen && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <TarjetaStat icono={<TeamOutlined />} valor={resumen.total_colaboradores} etiqueta="Colaboradores" color="bg-agento-blue-light text-agento-blue" />
          <TarjetaStat icono={<GiftOutlined />} valor={soles(resumen.total_neto)} etiqueta={`Total ${esGratificacion ? 'gratificaciones' : 'CTS'}`} color="bg-green-50 text-green-600" />
          <TarjetaStat icono={<CheckCircleOutlined />} valor={resumen.total_colaboradores - resumen.pendientes_de_pago} etiqueta="Pagados" color="bg-blue-50 text-blue-600" />
          <TarjetaStat icono={<ClockCircleOutlined />} valor={resumen.pendientes_de_pago} etiqueta="Pendientes" color="bg-orange-50 text-orange-500" />
        </div>
      )}

      <p className="text-sm font-semibold text-gray-700">{periodoActivo?.titulo} {anio}</p>

      <Table
        rowKey="colaborador_id"
        loading={loading}
        dataSource={resumen?.colaboradores ?? []}
        columns={columnas}
        scroll={{ x: 900 }}
        pagination={false}
        locale={{ emptyText: <Empty description="Sin boletas calculadas en este período" /> }}
      />
    </div>
  );
}
