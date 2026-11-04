import {
  CalendarOutlined, CheckCircleFilled, ClockCircleOutlined, EditOutlined, LogoutOutlined, SearchOutlined, UserOutlined,
} from '@ant-design/icons';
import { Alert, App, Button, Empty, Popconfirm, Skeleton, Steps, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { useMisPostulaciones, usePerfilCandidato } from '../hooks/usePortalEmpleos';
import VerificarCorreo from './VerificarCorreo';

const PASOS_PUBLICOS = ['Recibida', 'En revisión', 'Evaluación', 'Entrevistas', 'Etapa final'];

function Resumen({ etiqueta, valor, icono, tono }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-lg ${tono}`}>{icono}</span>
      <div>
        <p className="text-lg font-semibold text-gray-900">{valor}</p>
        <p className="text-xs text-gray-500">{etiqueta}</p>
      </div>
    </div>
  );
}

function TarjetaPostulacion({ postulacion: p, onRetirar, onRendir, navegar }) {
  const finalizada = !p.vigente;

  return (
    <article className={`rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5 ${finalizada ? 'opacity-80' : ''}`}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <button
            type="button"
            className="cursor-pointer text-left text-base font-semibold text-gray-900 hover:text-agento-blue-bright focus:outline-2 focus:outline-agento-blue-bright"
            onClick={() => navegar(`/empleos/${p.codigo_proceso}`)}
          >
            {p.cargo}
          </button>
          <p className="mt-0.5 text-sm text-gray-500">{p.empresa ?? 'Empresa confidencial'}</p>
          <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
            <span><CalendarOutlined className="mr-1" />Postulaste el {dayjs(p.postulada_at).format('DD/MM/YYYY')}</span>
            <span>Código de seguimiento <span className="font-mono font-semibold text-gray-700">{p.codigo_seguimiento}</span></span>
          </div>
        </div>
        <Tag color={p.contratado ? 'green' : p.vigente ? 'blue' : 'default'} className="m-0!">
          {p.contratado && <CheckCircleFilled className="mr-1" />}
          {p.estado_publico}
        </Tag>
      </div>

      {p.vigente && (
        <Steps
          size="small"
          className="mt-4"
          current={p.paso}
          responsive={false}
          items={PASOS_PUBLICOS.map((titulo, i) => ({ title: <span className="hidden text-xs sm:inline">{titulo}</span>, status: i < p.paso ? 'finish' : i === p.paso ? 'process' : 'wait' }))}
          aria-label={`Avance: ${PASOS_PUBLICOS[p.paso]}`}
        />
      )}
      {p.vigente && <p className="mt-1 text-xs text-gray-500 sm:hidden">Etapa actual: {PASOS_PUBLICOS[p.paso]}</p>}

      {p.mensaje && (
        <Alert className="mt-4" type={p.vigente ? 'warning' : 'info'} showIcon message={p.mensaje} />
      )}

      {p.pruebas_pendientes.map((t) => (
        <div key={t.asignacion_id} className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-agento-blue-light bg-agento-blue-light/50 p-3">
          <div className="flex items-start gap-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-agento-blue"><EditOutlined /></span>
            <div>
              <p className="text-sm font-medium text-gray-900">Tienes una evaluación pendiente: {t.nombre}</p>
              <p className="text-xs text-gray-600">
                <ClockCircleOutlined className="mr-1" />{t.tiempo_limite_minutos} min desde que la inicies · disponible hasta el {dayjs(t.disponible_hasta).format('DD/MM/YYYY')}
              </p>
            </div>
          </div>
          <Button type="primary" onClick={() => onRendir(t.asignacion_id)}>{t.estado === 'en_curso' ? 'Continuar' : 'Rendir evaluación'}</Button>
        </div>
      ))}

      {p.vigente && (
        <div className="mt-4 flex justify-end border-t border-gray-100 pt-3">
          <Popconfirm
            title="¿Retirar tu postulación?"
            description="No podrás volver a postular a este mismo proceso."
            okText="Retirar"
            okButtonProps={{ danger: true }}
            cancelText="Cancelar"
            onConfirm={() => onRetirar(p.id)}
          >
            <Button size="small" type="text" danger>Retirar postulación</Button>
          </Popconfirm>
        </div>
      )}
    </article>
  );
}

export default function MisPostulaciones({ acceso, navegar }) {
  const { message } = App.useApp();
  const { datos, cargando, error, recargar, retirar, accesoPrueba } = useMisPostulaciones(acceso.autenticado);
  const perfil = usePerfilCandidato(acceso.autenticado);
  const { expirar } = acceso;

  useEffect(() => {
    if (error?.response?.status === 401) {
      expirar();
    }
  }, [error, expirar]);

  if (!acceso.autenticado) {
    return (
      <VerificarCorreo
        acceso={acceso}
        descripcion="Verifica tu correo para ver tus postulaciones. El código de seguimiento por sí solo no da acceso."
        onVerificado={recargar}
      />
    );
  }

  const lista = datos ?? [];
  const enProceso = lista.filter((p) => p.vigente);
  const finalizadas = lista.filter((p) => !p.vigente);
  const pendientes = lista.reduce((total, p) => total + p.pruebas_pendientes.length, 0);

  const alRetirar = async (id) => {
    try {
      await retirar(id);
      message.success('Postulación retirada.');
      recargar();
    } catch (e) {
      message.error(mensajeDeError(e));
    }
  };

  const alRendir = async (asignacionId) => {
    try {
      const { token } = await accesoPrueba(asignacionId);
      navegar(`/empleos/prueba/${token}`);
    } catch (e) {
      message.error(mensajeDeError(e));
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Mis postulaciones</h1>
          <p className="mt-1 text-sm text-gray-500">
            <UserOutlined className="mr-1" />
            {perfil.datos?.email ?? 'Correo verificado'}
          </p>
        </div>
        <div className="flex gap-2">
          <Button icon={<SearchOutlined />} onClick={() => navegar('/empleos')}>Buscar empleos</Button>
          <Button icon={<LogoutOutlined />} onClick={acceso.cerrarSesion}>Salir</Button>
        </div>
      </div>

      {cargando && !datos && <Skeleton active paragraph={{ rows: 4 }} />}
      {error && error?.response?.status !== 401 && (
        <Alert type="error" showIcon message="No se pudieron cargar tus postulaciones." action={<Button size="small" onClick={recargar}>Reintentar</Button>} />
      )}

      {datos && lista.length === 0 && (
        <div className="rounded-2xl border border-gray-100 bg-white py-12 shadow-sm">
          <Empty description="Aún no tienes postulaciones">
            <Button type="primary" onClick={() => navegar('/empleos')}>Ver empleos disponibles</Button>
          </Empty>
        </div>
      )}

      {lista.length > 0 && (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Resumen etiqueta="En proceso" valor={enProceso.length} icono={<ClockCircleOutlined />} tono="bg-agento-blue-light text-agento-blue" />
            <Resumen etiqueta="Evaluaciones pendientes" valor={pendientes} icono={<EditOutlined />} tono="bg-orange-50 text-orange-600" />
            <Resumen etiqueta="Finalizadas" valor={finalizadas.length} icono={<CheckCircleFilled />} tono="bg-green-50 text-green-600" />
          </div>

          {enProceso.length > 0 && (
            <section className="space-y-3" aria-label="Postulaciones en proceso">
              <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase">En proceso</h2>
              {enProceso.map((p) => <TarjetaPostulacion key={p.id} postulacion={p} navegar={navegar} onRetirar={alRetirar} onRendir={alRendir} />)}
            </section>
          )}

          {finalizadas.length > 0 && (
            <section className="space-y-3" aria-label="Postulaciones finalizadas">
              <h2 className="text-sm font-semibold tracking-wide text-gray-500 uppercase">Finalizadas</h2>
              {finalizadas.map((p) => <TarjetaPostulacion key={p.id} postulacion={p} navegar={navegar} onRetirar={alRetirar} onRendir={alRendir} />)}
            </section>
          )}
        </>
      )}
    </div>
  );
}
