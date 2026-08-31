import { Button, Modal, Table } from 'antd';

const TIPO_INCIDENCIA_LABEL = {
  falta: 'Falta',
  marcacion_incompleta: 'Marcación incompleta',
  horario_desplazado: 'Horario desplazado',
  horas_incompletas: 'Horas incompletas',
  dia_sin_clasificar: 'Rotativo sin planificar',
  trabajo_en_descanso: 'Trabajo en descanso',
};

const columnas = [
  { title: 'Colaborador', dataIndex: 'colaborador' },
  { title: 'Legajo', dataIndex: 'legajo', width: 100 },
  { title: 'Tipo', dataIndex: 'tipo', width: 170, render: (tipo) => TIPO_INCIDENCIA_LABEL[tipo] ?? tipo },
  { title: 'Fecha', dataIndex: 'fecha', width: 110 },
  { title: 'Descripción', dataIndex: 'descripcion', ellipsis: true, render: (v) => v ?? <span className="text-gray-300">—</span> },
];

/**
 * Bloqueo informativo (no una confirmación) — se muestra en vez del diálogo
 * habitual cuando ya se detectó, antes de intentar la acción, que hay
 * incidencias de asistencia pendientes. Se reutiliza en los tres puntos
 * donde el backend aplica la misma regla (BoletaService::aprobar /
 * incidenciasPendientesAprobar, CicloRemunerativoService::cerrar /
 * incidenciasPendientesCierre): aprobar boleta individual, aprobar
 * seleccionadas y cerrar período. Solo tiene un botón de salida: la acción
 * sigue bloqueada hasta que RR.HH. resuelva cada fila listada aquí.
 */
export default function IncidenciasPendientesModal({ open, title, incidencias, onCancel }) {
  return (
    <Modal
      title={title}
      open={open}
      onCancel={onCancel}
      footer={<Button type="primary" onClick={onCancel}>Entendido</Button>}
      width={{ xs: '95%', sm: '85%', md: 720 }}
      destroyOnHidden
    >
      <p className="mb-3 text-sm text-gray-600">
        Los siguientes colaboradores tienen incidencias de asistencia pendientes dentro del período correspondiente. Resuélvelas en Gestión de asistencias y vuelve a intentarlo.
      </p>
      <Table
        size="small"
        rowKey="id"
        dataSource={incidencias ?? []}
        columns={columnas}
        pagination={false}
        scroll={{ y: 320 }}
      />
    </Modal>
  );
}
