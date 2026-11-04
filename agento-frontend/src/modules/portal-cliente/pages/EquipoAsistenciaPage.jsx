import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { Alert, Button, DatePicker, Descriptions, Empty, Input, Modal, Select, Table, Tabs, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { ESTADOS_ASISTENCIA, claveEstadoAsistencia } from '../../asistencia/constants/estadosAsistencia';
import { mensajeDeError } from '../../reclutamiento/constants/seleccion';
import { formatoFecha, formatoMinutos } from '../constants/estadosPortal';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { useEquipoMensual, useIncidenciasEquipo } from '../hooks/usePortalGestion';

const { Text } = Typography;
const hora = (valor) => (valor ? valor.slice(11, 16) : '—');

function CeldaDia({ resultado, fecha, onAbrir }) {
  // Sin resultado: el día aún no se procesó (o es futuro). Nunca se pinta
  // como falta: solo el motor de Asistencias decide eso.
  if (!resultado) {
    const futuro = dayjs(fecha).isAfter(dayjs(), 'day');
    return <span className="text-xs text-gray-400" title={futuro ? 'Día futuro' : 'Sin procesar'}>{futuro ? '·' : '—'}</span>;
  }
  const [codigo, etiqueta, color] = ESTADOS_ASISTENCIA[claveEstadoAsistencia(resultado)] ?? ESTADOS_ASISTENCIA.no_programado;

  return (
    <button
      type="button"
      onClick={onAbrir}
      className="cursor-pointer rounded border-0 bg-transparent p-0 focus-visible:outline-2 focus-visible:outline-agento-blue"
      aria-label={`${formatoFecha(fecha)}: ${etiqueta}`}
    >
      <Tag color={color} className="!m-0 min-w-7 text-center">{codigo}</Tag>
      {resultado.incidencias_pendientes > 0 && <span className="ml-0.5 text-[10px] font-semibold text-orange-600" title="Incidencias pendientes">!</span>}
    </button>
  );
}

function Leyenda() {
  const vistos = new Set();
  return (
    <div className="flex flex-wrap gap-2" aria-label="Leyenda de asistencia">
      {Object.entries(ESTADOS_ASISTENCIA).filter(([, [codigo, etiqueta]]) => {
        const clave = `${codigo}-${etiqueta}`;
        if (vistos.has(clave)) return false;
        vistos.add(clave);
        return true;
      }).map(([estado, [codigo, etiqueta, color]]) => (
        <span key={estado} className="flex items-center gap-1 text-xs text-gray-600"><Tag color={color} className="!m-0">{codigo}</Tag>{etiqueta}</span>
      ))}
      <span className="flex items-center gap-1 text-xs text-gray-600"><span className="font-semibold text-orange-600">!</span>Incidencia pendiente</span>
    </div>
  );
}

export default function EquipoAsistenciaPage({ areas }) {
  const [filtros, setFiltros] = useFiltrosUrl({ mes: dayjs().format('YYYY-MM'), area_id: undefined, busqueda: '', page: 1 });
  const [detalle, setDetalle] = useState(null);
  const [pestana, setPestana] = useState('calendario');
  const { datos, cargando, error, recargar } = useEquipoMensual(filtros);
  const mes = dayjs(`${filtros.mes}-01`);
  const incidencias = useIncidenciasEquipo({
    fecha_desde: mes.startOf('month').format('YYYY-MM-DD'),
    fecha_hasta: mes.endOf('month').format('YYYY-MM-DD'),
  }, pestana === 'incidencias');

  const cambiarMes = (delta) => setFiltros({ mes: mes.add(delta, 'month').format('YYYY-MM'), page: 1 });

  const columnas = [
    {
      title: 'Colaborador',
      fixed: 'left',
      width: 220,
      render: (_, fila) => (
        <div className="leading-tight">
          <div className="font-medium text-gray-900">{fila.nombre_completo}</div>
          <Text type="secondary" className="text-xs">{fila.legajo} · {fila.area}</Text>
        </div>
      ),
    },
    ...(datos?.dias ?? []).map((fecha) => ({
      title: <span className={[0, 6].includes(dayjs(fecha).day()) ? 'text-gray-400' : ''}>{dayjs(fecha).format('DD')}</span>,
      key: fecha,
      width: 46,
      align: 'center',
      render: (_, fila) => (
        <CeldaDia fecha={fecha} resultado={fila.resultados?.[fecha]} onAbrir={() => setDetalle({ fila, fecha, resultado: fila.resultados[fecha] })} />
      ),
    })),
  ];

  const filtrosBarra = (
    <div className="flex flex-wrap items-center gap-2">
      <Button icon={<LeftOutlined />} aria-label="Mes anterior" onClick={() => cambiarMes(-1)} />
      <DatePicker picker="month" allowClear={false} value={mes} format="MMMM YYYY" onChange={(valor) => valor && setFiltros({ mes: valor.format('YYYY-MM'), page: 1 })} aria-label="Mes" />
      <Button icon={<RightOutlined />} aria-label="Mes siguiente" onClick={() => cambiarMes(1)} />
      <Select
        allowClear
        placeholder="Todas mis áreas"
        className="w-56"
        aria-label="Filtrar por área"
        value={filtros.area_id ? Number(filtros.area_id) : undefined}
        options={areas.map((a) => ({ value: a.id, label: a.nombre }))}
        onChange={(valor) => setFiltros({ area_id: valor, page: 1 })}
      />
      <Input.Search
        allowClear
        className="max-w-xs"
        placeholder="Buscar por nombre o código"
        defaultValue={filtros.busqueda}
        onSearch={(valor) => setFiltros({ busqueda: valor, page: 1 })}
        aria-label="Buscar colaborador"
      />
    </div>
  );

  return (
    <div className="flex flex-col gap-4">
      <Alert type="info" showIcon message="Vista de solo lectura. Las correcciones de jornadas, marcaciones y justificaciones las realiza RR. HH." />
      {filtrosBarra}
      <Tabs
        activeKey={pestana}
        onChange={setPestana}
        items={[
          {
            key: 'calendario',
            label: 'Calendario mensual',
            children: (
              <div className="flex flex-col gap-3">
                <Leyenda />
                {error ? (
                  <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo cargar la asistencia del equipo.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
                ) : (
                  <Table
                    rowKey="id"
                    size="small"
                    bordered
                    loading={cargando}
                    dataSource={datos?.data ?? []}
                    columns={columnas}
                    scroll={{ x: 'max-content' }}
                    locale={{ emptyText: <Empty description="No hay colaboradores activos en tus áreas para este filtro" /> }}
                    pagination={{
                      current: datos?.meta?.current_page ?? 1,
                      pageSize: datos?.meta?.per_page ?? 15,
                      total: datos?.meta?.total ?? 0,
                      showSizeChanger: false,
                      onChange: (page) => setFiltros({ page }),
                    }}
                  />
                )}
              </div>
            ),
          },
          {
            key: 'incidencias',
            label: 'Incidencias del mes',
            children: incidencias.error ? (
              <Alert type="error" showIcon message={mensajeDeError(incidencias.error, 'No se pudieron cargar las incidencias.')} />
            ) : (
              <Table
                rowKey="id"
                size="small"
                loading={incidencias.cargando}
                dataSource={incidencias.datos?.data ?? []}
                locale={{ emptyText: <Empty description="Sin incidencias en el mes" /> }}
                columns={[
                  { title: 'Fecha', dataIndex: 'fecha', render: formatoFecha, width: 110 },
                  { title: 'Colaborador', render: (_, r) => r.colaborador?.nombre_completo },
                  { title: 'Tipo', dataIndex: 'tipo', render: (v) => v?.replaceAll('_', ' ') },
                  { title: 'Estado', dataIndex: 'estado', render: (v) => <Tag color={v === 'pendiente' ? 'gold' : v === 'resuelta' ? 'green' : 'red'}>{v}</Tag> },
                  { title: 'Descripción', dataIndex: 'descripcion', ellipsis: true },
                ]}
                pagination={{ pageSize: 50, hideOnSinglePage: true }}
                scroll={{ x: true }}
              />
            ),
          },
        ]}
      />

      <Modal open={!!detalle} onCancel={() => setDetalle(null)} footer={null} title={detalle ? `${detalle.fila.nombre_completo} · ${formatoFecha(detalle.fecha)}` : ''}>
        {detalle?.resultado && (
          <Descriptions column={1} size="small" bordered>
            <Descriptions.Item label="Estado">{(ESTADOS_ASISTENCIA[claveEstadoAsistencia(detalle.resultado)] ?? ESTADOS_ASISTENCIA.no_programado)[1]}</Descriptions.Item>
            <Descriptions.Item label="Tipo de día">{detalle.resultado.tipo_dia?.replaceAll('_', ' ') ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Entrada">{hora(detalle.resultado.entrada)}</Descriptions.Item>
            <Descriptions.Item label="Salida">{hora(detalle.resultado.salida)}</Descriptions.Item>
            <Descriptions.Item label="Tiempo trabajado">{formatoMinutos(detalle.resultado.minutos_trabajados)}</Descriptions.Item>
            <Descriptions.Item label="Tardanza">{detalle.resultado.minutos_tardanza ? `${detalle.resultado.minutos_tardanza} min` : '—'}</Descriptions.Item>
            <Descriptions.Item label="Incidencias pendientes">{detalle.resultado.incidencias_pendientes}</Descriptions.Item>
          </Descriptions>
        )}
      </Modal>
    </div>
  );
}
