import { Spin, Table } from 'antd';
import { useEffect } from 'react';
import { formatParametroValor } from '../../../utils/formatNumber';
import { useParametrosLaborales } from '../hooks/useParametrosLaborales';
import { useTramosRenta } from '../hooks/useTramosRenta';

const CLAVES_4TA = ['renta_4ta_tasa', 'renta_4ta_umbral'];
const CLAVES_HORAS_EXTRA = [
  'horas_extra_tasa_x25',
  'horas_extra_tasa_x35',
  'horas_extra_tasa_nocturna',
  'bonificacion_extraordinaria_porcentaje',
];

/**
 * Tabla legal (SUNAT), no editable desde acá — a diferencia de Parámetros
 * Nacionales y AFP y Pensiones, esta pantalla es de solo lectura porque los
 * tramos progresivos de renta de 5ta no varían por empresa ni régimen.
 */
export default function TramosTributarios() {
  const { categorias, loading: cargandoTramos, fetchTramos } = useTramosRenta();
  const { regimenes, loading: cargandoParametros, fetchParametros } = useParametrosLaborales();

  useEffect(() => {
    fetchTramos();
    fetchParametros();
  }, [fetchTramos, fetchParametros]);

  const regimenReferencia = regimenes[0];
  const uit = Number(regimenReferencia?.parametros.find((p) => p.clave === 'uit')?.valor ?? 0);
  const parametros4ta = regimenReferencia?.parametros.filter((p) => CLAVES_4TA.includes(p.clave)) ?? [];
  const parametrosHorasExtra =
    regimenReferencia?.parametros.filter((p) => CLAVES_HORAS_EXTRA.includes(p.clave)) ?? [];

  const quinta = categorias?.quinta;

  const columns = [
    { title: 'Tramo', key: 'tramo', render: (_, tramo) => (
      tramo.limite_superior_uit === null
        ? `Exceso de ${tramo.limite_inferior_uit} UIT`
        : tramo.limite_inferior_uit === 0
          ? `Hasta ${tramo.limite_superior_uit} UIT`
          : `De ${tramo.limite_inferior_uit} a ${tramo.limite_superior_uit} UIT`
    ) },
    { title: 'Tasa', dataIndex: 'tasa_porcentaje', render: (tasa) => `${Number(tasa)}%` },
    {
      title: 'Hasta S/',
      key: 'hasta_soles',
      render: (_, tramo) =>
        tramo.limite_superior_uit === null || !uit
          ? '—'
          : formatParametroValor(tramo.limite_superior_uit * uit, 'S/'),
    },
  ];

  return (
    <Spin spinning={cargandoTramos || cargandoParametros}>
      <div className="flex flex-col gap-4">
        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <h3 className="mb-1 font-medium text-gray-900">Renta de Quinta Categoría</h3>
          <p className="mb-3 text-xs text-gray-500">
            {quinta?.vigencia_desde
              ? `Vigencia desde ${quinta.vigencia_desde}. UIT = ${formatParametroValor(uit, 'S/')}.`
              : 'No hay tramos configurados.'}
          </p>
          <Table
            rowKey="orden"
            size="small"
            pagination={false}
            columns={columns}
            dataSource={quinta?.tramos ?? []}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h3 className="mb-3 font-medium text-gray-900">Renta de Cuarta Categoría / RH</h3>
            <p className="mb-3 text-xs text-gray-500">
              Aplicable a contratos de locación de servicios (recibos por honorarios).
            </p>
            <div className="grid grid-cols-2 gap-3">
              {parametros4ta.map((parametro) => (
                <div key={parametro.definicion_id}>
                  <p className="text-xs text-gray-500">{parametro.nombre}</p>
                  <p className="text-base font-semibold text-gray-900">
                    {formatParametroValor(parametro.valor, parametro.unidad)}
                  </p>
                </div>
              ))}
            </div>
          </div>

          <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h3 className="mb-3 font-medium text-gray-900">Tasas de Horas Extra</h3>
            <div className="grid grid-cols-2 gap-3">
              {parametrosHorasExtra.map((parametro) => (
                <div key={parametro.definicion_id}>
                  <p className="text-xs text-gray-500">{parametro.nombre}</p>
                  <p className="text-base font-semibold text-gray-900">
                    {formatParametroValor(parametro.valor, parametro.unidad)}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </Spin>
  );
}
