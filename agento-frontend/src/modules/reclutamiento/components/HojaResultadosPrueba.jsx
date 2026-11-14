import { StarFilled } from '@ant-design/icons';
import { Alert, Button, Form, Input, Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { CONCLUSIONES_PRUEBA, NIVELES_PSICOMETRICOS } from '../constants/seleccion';

const ESTADOS_ASIGNACION = {
  pendiente: 'Enviada: aún no la inicia',
  en_curso: 'En curso',
  finalizada: 'Finalizada',
  vencida: 'Vencida sin rendir',
  anulada: 'Anulada',
};

const NIVEL_MINIMO = { bajo: 'Bajo', medio: 'Medio', alto: 'Alto' };

/**
 * Hoja de resultados de la guía del evaluador: puntaje bruto y nivel por
 * dimensión, interpretación, escala de validez, filtro del proceso,
 * preguntas sugeridas para la entrevista y conclusión del evaluador.
 */
export default function HojaResultadosPrueba({ asignacion, puedeConcluir, onConcluir }) {
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const { prueba, intento, resultado } = asignacion;

  const guardar = async () => {
    const valores = await form.validateFields();
    setGuardando(true);
    try {
      await onConcluir(asignacion.id, valores);
    } finally {
      setGuardando(false);
    }
  };

  const aProfundizar = (resultado?.dimensiones ?? []).filter(
    (d) => d.pregunta_entrevista && (d.nivel === 'bajo' || (d.prioritaria && d.nivel !== 'alto')),
  );
  const conclusion = CONCLUSIONES_PRUEBA.find((c) => c.value === resultado?.conclusion?.valor);

  return (
    <div className="space-y-3 rounded-xl border border-gray-100 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{prueba.nombre} <span className="text-xs text-gray-400">v{prueba.version}</span></span>
          {prueba.tipo === 'conocimientos' && <Tag color="purple">Conocimientos</Tag>}
          {prueba.origen === 'agento' && <Tag color="blue">Banco Agento</Tag>}
          {!prueba.validada && <Tag>No validada</Tag>}
        </div>
        <span className="text-xs text-gray-500">
          {ESTADOS_ASIGNACION[asignacion.estado] ?? asignacion.estado}
          {intento?.finalizado_at && ` · ${dayjs(intento.finalizado_at).format('DD/MM/YYYY HH:mm')}`}
          {intento?.cierre === 'tiempo' && ' · cerrada por tiempo'}
        </span>
      </div>

      {!resultado && asignacion.estado === 'pendiente' && (
        <p className="text-sm text-gray-500">Disponible hasta {dayjs(asignacion.disponible_hasta).format('DD/MM/YYYY')}. El resultado aparecerá aquí cuando el candidato termine.</p>
      )}

      {resultado && (
        <>
          {resultado.filtro?.puntaje_minimo != null && (
            <Alert
              showIcon
              type={resultado.filtro.cumple ? 'success' : 'warning'}
              message={`Puntaje ${resultado.filtro.puntaje ?? '—'} % · mínimo del proceso ${resultado.filtro.puntaje_minimo} %: ${resultado.filtro.cumple ? 'alcanza el mínimo.' : 'no alcanza el mínimo.'}`}
              description={resultado.filtro.cumple ? undefined : 'Es una alerta para RR. HH.: decide si continúa con la entrevista y las referencias.'}
            />
          )}
          {resultado.filtro && resultado.filtro.puntaje_minimo == null && (
            <Alert
              showIcon
              type={resultado.filtro.cumple ? 'success' : 'warning'}
              message={resultado.filtro.cumple
                ? `Alcanza el nivel mínimo (${NIVEL_MINIMO[resultado.filtro.nivel_minimo]}) en las dimensiones prioritarias del puesto.`
                : `No alcanza el nivel mínimo (${NIVEL_MINIMO[resultado.filtro.nivel_minimo]}) en: ${[...resultado.filtro.incumplidas, ...resultado.filtro.sin_resultado].join(', ')}.`}
              description={resultado.filtro.cumple ? undefined : 'Es una alerta para RR. HH.: la prueba es de tamizaje y no descarta por sí sola. Decide con la entrevista y las referencias.'}
            />
          )}
          {resultado.validez?.alerta && (
            <Alert
              showIcon
              type="warning"
              message={`${resultado.validez.nombre}: ${resultado.validez.suma} (≥ ${resultado.validez.alerta_desde})`}
              description="La persona pudo responder buscando dar buena imagen. Interpreta el perfil con cautela y verifícalo en la entrevista."
            />
          )}

          <Table
            size="small"
            rowKey={(d) => d.codigo ?? d.nombre}
            pagination={false}
            dataSource={resultado.dimensiones}
            scroll={{ x: 560 }}
            columns={[
              {
                title: 'Dimensión',
                render: (_, d) => (
                  <span>
                    {d.prioritaria && <StarFilled className="mr-1 text-agento-blue" aria-label="Prioritaria para el puesto" />}
                    {d.nombre}
                  </span>
                ),
              },
              {
                title: 'Puntaje',
                width: 90,
                align: 'center',
                // Conocimientos: porcentaje de aciertos; psicométrica: puntaje bruto de la guía.
                render: (_, d) => (prueba.tipo === 'conocimientos' ? (d.puntaje != null ? `${d.puntaje} %` : '—') : d.suma ?? '—'),
              },
              {
                title: 'Nivel',
                width: 90,
                render: (_, d) => (d.nivel ? <Tag color={NIVELES_PSICOMETRICOS[d.nivel].color}>{NIVELES_PSICOMETRICOS[d.nivel].label}</Tag> : '—'),
              },
              { title: 'Interpretación', render: (_, d) => <span className="text-xs text-gray-600">{d.interpretacion ?? '—'}</span> },
            ]}
          />
          {resultado.validez && !resultado.validez.alerta && (
            <p className="text-xs text-gray-500">{resultado.validez.nombre}: {resultado.validez.suma} (sin alerta). Puntaje general: {intento?.puntaje ?? '—'}.</p>
          )}

          {aProfundizar.length > 0 && (
            <div>
              <p className="mb-1 text-sm font-medium">Preguntas sugeridas para la entrevista</p>
              <ul className="list-disc space-y-0.5 pl-5 text-sm text-gray-700">
                {aProfundizar.map((d) => <li key={d.codigo ?? d.nombre}><span className="text-gray-500">{d.nombre}:</span> {d.pregunta_entrevista}</li>)}
              </ul>
            </div>
          )}

          <div className="rounded-lg bg-gray-50 p-3">
            <p className="mb-2 text-sm font-medium">Conclusión del evaluador</p>
            {resultado.conclusion && (
              <p className="mb-2 text-sm">
                <Tag color={conclusion?.color}>{conclusion?.label ?? resultado.conclusion.valor}</Tag>
                <span className="text-xs text-gray-500">{resultado.conclusion.por} · {dayjs(resultado.conclusion.at).format('DD/MM/YYYY HH:mm')}</span>
                {resultado.conclusion.comentario && <span className="mt-1 block whitespace-pre-line text-gray-700">{resultado.conclusion.comentario}</span>}
              </p>
            )}
            {puedeConcluir && (
              <Form
                form={form}
                layout="vertical"
                initialValues={{ conclusion: resultado.conclusion?.valor, comentario: resultado.conclusion?.comentario }}
              >
                <Form.Item name="conclusion" label="Conclusión" rules={[{ required: true, message: 'Elige una conclusión.' }]} className="mb-2!">
                  <Select options={CONCLUSIONES_PRUEBA} placeholder="Selecciona" />
                </Form.Item>
                <Form.Item name="comentario" label="Comentarios y aspectos a profundizar en entrevista" className="mb-2!">
                  <Input.TextArea rows={2} maxLength={2000} />
                </Form.Item>
                <Button type="primary" onClick={guardar} loading={guardando}>
                  {resultado.conclusion ? 'Actualizar conclusión' : 'Registrar conclusión'}
                </Button>
              </Form>
            )}
          </div>
          <p className="text-xs text-gray-400">Herramienta de apoyo y tamizaje: no sustituye la entrevista ni la verificación de referencias. Los niveles son orientativos.</p>
        </>
      )}
    </div>
  );
}
