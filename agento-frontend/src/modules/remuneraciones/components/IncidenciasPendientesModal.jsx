import { Button, Modal, Space, Table } from 'antd';

const TIPO_INCIDENCIA_LABEL = {
  falta: 'Falta',
  marcacion_incompleta: 'Marcación incompleta',
  horario_desplazado: 'Horario desplazado',
  horas_incompletas: 'Horas incompletas',
  dia_sin_clasificar: 'Rotativo sin planificar',
  trabajo_en_descanso: 'Trabajo en descanso',
};

/**
 * Bloqueo informativo (no una confirmación) — se muestra en vez del diálogo
 * habitual cuando ya se detectó, antes de intentar la acción, que hay
 * incidencias de asistencia pendientes. Se reutiliza en los tres puntos
 * donde el backend aplica la misma regla (BoletaService::aprobar /
 * incidenciasPendientesAprobar, CicloRemunerativoService::cerrar /
 * incidenciasPendientesCierre): aprobar boleta individual, aprobar
 * seleccionadas y cerrar período. Cuando el usuario tiene permiso para
 * resolver incidencias (`puedeResolver`), Aprobar/Rechazar quedan
 * disponibles acá mismo para el caso común, sin navegar a Gestión de
 * Asistencias — pero OJO: eso solo destraba el cierre (cambia el estado de
 * la incidencia), nunca cambia el resultado diario ni evita el descuento de
 * una falta real. Para "dia_sin_clasificar"/"trabajo_en_descanso", que
 * exigen su propio flujo de dominio, no se ofrece acción rápida acá.
 */
export default function IncidenciasPendientesModal({ open, title, incidencias, onCancel, puedeResolver, onResolver }) {
  const tiposSinAccionRapida = ['dia_sin_clasificar', 'trabajo_en_descanso'];

  const columnas = [
    { title: 'Colaborador', dataIndex: 'colaborador' },
    { title: 'Legajo', dataIndex: 'legajo', width: 100 },
    { title: 'Tipo', dataIndex: 'tipo', width: 170, render: (tipo) => TIPO_INCIDENCIA_LABEL[tipo] ?? tipo },
    { title: 'Fecha', dataIndex: 'fecha', width: 110 },
    { title: 'Descripción', dataIndex: 'descripcion', ellipsis: true, render: (v) => v ?? <span className="text-gray-300">—</span> },
    ...(puedeResolver ? [{
      title: 'Acciones',
      key: 'acciones',
      width: 160,
      render: (_, incidencia) => (tiposSinAccionRapida.includes(incidencia.tipo)
        ? <span className="text-gray-300">Ir a Asistencia</span>
        : (
          <Space size="small">
            <Button size="small" type="link" onClick={() => onResolver(incidencia, 'aprobar')}>Aprobar</Button>
            <Button size="small" type="link" danger onClick={() => onResolver(incidencia, 'rechazar')}>Rechazar</Button>
          </Space>
        )),
    }] : []),
  ];

  return (
    <Modal
      title={title}
      open={open}
      onCancel={onCancel}
      footer={<Button type="primary" onClick={onCancel}>Entendido</Button>}
      width={{ xs: '95%', sm: '85%', md: puedeResolver ? 880 : 720 }}
      destroyOnHidden
    >
      <p className="mb-3 text-sm text-gray-600">
        Los siguientes colaboradores tienen incidencias de asistencia pendientes dentro del período correspondiente.
        {puedeResolver
          ? ' Puedes aprobarlas/rechazarlas acá mismo, o resolverlas con más detalle en Gestión de asistencias.'
          : ' Resuélvelas en Gestión de asistencias y vuelve a intentarlo.'}
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
