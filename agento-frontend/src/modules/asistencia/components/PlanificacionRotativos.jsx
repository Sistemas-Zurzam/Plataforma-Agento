import { LeftOutlined, RightOutlined, WarningOutlined } from '@ant-design/icons';
import { Alert, App, Button, Card, Checkbox, Dropdown, Empty, Input, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import 'dayjs/locale/es';
import { useEffect, useMemo, useState } from 'react';
import api from '../../../services/api';

dayjs.locale('es');

const { Text } = Typography;
const { Group: CheckboxGroup } = Checkbox;

const TIPOS_PLANIFICABLES = [
  { value: 'laborable_presencial', label: 'Trabajo presencial', abrev: 'L', color: 'blue' },
  { value: 'home_office', label: 'Home office', abrev: 'TR', color: 'cyan' },
  { value: 'descanso', label: 'Descanso', abrev: 'D', color: 'green' },
];

const ESTADOS_SEMANA = {
  ok: { label: 'Cuadrado', color: 'green' },
  descuadre: { label: 'Descansos no cuadran', color: 'gold' },
  incompleto: { label: 'Días sin planificar', color: 'red' },
  sin_requisito_declarado: { label: 'Sin requisito declarado', color: 'default' },
};

const NO_EDITABLES = ['feriado', 'fuera_de_periodo'];

function celda(tipo) {
  if (!tipo) return { abrev: '—', color: 'default' };
  if (tipo === 'feriado') return { abrev: 'F', color: 'purple' };
  if (tipo === 'fuera_de_periodo') return { abrev: '', color: 'default' };
  return TIPOS_PLANIFICABLES.find((t) => t.value === tipo) ?? { abrev: '?', color: 'default' };
}

/**
 * Horarios Rotativos Fase 2 — vista semanal de planificación + validación de
 * descansos. Consume /asistencia/planificacion (GET) y
 * /asistencia/planificacion(/masivo) (PUT) — ambos reutilizan
 * colaborador_calendario_dias, ninguna tabla nueva. Es puramente
 * informativa: el "descuadre" de descansos nunca bloquea nada por sí solo,
 * a diferencia del "incompleto" (día sin planificar), que sí sigue
 * bloqueando el envío a Nómina — pero por la incidencia dia_sin_clasificar
 * de la Fase 1, no por esta pantalla.
 */
export default function PlanificacionRotativos({ puedeEditar, onAbrirIncidencia }) {
  const { message, modal } = App.useApp();
  const [semana, setSemana] = useState(() => dayjs().startOf('week'));
  const [busqueda, setBusqueda] = useState('');
  const [soloRotativos, setSoloRotativos] = useState(true);
  const [cargando, setCargando] = useState(false);
  const [datos, setDatos] = useState({ semana: null, colaboradores: [] });
  const [seleccionados, setSeleccionados] = useState([]);
  const [diasMasivo, setDiasMasivo] = useState([]);
  const [tipoMasivo, setTipoMasivo] = useState();
  const [guardando, setGuardando] = useState(false);

  const cargar = () => {
    setCargando(true);
    api.get('/asistencia/planificacion', {
      params: { semana: semana.format('YYYY-MM-DD'), busqueda: busqueda || undefined, solo_rotativos: soloRotativos },
    })
      .then(({ data }) => setDatos(data))
      .catch(() => message.error('No se pudo cargar la planificación de la semana'))
      .finally(() => setCargando(false));
  };

  useEffect(() => {
    cargar();
    setSeleccionados([]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [semana, soloRotativos]);

  useEffect(() => {
    const timeout = setTimeout(cargar, 400);
    return () => clearTimeout(timeout);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busqueda]);

  const fechasSemana = useMemo(() => {
    if (!datos.semana) return [];
    const inicio = dayjs(datos.semana.desde);
    return Array.from({ length: 7 }, (_, index) => inicio.add(index, 'day'));
  }, [datos.semana]);

  useEffect(() => {
    setDiasMasivo(fechasSemana.map((f) => f.format('YYYY-MM-DD')));
  }, [fechasSemana]);

  const planificarDia = async (colaboradorId, fecha, tipo) => {
    try {
      await api.put('/asistencia/planificacion', { colaborador_id: colaboradorId, fecha, tipo });
      message.success('Planificación actualizada');
      cargar();
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo actualizar la planificación');
    }
  };

  const aplicarMasivo = () => {
    if (seleccionados.length === 0 || diasMasivo.length === 0 || !tipoMasivo) return;
    const etiquetaTipo = TIPOS_PLANIFICABLES.find((t) => t.value === tipoMasivo)?.label ?? tipoMasivo;
    modal.confirm({
      title: 'Aplicar planificación masiva',
      content: `Se marcará "${etiquetaTipo}" para ${seleccionados.length} colaborador(es) en ${diasMasivo.length} día(s) seleccionados. ¿Continuar?`,
      okText: 'Aplicar', cancelText: 'Cancelar',
      onOk: async () => {
        setGuardando(true);
        try {
          const { data } = await api.put('/asistencia/planificacion/masivo', {
            colaborador_ids: seleccionados, fechas: diasMasivo, tipo: tipoMasivo,
          });
          message.success(`Planificación aplicada a ${data.procesadas} combinación(es)`);
          setSeleccionados([]);
          setTipoMasivo(undefined);
          cargar();
        } catch (err) {
          message.error(err.response?.data?.message ?? 'No se pudo aplicar la planificación masiva');
        } finally {
          setGuardando(false);
        }
      },
    });
  };

  const columnas = [
    {
      title: 'Colaborador', fixed: 'left', width: 220,
      render: (_, row) => <div><div className="font-medium">{row.nombre_completo}</div><Text type="secondary" className="text-xs">{row.legajo} · {row.horario ?? 'Sin horario'}</Text></div>,
    },
    ...fechasSemana.map((fecha, index) => ({
      title: <div className="text-center text-[11px] capitalize leading-3">{fecha.format('dd').slice(0, 2)}<br />{fecha.format('DD/MM')}</div>,
      key: fecha.format('YYYY-MM-DD'),
      width: 62,
      align: 'center',
      render: (_, row) => {
        const dia = row.dias[index];
        const info = celda(dia.tipo);
        const editable = puedeEditar && !NO_EDITABLES.includes(dia.tipo);
        const tagBase = (
          <Tag
            className={`!m-0 min-w-8 text-center ${editable ? 'cursor-pointer' : ''}`}
            color={info.color}
            title={dia.trabajo_en_descanso ? 'Descanso planificado, pero con marcaciones ese día' : undefined}
          >
            {info.abrev}{dia.trabajo_en_descanso ? ' ⚠' : ''}
          </Tag>
        );
        // Fase 3.1 — un día con trabajo_en_descanso pendiente no se edita
        // aquí directamente (el endpoint genérico lo bloquea, Sección 22):
        // el menú solo ofrece saltar a resolver la incidencia especializada.
        if (dia.incidencia_trabajo_en_descanso_id) {
          return (
            <Dropdown
              trigger={['click']}
              menu={{
                items: [{ key: 'ir', label: 'Resolver "Trabajo en descanso"' }],
                onClick: () => onAbrirIncidencia?.(dia.incidencia_trabajo_en_descanso_id, row.colaborador_id),
              }}
            >
              {tagBase}
            </Dropdown>
          );
        }
        if (!editable) return tagBase;
        return (
          <Dropdown
            trigger={['click']}
            menu={{
              items: [
                ...TIPOS_PLANIFICABLES.map((t) => ({ key: t.value, label: t.label })),
                { type: 'divider' },
                { key: 'quitar', label: 'Quitar planificación', danger: true },
              ],
              onClick: ({ key }) => planificarDia(row.colaborador_id, dia.fecha, key === 'quitar' ? null : key),
            }}
          >
            {tagBase}
          </Dropdown>
        );
      },
    })),
    {
      title: 'Descansos', width: 130, align: 'center',
      render: (_, row) => (
        <div>
          <span className={row.estado === 'descuadre' ? 'font-semibold text-amber-600' : ''}>
            {row.dias_descanso_planificados}{row.dias_descanso_requeridos !== null ? ` / ${row.dias_descanso_requeridos}` : ''}
          </span>
          {/* Fase 3.1 — gozados aparte de planificados: un descanso
              trabajado sigue "planificado" pero no se gozó realmente. */}
          <div className="text-[10px] text-gray-400">{row.dias_descanso_gozados} gozado(s)</div>
        </div>
      ),
    },
    {
      title: 'Estado', width: 150,
      render: (_, row) => <Tag color={ESTADOS_SEMANA[row.estado]?.color}>{ESTADOS_SEMANA[row.estado]?.label ?? row.estado}</Tag>,
    },
  ];

  return (
    <div className="space-y-3">
      <Alert
        type="info"
        showIcon
        message="Planificación de horarios rotativos"
        description="Aquí se declara cuáles días le tocan de descanso, trabajo presencial u home office a cada colaborador rotativo — nunca se infiere automáticamente. Un día sin marcar queda 'sin planificar' hasta que se procese, y ahí genera la incidencia de clasificación pendiente que sí bloquea el envío a Nómina."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Button.Group>
          <Button icon={<LeftOutlined />} onClick={() => setSemana((actual) => actual.subtract(1, 'week'))} />
          <Button disabled>{datos.semana ? `${dayjs(datos.semana.desde).format('DD/MM')} – ${dayjs(datos.semana.hasta).format('DD/MM/YYYY')}` : '—'}</Button>
          <Button icon={<RightOutlined />} onClick={() => setSemana((actual) => actual.add(1, 'week'))} />
        </Button.Group>
        <Button onClick={() => setSemana(dayjs().startOf('week'))}>Semana actual</Button>
        <Input.Search placeholder="Buscar colaborador o legajo" value={busqueda} onChange={(event) => setBusqueda(event.target.value)} allowClear className="w-64" />
        <Checkbox checked={soloRotativos} onChange={(event) => setSoloRotativos(event.target.checked)}>Solo rotativos</Checkbox>
      </div>

      {puedeEditar && seleccionados.length > 0 && (
        <Card size="small" className="border-blue-200 bg-blue-50">
          <Space direction="vertical" size={8} className="w-full">
            <Text strong>{seleccionados.length} colaborador(es) seleccionados</Text>
            <CheckboxGroup
              value={diasMasivo}
              onChange={setDiasMasivo}
              options={fechasSemana.map((f) => ({ label: f.format('dd DD/MM'), value: f.format('YYYY-MM-DD') }))}
            />
            <Space wrap>
              <Select
                placeholder="Marcar como..."
                className="w-48"
                value={tipoMasivo}
                options={TIPOS_PLANIFICABLES}
                onChange={setTipoMasivo}
                disabled={guardando}
              />
              <Button type="primary" disabled={!tipoMasivo || diasMasivo.length === 0} loading={guardando} onClick={aplicarMasivo}>
                Aplicar
              </Button>
            </Space>
          </Space>
        </Card>
      )}

      <Card styles={{ body: { padding: 0 } }}>
        <Table
          size="small"
          rowKey="colaborador_id"
          loading={cargando}
          dataSource={datos.colaboradores}
          columns={columnas}
          scroll={{ x: 900 }}
          rowSelection={puedeEditar ? { selectedRowKeys: seleccionados, onChange: setSeleccionados } : undefined}
          pagination={{ pageSize: 25, size: 'small' }}
          locale={{ emptyText: <Empty description="No hay colaboradores para los filtros seleccionados" /> }}
        />
      </Card>
      <Text type="secondary" className="flex items-center gap-1 text-xs"><WarningOutlined /> El icono de advertencia indica un descanso planificado que igual registró marcaciones ese día — solo informativo.</Text>
    </div>
  );
}
