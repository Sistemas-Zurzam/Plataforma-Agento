import { Alert, App, Button, Card, Popconfirm, Radio, Result, Spin, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { usePruebaCandidato } from '../hooks/usePortalEmpleos';

function formatear(segundos) {
  const m = Math.floor(segundos / 60);
  const s = Math.floor(segundos % 60);
  return `${m}:${String(s).padStart(2, '0')}`;
}

/**
 * Rendir una prueba con el enlace personal. El reloj lo controla el
 * servidor: el contador local parte de los segundos restantes que informó
 * el servidor y solo es indicativo; al reconectar se vuelve a consultar.
 */
export default function RendirPrueba({ token }) {
  const { message } = App.useApp();
  const { datos, setDatos, cargando, error, recargar, iniciar, responder, finalizar } = usePruebaCandidato(token);
  const [ahora, setAhora] = useState(() => Date.now());
  const [respuestas, setRespuestas] = useState({});
  const [terminada, setTerminada] = useState(false);

  const enCurso = Boolean(datos?.intento && !datos.intento.finalizado) && !terminada;
  const restante = enCurso
    ? Math.max(0, datos.intento.segundos_restantes - (ahora - datos.recibido_ms) / 1000)
    : null;

  useEffect(() => {
    if (!enCurso) return undefined;
    const id = setInterval(() => setAhora(Date.now()), 1000);
    return () => clearInterval(id);
  }, [enCurso]);

  useEffect(() => {
    if (enCurso && restante === 0) {
      finalizar().finally(() => {
        setTerminada(true);
        recargar();
      });
    }
  }, [enCurso, restante, finalizar, recargar]);

  if (cargando && !datos) return <div className="flex justify-center py-10"><Spin /></div>;
  if (error) return <Result status="404" title="Enlace no válido" subTitle="El enlace no existe o fue reemplazado por uno nuevo. Solicítalo desde Mis postulaciones." />;
  if (!datos) return null;

  if (terminada || datos.intento?.finalizado || ['finalizada', 'vencida', 'anulada'].includes(datos.estado)) {
    return (
      <Result
        status={datos.estado === 'vencida' || datos.estado === 'anulada' ? 'info' : 'success'}
        title={datos.estado === 'vencida' ? 'El plazo para rendir esta evaluación venció' : datos.estado === 'anulada' ? 'Esta evaluación ya no está disponible' : 'Evaluación finalizada'}
        subTitle="Gracias. Los resultados los revisa el equipo de selección."
      />
    );
  }

  if (!datos.intento) {
    return (
      <Card className="mx-auto max-w-xl">
        <h1 className="text-lg font-semibold">{datos.nombre}</h1>
        {datos.descripcion && <p className="mt-2 whitespace-pre-line text-sm text-gray-600">{datos.descripcion}</p>}
        <Alert className="my-4" type="info" showIcon message={`Tendrás ${datos.tiempo_limite_minutos} minutos desde que inicies. Si se corta tu conexión puedes volver a este enlace mientras el tiempo siga vigente. Tus respuestas se guardan automáticamente.`} />
        <Button type="primary" onClick={async () => {
          try {
            setDatos(await iniciar());
            setAhora(Date.now());
          } catch (e) {
            message.error(mensajeDeError(e));
            recargar();
          }
        }}>Iniciar evaluación</Button>
      </Card>
    );
  }

  const respuestasVista = { ...(datos.respuestas ?? {}), ...respuestas };

  return (
    <div className="mx-auto max-w-2xl space-y-3">
      <div className="sticky top-0 z-10 flex items-center justify-between rounded-xl bg-white p-3 shadow-sm">
        <span className="font-semibold">{datos.nombre}</span>
        <Tag color={restante < 60 ? 'red' : 'blue'} aria-live="polite">Tiempo restante {formatear(restante ?? 0)}</Tag>
      </div>
      {datos.preguntas.map((pregunta, i) => (
        <Card key={pregunta.id} size="small">
          <p className="mb-2 text-sm font-medium">{i + 1}. {pregunta.enunciado}</p>
          <Radio.Group
            className="flex! flex-col! gap-1"
            value={respuestasVista[pregunta.id]}
            onChange={async (e) => {
              const opcion = e.target.value;
              setRespuestas((r) => ({ ...r, [pregunta.id]: opcion }));
              try {
                const r = await responder(pregunta.id, opcion);
                setDatos((previo) => ({ ...previo, intento: { ...previo.intento, segundos_restantes: r.segundos_restantes }, recibido_ms: Date.now() }));
              } catch (err) {
                message.error(mensajeDeError(err, 'No se pudo guardar la respuesta.'));
                if (err?.response?.status === 422) {
                  setTerminada(true);
                  recargar();
                }
              }
            }}
            options={pregunta.opciones.map((o) => ({ value: o.id, label: o.texto }))}
          />
        </Card>
      ))}
      <Popconfirm
        title="¿Finalizar la evaluación?"
        description="No podrás cambiar tus respuestas."
        okText="Finalizar"
        cancelText="Seguir respondiendo"
        onConfirm={async () => {
          try {
            await finalizar();
            setTerminada(true);
          } catch (e) {
            message.error(mensajeDeError(e));
          }
        }}
      >
        <Button type="primary" block>Finalizar evaluación</Button>
      </Popconfirm>
    </div>
  );
}
