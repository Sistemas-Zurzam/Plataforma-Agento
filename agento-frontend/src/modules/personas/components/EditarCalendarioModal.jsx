import { Modal } from 'antd';
import { useEffect, useState } from 'react';
import CalendarioInicialColaborador, { siguienteTipoCiclo } from './CalendarioInicialColaborador';

export default function EditarCalendarioModal({ open, colaborador, submitting, onGuardar, onCancel }) {
  const [dias, setDias] = useState([]);
  const [originales, setOriginales] = useState([]);

  useEffect(() => {
    if (!open) return;
    const calendario = (colaborador?.calendario ?? []).map((dia) => ({
      ...dia,
      editable: dia.tipo !== 'feriado',
    }));
    setDias(calendario);
    setOriginales(calendario);
  }, [open, colaborador]);

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
      width={760}
      centered
      destroyOnHidden
    >
      <CalendarioInicialColaborador
        dias={dias}
        horario={colaborador?.horario}
        onCambiarDia={cambiarDia}
        onBulkSet={cambiarTodos}
        onRestablecer={() => setDias(originales)}
      />
    </Modal>
  );
}
