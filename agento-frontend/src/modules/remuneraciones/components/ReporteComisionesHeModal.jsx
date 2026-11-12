import { FileExcelOutlined } from '@ant-design/icons';
import { App, Button, DatePicker, Modal } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';

const MAXIMO_MESES = 24;

/**
 * Elige el rango de meses del reporte de comisiones y horas extra de las
 * planillas complementarias. La empresa la fija el ciclo activo en el backend;
 * el rango solo decide qué ciclos de esa empresa entran al Excel.
 */
export default function ReporteComisionesHeModal({ open, onCancel, ciclo, exportarComisionesHorasExtraExcel }) {
  const { message } = App.useApp();
  // null = rango por defecto (mes del ciclo activo); vuelve a null al cerrar.
  const [rangoElegido, setRangoElegido] = useState(null);
  const [exportando, setExportando] = useState(false);
  const mesCiclo = ciclo?.fecha_inicio ? dayjs(ciclo.fecha_inicio) : dayjs();
  const rango = rangoElegido ?? [mesCiclo, mesCiclo];

  const cerrar = () => {
    setRangoElegido(null);
    onCancel();
  };

  const meses = rango ? rango[1].startOf('month').diff(rango[0].startOf('month'), 'month') + 1 : 0;
  const excedeMaximo = meses > MAXIMO_MESES;

  const descargar = async () => {
    if (!ciclo || !rango || excedeMaximo) return;
    setExportando(true);
    try {
      await exportarComisionesHorasExtraExcel(ciclo.id, {
        desde: rango[0].format('YYYY-MM'),
        hasta: rango[1].format('YYYY-MM'),
      });
      message.success('Reporte de comisiones y horas extra generado');
      cerrar();
    } catch (e) {
      // La respuesta es un blob: el mensaje del backend (422) no llega como JSON.
      message.error(e.response?.status === 422
        ? 'No hay planillas complementarias en los meses elegidos'
        : 'No se pudo generar el reporte de comisiones y horas extra');
    } finally {
      setExportando(false);
    }
  };

  return (
    <Modal
      open={open}
      onCancel={cerrar}
      title="Reporte de comisiones y horas extra"
      destroyOnHidden
      footer={[
        <Button key="cancelar" onClick={cerrar}>Cancelar</Button>,
        <Button key="descargar" type="primary" icon={<FileExcelOutlined />} loading={exportando}
          disabled={!rango || excedeMaximo} onClick={descargar}>
          Descargar Excel
        </Button>,
      ]}
    >
      <p className="mb-3 text-sm text-gray-500">
        Incluye las comisiones y horas extra registradas en las planillas complementarias de los ciclos que inician en los meses elegidos.
      </p>
      <DatePicker.RangePicker
        picker="month"
        format="MM/YYYY"
        allowClear={false}
        value={rango}
        onChange={setRangoElegido}
        className="w-full"
      />
      {excedeMaximo && (
        <p className="mt-2 text-sm text-red-500">Elige un rango de hasta {MAXIMO_MESES} meses.</p>
      )}
    </Modal>
  );
}
