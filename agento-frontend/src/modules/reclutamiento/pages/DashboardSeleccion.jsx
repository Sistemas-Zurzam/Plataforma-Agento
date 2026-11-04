import { CheckOutlined, ClockCircleOutlined, FileSearchOutlined, TeamOutlined, WarningOutlined } from '@ant-design/icons';
import { App, Button, Card, DatePicker, List, Progress, Select, Table, Tag, Tooltip } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import EvaluarEntrevistaModal from '../components/EvaluarEntrevistaModal';
import { ETAPAS, ETIQUETA_ETAPA, FUENTES, TIPOS_ENTREVISTA, etiquetaDe, mensajeDeError, tiene } from '../constants/seleccion';
import {
  useDashboardSeleccion, useEntrevistasPropias, useReferenciasSolicitud, useTareasSeleccion, useUsuariosSeleccion,
} from '../hooks/useSeleccion';

function Indicador({ icono, valor, etiqueta, tono = 'bg-agento-blue-light text-agento-blue', ayuda }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-lg ${tono}`}>{icono}</span>
      <div className="min-w-0">
        <p className="text-lg font-semibold text-gray-900">{valor}</p>
        <p className="text-xs text-gray-500">
          {etiqueta}
          {ayuda && <Tooltip title={ayuda}><span className="ml-1 cursor-help text-gray-400">ⓘ</span></Tooltip>}
        </p>
      </div>
    </div>
  );
}

function Indicadores({ user, onAbrirProceso }) {
  const [rango, setRango] = useState([dayjs().subtract(3, 'month'), dayjs()]);
  const [areaId, setAreaId] = useState();
  const [reclutadorId, setReclutadorId] = useState();
  const filtros = useMemo(() => ({
    desde: rango?.[0]?.format('YYYY-MM-DD'),
    hasta: rango?.[1]?.format('YYYY-MM-DD'),
    area_id: areaId,
    reclutador_id: reclutadorId,
  }), [rango, areaId, reclutadorId]);
  const { datos, cargando, error, recargar } = useDashboardSeleccion(filtros);
  const referencias = useReferenciasSolicitud(true);
  const usuarios = useUsuariosSeleccion(tiene(user, 'seleccion.vacantes.gestionar', 'seleccion.solicitudes.decidir'));

  const maxEtapa = Math.max(1, ...Object.values(datos?.candidatos_por_etapa ?? { x: 0 }));
  const totalFuentes = Object.values(datos?.fuentes ?? {}).reduce((a, b) => a + b, 0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <DatePicker.RangePicker value={rango} onChange={setRango} format="DD/MM/YYYY" allowClear={false} aria-label="Período" />
        <Select
          allowClear
          placeholder="Área"
          className="min-w-44"
          value={areaId}
          onChange={setAreaId}
          options={(referencias.datos?.areas ?? []).map((a) => ({ value: a.id, label: a.nombre }))}
        />
        {usuarios.datos && (
          <Select
            allowClear
            placeholder="Reclutador"
            className="min-w-44"
            value={reclutadorId}
            onChange={setReclutadorId}
            options={usuarios.datos.map((u) => ({ value: u.id, label: u.nombre }))}
          />
        )}
      </div>

      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        {datos && (
          <>
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
              <Indicador icono={<FileSearchOutlined />} valor={datos.procesos_abiertos} etiqueta="Procesos abiertos" ayuda="Abiertos o en pausa al momento de consultar." />
              <Indicador icono={<TeamOutlined />} valor={datos.plazas.solicitadas} etiqueta="Plazas solicitadas" ayuda="Plazas de procesos creados en el período." />
              <Indicador icono={<CheckOutlined />} tono="bg-green-50 text-green-600" valor={datos.plazas.cubiertas} etiqueta="Plazas cubiertas" />
              <Indicador icono={<ClockCircleOutlined />} tono="bg-orange-50 text-orange-600" valor={datos.plazas.pendientes} etiqueta="Plazas pendientes" />
              <Indicador
                icono={<ClockCircleOutlined />}
                valor={datos.tiempo_promedio_cobertura_dias ?? 'Sin datos suficientes'}
                etiqueta="Días promedio de cobertura"
                ayuda="Promedio de (fecha de cobertura de la plaza − creación del proceso) para plazas cubiertas en el período."
              />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Card size="small" title="Candidatos vigentes por etapa">
                <div className="space-y-2">
                  {ETAPAS.map((etapa) => (
                    <div key={etapa.value} className="flex items-center gap-3">
                      <span className="w-40 shrink-0 text-sm text-gray-600">{etapa.label}</span>
                      <Progress percent={((datos.candidatos_por_etapa[etapa.value] ?? 0) / maxEtapa) * 100} showInfo={false} className="mb-0!" />
                      <span className="w-8 text-right text-sm font-medium">{datos.candidatos_por_etapa[etapa.value] ?? 0}</span>
                    </div>
                  ))}
                </div>
              </Card>
              <Card size="small" title="Fuentes de candidatos (postulaciones del período)">
                {totalFuentes === 0 ? <p className="text-sm text-gray-500">Sin datos suficientes</p> : (
                  <div className="space-y-2">
                    {Object.entries(datos.fuentes).map(([fuente, total]) => (
                      <div key={fuente} className="flex items-center justify-between text-sm">
                        <span className="text-gray-600">{FUENTES[fuente] ?? fuente}</span>
                        <span className="font-medium">{total} ({Math.round((total / totalFuentes) * 100)}%)</span>
                      </div>
                    ))}
                  </div>
                )}
              </Card>
              <Card size="small" title="Rotación temprana de contratados por Selección">
                {datos.rotacion_temprana.disponible
                  ? <p className="text-sm">{datos.rotacion_temprana.cesados} de {datos.rotacion_temprana.contratados} cesados en Personas ({datos.rotacion_temprana.porcentaje}%)</p>
                  : <p className="text-sm text-gray-500">{datos.rotacion_temprana.mensaje}</p>}
              </Card>
              <Card size="small" title="Costo de contratación">
                <p className="text-sm text-gray-500">{datos.costo_contratacion.mensaje}</p>
              </Card>
            </div>

            <Card size="small" title={<span><WarningOutlined className="mr-2 text-orange-600" />Procesos atrasados según fecha requerida</span>}>
              <Table
                size="small"
                rowKey="id"
                pagination={false}
                dataSource={datos.procesos_atrasados}
                locale={{ emptyText: 'No hay procesos atrasados' }}
                onRow={(fila) => ({ onClick: () => onAbrirProceso(fila.id), className: 'cursor-pointer' })}
                scroll={{ x: 600 }}
                columns={[
                  { title: 'Código', dataIndex: 'codigo' },
                  { title: 'Cargo', dataIndex: 'cargo' },
                  { title: 'Área', render: (_, f) => f.area?.nombre },
                  { title: 'Fecha requerida', dataIndex: 'fecha_requerida', render: (v) => dayjs(v).format('DD/MM/YYYY') },
                  { title: 'Plazas pendientes', dataIndex: 'plazas_pendientes' },
                ]}
              />
            </Card>
          </>
        )}
      </EstadoSeccion>
    </div>
  );
}

function Tareas() {
  const { message } = App.useApp();
  const { datos, cargando, error, recargar, completar } = useTareasSeleccion('pendiente');

  return (
    <Card size="small" title="Tareas de seguimiento (Nóminas / RR. HH.)">
      <EstadoSeccion cargando={cargando} error={error} vacio={datos?.data?.length === 0} mensajeVacio="No hay tareas pendientes">
        <List
          dataSource={datos?.data ?? []}
          renderItem={(tarea) => (
            <List.Item
              actions={[
                <Button
                  key="completar"
                  size="small"
                  onClick={async () => {
                    try {
                      await completar(tarea.id);
                      message.success('Tarea completada.');
                      recargar();
                    } catch (e) {
                      message.error(mensajeDeError(e));
                    }
                  }}
                >
                  Marcar completada
                </Button>,
              ]}
            >
              <List.Item.Meta
                title={<span>{tarea.titulo} <Tag>{tarea.tipo.replace('_', ' ')}</Tag></span>}
                description={`${tarea.detalle ?? ''}${tarea.colaborador ? ` · Legajo ${tarea.colaborador.legajo}` : ''}`}
              />
            </List.Item>
          )}
        />
      </EstadoSeccion>
    </Card>
  );
}

function MisEntrevistas() {
  const { datos, cargando, error, recargar, evaluar } = useEntrevistasPropias();
  const [evaluando, setEvaluando] = useState(null);

  if (!cargando && !error && (datos ?? []).length === 0) {
    return null;
  }

  return (
    <Card size="small" title="Entrevistas asignadas a ti">
      <EstadoSeccion cargando={cargando} error={error}>
        <List
          dataSource={datos ?? []}
          renderItem={(e) => (
            <List.Item actions={[<Button key="evaluar" size="small" type="primary" onClick={() => setEvaluando(e)}>Evaluar</Button>]}>
              <List.Item.Meta
                title={`${e.candidato?.nombre ?? 'Candidato'} · ${e.candidato?.proceso ?? ''}`}
                description={`${etiquetaDe(TIPOS_ENTREVISTA, e.tipo)}${e.programada_at ? ` · ${dayjs(e.programada_at).format('DD/MM/YYYY HH:mm')}` : ''}`}
              />
            </List.Item>
          )}
        />
      </EstadoSeccion>
      <EvaluarEntrevistaModal
        entrevista={evaluando}
        onEvaluar={evaluar}
        onCerrar={(guardado) => {
          setEvaluando(null);
          if (guardado) recargar();
        }}
      />
    </Card>
  );
}

export default function DashboardSeleccion({ user, puedeVerIndicadores, onAbrirProceso }) {
  const veTareas = tiene(user, 'seleccion.vacantes.gestionar', 'seleccion.contratar', 'colaboradores.editar');

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Selección y reclutamiento</h2>
        <p className="text-sm text-gray-500">Seguimiento de solicitudes, procesos y plazas de la empresa activa.</p>
      </div>
      <MisEntrevistas />
      {puedeVerIndicadores && <Indicadores user={user} onAbrirProceso={onAbrirProceso} />}
      {veTareas && <Tareas />}
      {!puedeVerIndicadores && !veTareas && (
        <p className="text-sm text-gray-500">Usa el menú para revisar tus solicitudes de personal.</p>
      )}
      <p className="text-xs text-gray-400">Etapas: {Object.values(ETIQUETA_ETAPA).join(' → ')}</p>
    </div>
  );
}
