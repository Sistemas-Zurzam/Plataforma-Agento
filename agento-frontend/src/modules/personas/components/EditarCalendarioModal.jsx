import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { Alert, App, Button, Modal, Spin } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { useColaboradores } from '../hooks/useColaboradores';
import CalendarioInicialColaborador, { siguienteTipoCiclo } from './CalendarioInicialColaborador';

export default function EditarCalendarioModal({ open, colaborador, submitting, onGuardar, onCancel }) {
  const { fetchCalendarioDelMes } = useColaboradores();
  const { message } = App.useApp();
  const [mesActual, setMesActual] = useState(() => dayjs().startOf('month'));
  const [dias, setDias] = useState([]);
  const [originales, setOriginales] = useState([]);
  const [cargando, setCargando] = useState(false);

  const mesIngreso = colaborador?.fecha_ingreso ? dayjs(colaborador.fecha_ingreso).startOf('month') : null;
  const puedeRetroceder = !mesIngreso || mesActual.isAfter(mesIngreso, 'month');

  useEffect(() => {
    if (!open) return;
    setMesActual(dayjs().startOf('month'));
  }, [open, colaborador?.id]);

  useEffect(() => {
    if (!open || !colaborador?.id) return;
    setCargando(true);
    fetchCalendarioDelMes(colaborador.id, mesActual.year(), mesActual.month() + 1)
      .then((diasDelMes) => {
        const calendario = diasDelMes.map((dia) => ({ ...dia, editable: true }));
        setDias(calendario);
        setOriginales(calendario);
      })
      .catch(() => message.error('No se pudo cargar el calendario de este mes'))
      .finally(() => setCargando(false));
  }, [open, colaborador?.id, mesActual, fetchCalendarioDelMes, message]);

  const cambiarDia = (fecha) => setDias((actuales) => actuales.map((dia) => (
    dia.fecha === fecha && dia.tipo !== 'feriado'
      ? { ...dia, tipo: siguienteTipoCiclo(dia.tipo) }
      : dia
  )));

  const cambiarTodos = (tipo) => setDias((actuales) => actuales.map((dia) => (
    dia.tipo === 'feriado' ? dia : { ...dia, tipo }
  )));

  return (
    <Modal
      title="Editar calendario"
      open={open}
      onCancel={onCancel}
      onOk={() => onGuardar(dias.map(({ fecha, tipo }) => ({ fecha, tipo })))}
      okText="Guardar calendario"
      cancelText="Cancelar"
      confirmLoading={submitting}
      width={{ xs: '92%', sm: 760 }}
      centered
      destroyOnHidden
    >
      {colaborador?.horario?.tipo_turno === 'rotativo' && (
        <Alert
          type="warning"
          showIcon
          className="mb-3"
          message={`Horario rotativo${colaborador?.dias_descanso_rotativo_por_semana ? ` — le corresponden ${colaborador.dias_descanso_rotativo_por_semana} día(s) de descanso por semana` : ''}`}
          description="Los días marcados con borde punteado ('sin declarar') todavía no tienen un tipo confirmado — la planilla no se puede calcular hasta que los completes."
        />
      )}

      <div className="mb-3 flex items-center justify-center gap-3">
        <Button
          icon={<LeftOutlined />}
          size="small"
          disabled={!puedeRetroceder || cargando}
          onClick={() => setMesActual((actual) => actual.subtract(1, 'month'))}
          aria-label="Mes anterior"
        />
        <span className="w-40 text-center font-medium text-gray-800 capitalize">
          {mesActual.format('MMMM YYYY')}
        </span>
        <Button
          icon={<RightOutlined />}
          size="small"
          disabled={cargando}
          onClick={() => setMesActual((actual) => actual.add(1, 'month'))}
          aria-label="Mes siguiente"
        />
      </div>

      {cargando ? (
        <div className="flex min-h-64 items-center justify-center"><Spin /></div>
      ) : (
        <CalendarioInicialColaborador
          dias={dias}
          horario={colaborador?.horario}
          onCambiarDia={cambiarDia}
          onBulkSet={cambiarTodos}
          onRestablecer={() => setDias(originales)}
        />
      )}
    </Modal>
  );
}
