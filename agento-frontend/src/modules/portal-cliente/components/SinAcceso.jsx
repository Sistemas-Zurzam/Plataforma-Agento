import { Result } from 'antd';

/**
 * Se muestra cuando el usuario intenta abrir una sección sin el permiso
 * portal.* correspondiente — el menú ya la oculta, pero esto cubre la
 * navegación directa por URL. No dispara ninguna consulta a la API.
 */
export default function SinAcceso() {
  return <Result status="403" title="Sin acceso" subTitle="No tienes permiso para ver esta sección." />;
}
