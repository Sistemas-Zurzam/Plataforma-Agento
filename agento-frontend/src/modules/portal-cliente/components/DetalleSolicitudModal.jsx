import { Alert, Descriptions, Modal, Skeleton, Tag } from 'antd';
import { MODALIDADES, MOTIVOS_SOLICITUD, TIPOS_CONTRATO, etiquetaDe, mensajeDeError } from '../../reclutamiento/constants/seleccion';
import EstadoSolicitud from './EstadoSolicitud';
import { formatoFecha } from '../constants/estadosPortal';

const Texto = ({ children }) => <span className="whitespace-pre-line">{children}</span>;

/**
 * Detalle de solo lectura de una solicitud de personal tal como se envió a
 * RR. HH. (sin datos de candidatos ni condiciones comerciales).
 */
export default function DetalleSolicitudModal({ abierto, solicitud, cargando, error, onCerrar }) {
  const s = solicitud;
  const sueldo = s && (Number(s.salario_max) !== Number(s.salario_min) ? `S/ ${s.salario_min} – ${s.salario_max}` : `S/ ${s.salario_min}`);

  return (
    <Modal open={abierto} title={s ? s.cargo : 'Solicitud de personal'} onCancel={onCerrar} footer={null} width={760} centered destroyOnHidden
      styles={{ body: { maxHeight: 'calc(100vh - 200px)', overflowY: 'auto', overflowX: 'hidden' } }}
    >
      {cargando && <Skeleton active />}
      {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo cargar la solicitud.')} />}
      {s && !cargando && (
        <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered>
          <Descriptions.Item label="Estado" span={2}><EstadoSolicitud estado={s.estado} /></Descriptions.Item>
          <Descriptions.Item label="Área">{s.area?.nombre ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Plazas">{s.plazas}{s.urgente && <Tag color="red" className="ml-2">Urgente</Tag>}</Descriptions.Item>
          <Descriptions.Item label="Motivo">{etiquetaDe(MOTIVOS_SOLICITUD, s.motivo)}</Descriptions.Item>
          <Descriptions.Item label="Ingreso requerido">{formatoFecha(s.fecha_requerida)}</Descriptions.Item>
          {s.justificacion && <Descriptions.Item label="Detalle del motivo" span={2}><Texto>{s.justificacion}</Texto></Descriptions.Item>}
          <Descriptions.Item label="Sueldo mensual">{sueldo}</Descriptions.Item>
          <Descriptions.Item label="Contrato">{etiquetaDe(TIPOS_CONTRATO, s.tipo_contrato)} · {etiquetaDe(MODALIDADES, s.modalidad)}</Descriptions.Item>
          {s.horario && <Descriptions.Item label="Horario" span={2}><Texto>{s.horario}</Texto></Descriptions.Item>}
          <Descriptions.Item label="Descripción" span={2}><Texto>{s.descripcion}</Texto></Descriptions.Item>
          {s.actividades && <Descriptions.Item label="Funciones" span={2}><Texto>{s.actividades}</Texto></Descriptions.Item>}
          {s.requisitos && <Descriptions.Item label="Requisitos" span={2}><Texto>{s.requisitos}</Texto></Descriptions.Item>}
          {s.habilidades?.length > 0 && (
            <Descriptions.Item label="Habilidades" span={2}>{s.habilidades.map((h) => `${h.nombre} (${h.nivel}/5)`).join(', ')}</Descriptions.Item>
          )}
          {s.decision_motivo && <Descriptions.Item label="Respuesta de RR. HH." span={2}><Texto>{s.decision_motivo}</Texto></Descriptions.Item>}
          {s.cancelacion_motivo && <Descriptions.Item label="Motivo de cancelación" span={2}><Texto>{s.cancelacion_motivo}</Texto></Descriptions.Item>}
        </Descriptions>
      )}
    </Modal>
  );
}
