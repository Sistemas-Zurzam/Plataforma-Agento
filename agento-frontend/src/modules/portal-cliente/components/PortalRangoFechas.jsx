import { DatePicker } from 'antd';
import dayjs from 'dayjs';

const { RangePicker } = DatePicker;

/**
 * fecha_desde/fecha_hasta compartidos por las pantallas del portal — el
 * backend igual valida el rango máximo (config('portal_cliente.rango_maximo_dias')),
 * esto es solo para que el usuario no tenga que escribir fechas a mano.
 */
export default function PortalRangoFechas({ value, onChange }) {
  return (
    <RangePicker
      value={[dayjs(value.fecha_desde), dayjs(value.fecha_hasta)]}
      onChange={(fechas) => {
        if (!fechas?.[0] || !fechas?.[1]) {
          return;
        }
        onChange({
          fecha_desde: fechas[0].format('YYYY-MM-DD'),
          fecha_hasta: fechas[1].format('YYYY-MM-DD'),
        });
      }}
      allowClear={false}
    />
  );
}
