import { EnvironmentOutlined } from '@ant-design/icons';
import {
  Alert, Button, Card, Empty, Input, Layout, Pagination, Result, Select, Spin, Tag,
} from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import agentoBrandMark from '../../../assets/agento-brand-mark.svg';
import { MODALIDADES, TIPOS_CONTRATO, etiquetaDe } from '../constants/seleccion';
import { useAccesoCandidato, useEmpleo, useEmpleos } from '../hooks/usePortalEmpleos';
import MisPostulaciones from './MisPostulaciones';
import PostulacionForm from './PostulacionForm';
import RendirPrueba from './RendirPrueba';
import VerificarCorreo from './VerificarCorreo';

function rutaDesde(pathname) {
  const ruta = pathname.replace(/\/$/, '');
  if (ruta === '/empleos/mis-postulaciones') return { vista: 'mis' };
  const prueba = ruta.match(/^\/empleos\/prueba\/([A-Za-z0-9]+)$/);
  if (prueba) return { vista: 'prueba', token: prueba[1] };
  const detalle = ruta.match(/^\/empleos\/([A-Za-z0-9-]+)$/);
  if (detalle) return { vista: 'detalle', codigo: detalle[1] };
  return { vista: 'listado' };
}

function Listado({ navegar }) {
  const [cargo, setCargo] = useState('');
  const [ubicacion, setUbicacion] = useState('');
  const [modalidad, setModalidad] = useState();
  const [pagina, setPagina] = useState(1);
  const filtros = useMemo(() => ({ cargo: cargo || undefined, ubicacion: ubicacion || undefined, modalidad, page: pagina }), [cargo, ubicacion, modalidad, pagina]);
  const { datos, cargando, error, recargar } = useEmpleos(filtros);

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Empleos disponibles</h1>
        <p className="text-sm text-gray-500">Postula con tu correo verificado y sigue el estado de tus postulaciones.</p>
      </div>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
        <Input.Search placeholder="Cargo" allowClear onSearch={(v) => { setPagina(1); setCargo(v); }} aria-label="Buscar por cargo" />
        <Input.Search placeholder="Ubicación" allowClear onSearch={(v) => { setPagina(1); setUbicacion(v); }} aria-label="Buscar por ubicación" />
        <Select allowClear placeholder="Modalidad" value={modalidad} onChange={(v) => { setPagina(1); setModalidad(v); }} options={MODALIDADES} aria-label="Modalidad" />
      </div>
      {cargando && !datos && <div className="flex justify-center py-10"><Spin /></div>}
      {error && <Alert type="error" showIcon message="No se pudo cargar el listado." action={<Button size="small" onClick={recargar}>Reintentar</Button>} />}
      {datos && datos.data.length === 0 && <Empty description="No hay publicaciones con estos filtros" />}
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {(datos?.data ?? []).map((e) => (
          <Card key={e.codigo} hoverable onClick={() => navegar(`/empleos/${e.codigo}`)} className="cursor-pointer" role="link" tabIndex={0} onKeyDown={(ev) => { if (ev.key === 'Enter') navegar(`/empleos/${e.codigo}`); }}>
            <p className="text-base font-semibold text-gray-900">{e.cargo}</p>
            <p className="text-sm text-gray-500">{e.empresa?.nombre ?? 'Empresa confidencial'}</p>
            <div className="mt-2 flex flex-wrap gap-1">
              <Tag>{etiquetaDe(MODALIDADES, e.modalidad)}</Tag>
              {e.ubicacion && <Tag icon={<EnvironmentOutlined />}>{e.ubicacion}</Tag>}
              {e.estado === 'en_pausa' && <Tag color="orange">En pausa</Tag>}
            </div>
          </Card>
        ))}
      </div>
      {datos?.meta?.total > (datos?.meta?.per_page ?? 12) && (
        <Pagination current={pagina} total={datos.meta.total} pageSize={datos.meta.per_page} onChange={setPagina} showSizeChanger={false} />
      )}
    </div>
  );
}

