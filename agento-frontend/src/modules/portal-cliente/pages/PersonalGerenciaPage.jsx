import { PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, Progress, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import SolicitudFormModal from '../../reclutamiento/components/SolicitudFormModal';
import { ESTADOS_PROCESO, ESTADOS_SOLICITUD, mensajeDeError } from '../../reclutamiento/constants/seleccion';
import { useReferenciasSolicitud } from '../../reclutamiento/hooks/useSeleccion';
import { seleccionApi } from '../../reclutamiento/services/seleccionApi';
import EstadoSolicitud from '../components/EstadoSolicitud';
import { formatoFecha, formatoFechaHora } from '../constants/estadosPortal';
import { useFiltrosUrl } from '../hooks/useFiltrosUrl';
import { usePersonalPortal } from '../hooks/usePortalGestion';

const { Text } = Typography;

const ETAPAS = [
  ['postulaciones', 'Postulaciones'],
  ['evaluaciones', 'Evaluaciones'],
  ['entrevista_virtual', 'Entrevista virtual'],
  ['entrevista_presencial', 'Entrevista presencial'],
  ['seleccion', 'Selección'],
  ['cubierto', 'Plazas cubiertas'],
];

function Seguimiento({ seguimiento }) {
  if (!seguimiento) return <Text type="secondary" className="text-xs">Sin proceso de selección todavía</Text>;
  const porcentaje = seguimiento.plazas_vigentes > 0 ? Math.round((seguimiento.plazas_cubiertas / seguimiento.plazas_vigentes) * 100) : 0;

  return (
    <div className="min-w-64 space-y-1">
      <div className="flex items-center gap-2 text-xs">
        <Tag color={ESTADOS_PROCESO[seguimiento.proceso.estado]?.color}>{ESTADOS_PROCESO[seguimiento.proceso.estado]?.label ?? seguimiento.proceso.estado}</Tag>
        <span>{seguimiento.proceso.codigo}</span>
      </div>
      <Progress percent={porcentaje} size="small" format={() => `${seguimiento.plazas_cubiertas}/${seguimiento.plazas_vigentes} plazas`} aria-label="Plazas cubiertas" />
      <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-600">
        {ETAPAS.map(([clave, etiqueta]) => <span key={clave}>{etiqueta}: <strong>{seguimiento.etapas[clave] ?? 0}</strong></span>)}
      </div>
    </div>
  );
}

export default function PersonalGerenciaPage({ puedeCrear }) {
  const { message } = App.useApp();
  const [filtros, setFiltros] = useFiltrosUrl({ estado: undefined, page: 1 });
  const [formulario, setFormulario] = useState({ abierto: false, solicitud: null });
  const [procesando, setProcesando] = useState(null);
  const { datos, cargando, error, recargar } = usePersonalPortal(filtros);
  const referencias = useReferenciasSolicitud(puedeCrear);

  const abrirEdicion = async (id) => {
    setProcesando(id);
    try {
      setFormulario({ abierto: true, solicitud: await seleccionApi.solicitudes.obtener(id) });
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setProcesando(null);
    }
  };

  const columnas = [
    { title: 'Puesto', render: (_, r) => <div><div className="font-medium">{r.cargo}{r.urgente && <Tag color="red" className="ml-2">Urgente</Tag>}</div><Text type="secondary" className="text-xs">{r.area} · {r.plazas} plaza(s) · requerida {formatoFecha(r.fecha_requerida)}</Text></div> },
    { title: 'Estado', width: 210, render: (_, r) => <EstadoSolicitud estado={r.estado} siguienteResponsable={r.siguiente_responsable} /> },
    { title: 'Seguimiento', render: (_, r) => <Seguimiento seguimiento={r.seguimiento} /> },
    {
      title: 'Respuesta de RR. HH.',
      render: (_, r) => (r.respuesta_rrhh ? <Text className="text-xs">{r.respuesta_rrhh}</Text> : <Text type="secondary" className="text-xs">{formatoFechaHora(r.actualizado_at)}</Text>),
    },
    {
      title: 'Acciones',
      width: 120,
      render: (_, r) => (r.editable && puedeCrear ? (
        <Button size="small" loading={procesando === r.id} onClick={() => abrirEdicion(r.id)}>
          {r.estado === 'devuelta' ? 'Corregir' : 'Editar'}
        </Button>
      ) : '—'),
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <Alert type="info" showIcon message="La entrevista con gerencia se coordina directamente con RR. HH. fuera del portal." description="Aquí ves el avance agregado de cada vacante; los datos y evaluaciones de los candidatos son reservados de RR. HH." />
      <Space wrap className="w-full justify-between">
        <Select
          allowClear
          placeholder="Estado"
          className="w-56"
          aria-label="Estado"
          value={filtros.estado}
          options={Object.entries(ESTADOS_SOLICITUD).map(([value, { label }]) => ({ value, label }))}
          onChange={(estado) => setFiltros({ estado, page: 1 })}
        />
        {puedeCrear && (
          <Tooltip title={referencias.error ? 'No se pudieron cargar las referencias del formulario' : undefined}>
            <Button type="primary" icon={<PlusOutlined />} disabled={!referencias.datos} loading={referencias.cargando} onClick={() => setFormulario({ abierto: true, solicitud: null })}>
              Nueva solicitud
            </Button>
          </Tooltip>
        )}
      </Space>
      {error ? (
        <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudieron cargar las solicitudes.')} action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      ) : (
        <Table
          rowKey="id"
          size="small"
          loading={cargando}
          dataSource={datos?.data ?? []}
          columns={columnas}
          scroll={{ x: 1100 }}
          locale={{ emptyText: 'No hay solicitudes de personal en tus áreas' }}
          pagination={{
            current: datos?.meta?.current_page ?? 1, pageSize: datos?.meta?.per_page ?? 15, total: datos?.meta?.total ?? 0,
            showSizeChanger: false, onChange: (page) => setFiltros({ page }),
          }}
        />
      )}
      {formulario.abierto && (
        <SolicitudFormModal
          abierto={formulario.abierto}
          solicitud={formulario.solicitud}
          referencias={referencias.datos}
          onCerrar={() => setFormulario({ abierto: false, solicitud: null })}
          onGuardar={async (valores) => {
            if (formulario.solicitud) {
              await seleccionApi.solicitudes.actualizar(formulario.solicitud.id, valores);
            } else {
              await seleccionApi.solicitudes.crear(valores);
            }
            message.success(valores.enviar ? 'Solicitud enviada a RR. HH.' : 'Borrador guardado.');
            setFormulario({ abierto: false, solicitud: null });
            recargar();
          }}
        />
      )}
    </div>
  );
}
