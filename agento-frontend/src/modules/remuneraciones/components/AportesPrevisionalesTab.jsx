import { BankOutlined, SafetyCertificateOutlined, TeamOutlined, WalletOutlined } from '@ant-design/icons';
import { Empty, Segmented, Table, Tag } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

const ESTADO_COLOR = {
  calculada: 'blue',
  observada: 'orange',
  aprobada: 'geekblue',
  pagada: 'green',
  anulada: 'red',
};

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
 * Lee las líneas AFP_APORTE_OBLIGATORIO/AFP_PRIMA_SEGURO/AFP_COMISION/ONP
 * ya persistidas en boleta_conceptos (mismo motor que Planilla Mensual,
 * nunca recalcula) y las agrupa por colaborador. ONP no tiene prima ni
 * comisión — esos campos llegan en null y se muestran como "—" en vez de
 * S/ 0.00 para no insinuar que la AFP le cobró algo a un colaborador ONP.
 */
export default function AportesPrevisionalesTab({ cicloId, fetchAportesPrevisionales, resumen, loading }) {
  const [filtro, setFiltro] = useState('todos');

  useEffect(() => {
    if (cicloId) fetchAportesPrevisionales(cicloId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cicloId]);

  const colaboradores = resumen?.colaboradores ?? [];

  const totales = useMemo(() => {
    const afp = colaboradores.filter((c) => c.sistema_previsional !== 'onp');
    const onp = colaboradores.filter((c) => c.sistema_previsional === 'onp');
    const sumar = (lista) => lista.reduce((acc, c) => acc + Number(c.total ?? 0), 0);
    return {
      totalColaboradores: colaboradores.length,
      totalAfp: sumar(afp),
      totalOnp: sumar(onp),
      totalGeneral: sumar(colaboradores),
    };
  }, [colaboradores]);

  const dataFiltrada = useMemo(() => {
    if (filtro === 'afp') return colaboradores.filter((c) => c.sistema_previsional !== 'onp');
    if (filtro === 'onp') return colaboradores.filter((c) => c.sistema_previsional === 'onp');
    return colaboradores;
  }, [colaboradores, filtro]);

  const totalFiltrado = useMemo(
    () => dataFiltrada.reduce((acc, c) => acc + Number(c.total ?? 0), 0),
    [dataFiltrada],
  );

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
            <p className="truncate text-xs text-gray-400">{fila.cargo}</p>
          </div>
        </div>
      ),
    },
    { title: 'Empresa', dataIndex: 'empresa' },
    {
      title: 'Sistema previsional',
      key: 'sistema_previsional',
      render: (_, fila) => (fila.sistema_previsional === 'onp'
        ? <Tag color="purple">ONP</Tag>
        : <Tag color="blue">{fila.afp_nombre ?? fila.sistema_previsional}</Tag>),
    },
    { title: 'Remuneración asegurable', dataIndex: 'remuneracion_asegurable', render: soles },
    { title: 'Aporte obligatorio', dataIndex: 'aporte_obligatorio', render: soles },
    { title: 'Prima de seguro', dataIndex: 'prima_seguro', render: (v) => (v == null ? '—' : soles(v)) },
    { title: 'Comisión', dataIndex: 'comision', render: (v) => (v == null ? '—' : soles(v)) },
    { title: 'Total', dataIndex: 'total', render: (v) => <span className="font-semibold text-gray-900">{soles(v)}</span> },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (estado) => <Tag color={ESTADO_COLOR[estado] ?? 'default'}>{estado}</Tag>,
    },
  ];

  return (
    <div className="space-y-4">
      {resumen && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <TarjetaStat icono={<TeamOutlined />} valor={totales.totalColaboradores} etiqueta="Colaboradores" color="bg-agento-blue-light text-agento-blue" />
          <TarjetaStat icono={<BankOutlined />} valor={soles(totales.totalAfp)} etiqueta="Total AFP a pagar" color="bg-blue-50 text-blue-600" />
          <TarjetaStat icono={<SafetyCertificateOutlined />} valor={soles(totales.totalOnp)} etiqueta="Total ONP a pagar" color="bg-purple-50 text-purple-600" />
          <TarjetaStat icono={<WalletOutlined />} valor={soles(totales.totalGeneral)} etiqueta="Total aportes previsionales" color="bg-green-50 text-green-600" />
        </div>
      )}

      <div className="flex items-center justify-between gap-3">
        <Segmented
          value={filtro}
          onChange={setFiltro}
          options={[
            { label: 'Todos', value: 'todos' },
            { label: 'AFP', value: 'afp' },
            { label: 'ONP', value: 'onp' },
          ]}
        />
      </div>

      <Table
        rowKey="colaborador_id"
        loading={loading}
        dataSource={dataFiltrada}
        columns={columnas}
        scroll={{ x: 1100 }}
        pagination={false}
        locale={{ emptyText: <Empty description="Este ciclo todavía no tiene boletas calculadas" /> }}
        summary={() => (dataFiltrada.length === 0 ? null : (
          <Table.Summary fixed>
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={7}><strong>Total</strong></Table.Summary.Cell>
              <Table.Summary.Cell index={7}><strong>{soles(totalFiltrado)}</strong></Table.Summary.Cell>
              <Table.Summary.Cell index={8} />
            </Table.Summary.Row>
          </Table.Summary>
        ))}
      />
    </div>
  );
}