function Detalle({ codigo, invitacion, acceso, navegar }) {
  const { datos: empleo, cargando, error, postular } = useEmpleo(codigo, invitacion);
  const [postulando, setPostulando] = useState(false);

  if (cargando) return <div className="flex justify-center py-10"><Spin /></div>;
  if (error) {
    return <Result status="404" title="Publicación no disponible" subTitle="La publicación no existe, no es pública o tu invitación venció." extra={<Button onClick={() => navegar('/empleos')}>Ver empleos</Button>} />;
  }

  return (
    <div className="space-y-4">
      <Button type="link" className="px-0!" onClick={() => navegar('/empleos')}>← Empleos</Button>
      <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-6">
        <div className="flex items-start gap-3">
          {empleo.empresa?.logo_url && <img src={empleo.empresa.logo_url} alt="" className="h-12 w-12 rounded-lg object-contain" />}
          <div>
            <h1 className="text-xl font-semibold text-gray-900">{empleo.cargo}</h1>
            <p className="text-sm text-gray-500">{empleo.empresa?.nombre ?? 'Empresa confidencial'}</p>
          </div>
        </div>
        <div className="mt-3 flex flex-wrap gap-1">
          <Tag>{etiquetaDe(MODALIDADES, empleo.modalidad)}</Tag>
          <Tag>{etiquetaDe(TIPOS_CONTRATO, empleo.tipo_contrato)}</Tag>
          {empleo.ubicacion && <Tag icon={<EnvironmentOutlined />}>{empleo.ubicacion}</Tag>}
          {empleo.salario && <Tag color="green">S/ {empleo.salario.min} – {empleo.salario.max}</Tag>}
          {empleo.fecha_cierre_postulacion && <Tag>Postula hasta el {dayjs(empleo.fecha_cierre_postulacion).format('DD/MM/YYYY')}</Tag>}
        </div>
        <p className="mt-4 whitespace-pre-line text-sm text-gray-700">{empleo.descripcion}</p>
        {empleo.requisitos.length > 0 && (
          <div className="mt-4">
            <p className="text-sm font-semibold">Requisitos</p>
            <ul className="list-disc pl-5 text-sm text-gray-700">
              {empleo.requisitos.map((r) => <li key={r.id}>{r.descripcion}{r.tipo === 'deseable' ? ' (deseable)' : ''}</li>)}
            </ul>
          </div>
        )}
        {empleo.competencias.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-1">
            {empleo.competencias.map((c) => <Tag key={c}>{c}</Tag>)}
          </div>
        )}
        {!empleo.acepta_postulaciones && (
          <Alert className="mt-4" type="warning" showIcon message={empleo.mensaje_publico ?? 'Este proceso no está recibiendo postulaciones.'} />
        )}
        {empleo.acepta_postulaciones && !postulando && (
          <Button type="primary" size="large" className="mt-4" onClick={() => setPostulando(true)}>Postular</Button>
        )}
      </div>
      {postulando && empleo.acepta_postulaciones && (acceso.autenticado
        ? <PostulacionForm empleo={empleo} invitacion={invitacion} onPostular={postular} onTerminar={() => navegar('/empleos/mis-postulaciones')} />
        : <VerificarCorreo acceso={acceso} descripcion="Para postular, verifica tu correo. Lo usarás también para consultar tus postulaciones." />)}
    </div>
  );
}

/**
 * Portal público de empleos (/empleos...). No usa la sesión administrativa:
 * se muestra igual con o sin usuario de Agento autenticado.
 */
export default function PortalEmpleosApp() {
  const [ruta, setRuta] = useState(() => rutaDesde(window.location.pathname));
  const invitacion = new URLSearchParams(window.location.search).get('invitacion');
  const acceso = useAccesoCandidato();

  useEffect(() => {
    const alVolver = () => setRuta(rutaDesde(window.location.pathname));
    window.addEventListener('popstate', alVolver);
    return () => window.removeEventListener('popstate', alVolver);
  }, []);

  const navegar = (path) => {
    window.history.pushState({}, '', path);
    setRuta(rutaDesde(path.split('?')[0]));
    window.scrollTo(0, 0);
  };

  return (
    <Layout className="min-h-svh! bg-gray-50!">
      <header className="bg-agento-blue">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
          <button
            type="button"
            className="flex cursor-pointer items-center gap-3 rounded-lg focus:outline-2 focus:outline-white"
            onClick={() => navegar('/empleos')}
          >
            <img src={agentoBrandMark} alt="Agento" className="h-7 w-auto" />
            <span className="h-6 w-px bg-white/30" aria-hidden="true" />
            <span className="text-base font-semibold text-white">Empleos</span>
          </button>
          <nav className="flex items-center gap-1" aria-label="Portal de empleos">
            {[
              { ruta: '/empleos', etiqueta: 'Vacantes', activa: ['listado', 'detalle'].includes(ruta.vista) },
              { ruta: '/empleos/mis-postulaciones', etiqueta: 'Mis postulaciones', activa: ruta.vista === 'mis' },
            ].map((item) => (
              <button
                key={item.ruta}
                type="button"
                aria-current={item.activa ? 'page' : undefined}
                onClick={() => navegar(item.ruta)}
                className={`cursor-pointer rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus:outline-2 focus:outline-white ${
                  item.activa ? 'bg-white text-agento-blue' : 'text-white/90 hover:bg-white/10'
                }`}
              >
                {item.etiqueta}
              </button>
            ))}
          </nav>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl px-4 py-6">
        {ruta.vista === 'listado' && <Listado navegar={navegar} />}
        {ruta.vista === 'detalle' && <Detalle codigo={ruta.codigo} invitacion={invitacion} acceso={acceso} navegar={navegar} />}
        {ruta.vista === 'mis' && <MisPostulaciones acceso={acceso} navegar={navegar} />}
        {ruta.vista === 'prueba' && <RendirPrueba token={ruta.token} />}
      </main>
    </Layout>
  );
}
