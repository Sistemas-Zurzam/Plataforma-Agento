import { Alert, Button, Empty, Result, Spin } from 'antd';

/**
 * Envoltorio de carga / error / acceso denegado / vacío para las vistas de
 * Selección. La autorización real la decide el backend.
 */
export default function EstadoSeccion({ cargando, error, vacio, mensajeVacio = 'No hay registros', onReintentar, children }) {
  if (cargando) {
    return (
      <div className="flex justify-center py-10" role="status" aria-live="polite">
        <Spin size="large" />
      </div>
    );
  }

  if (error) {
    const status = error?.response?.status;
    if (status === 403) {
      return <Result status="403" title="Acceso denegado" subTitle="No tienes permiso para ver esta información." />;
    }
    if (status === 404) {
      return <Result status="404" title="No encontrado" subTitle="El registro no existe o no pertenece a tu empresa." />;
    }
    return (
      <Alert
        type="error"
        showIcon
        className="my-4"
        message={error?.response?.data?.message ?? 'Ocurrió un error al cargar la información.'}
        action={onReintentar ? <Button size="small" onClick={onReintentar}>Reintentar</Button> : null}
      />
    );
  }

  if (vacio) {
    return <Empty description={mensajeVacio} className="py-10" />;
  }

  return children;
}
