import { Alert, Empty, Spin } from 'antd';

const MENSAJES_POR_ESTADO = {
  401: 'Tu sesión expiró. Vuelve a iniciar sesión.',
  403: 'No tienes permiso para ver esta información.',
  404: 'No se encontró la información solicitada.',
};

/**
 * Envoltorio de carga/error/vacío reutilizado por todas las pantallas del
 * portal — la autorización real ya se decidió en Laravel; esto solo evita
 * que un 401/403/404/422 deje la pantalla en blanco o rota.
 */
export default function EstadoConsulta({ cargando, error, vacio, children }) {
  if (cargando) {
    return (
      <div className="flex justify-center py-10">
        <Spin size="large" />
      </div>
    );
  }

  if (error) {
    const status = error?.response?.status;
    const mensaje = status === 422
      ? (error?.response?.data?.message ?? 'Los filtros ingresados no son válidos.')
      : (MENSAJES_POR_ESTADO[status] ?? 'Ocurrió un error al cargar la información.');

    return <Alert type="error" showIcon message={mensaje} className="my-4" />;
  }

  if (vacio) {
    return <Empty description="No hay información para el rango seleccionado" className="py-10" />;
  }

  return children;
}
