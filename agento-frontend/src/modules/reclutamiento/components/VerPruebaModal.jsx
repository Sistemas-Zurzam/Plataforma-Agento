import { EditOutlined } from '@ant-design/icons';
import { Alert, Button, Collapse, Empty, Input, Modal, Segmented, Select, Skeleton, Tag } from 'antd';
import { useMemo, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { urlRecurso } from '../services/urlRecurso';

/**
 * Vista de solo lectura de una prueba: escala, dimensiones con su guía de
 * corrección y las preguntas. "Vista del evaluador" muestra la clave
 * (dimensión, invertida y valor de cada opción); "Vista del candidato"
 * muestra solo lo que ve quien la rinde.
 */
export default function VerPruebaModal({ abierto, prueba, cargando, error, onCerrar, onEditar }) {
  const [vista, setVista] = useState('evaluador');
  const [dimension, setDimension] = useState();
  const [busqueda, setBusqueda] = useState('');

  const dimensiones = useMemo(() => Object.fromEntries((prueba?.dimensiones ?? []).map((d) => [String(d.id), d])), [prueba]);
  const preguntas = (prueba?.preguntas ?? [])
    .map((p, i) => ({ ...p, numero: i + 1 }))
    .filter((p) => !dimension || p.dimension === dimension)
    .filter((p) => !busqueda || p.enunciado.toLowerCase().includes(busqueda.toLowerCase()));
  const evaluador = vista === 'evaluador';

  const cerrar = () => {
    setDimension(undefined);
    setBusqueda('');
    onCerrar();
  };

  return (
    <Modal
      open={abierto}
      title={prueba ? `${prueba.nombre} (v${prueba.version})` : 'Prueba'}
      onCancel={cerrar}
      footer={null}
      width={900}
      centered
      styles={{ body: { maxHeight: 'calc(100vh - 200px)', overflowY: 'auto', overflowX: 'hidden' } }}
      destroyOnHidden
    >
      {cargando && <Skeleton active />}
      {error && <Alert type="error" showIcon message={mensajeDeError(error, 'No se pudo cargar la prueba.')} />}
      {prueba && !cargando && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 text-sm text-gray-600">
            <Tag>{prueba.tipo === 'psicometrica' ? 'Psicométrica' : 'Conocimientos'}</Tag>
            {prueba.area_conocimiento && <Tag color="purple">{prueba.area_conocimiento}</Tag>}
            {prueba.origen === 'agento' && <Tag color="blue">Banco Agento</Tag>}
            <span>{prueba.preguntas?.length ?? 0} preguntas · {prueba.tiempo_limite_minutos} min</span>
            {onEditar && (
              <Button type="primary" size="small" icon={<EditOutlined />} className="ml-auto" onClick={() => onEditar(prueba)}>
                {prueba.estado === 'borrador' ? 'Editar' : 'Editar (nueva versión)'}
              </Button>
            )}
          </div>
          {prueba.descripcion && <p className="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{prueba.descripcion}</p>}

          <Collapse
            size="small"
            items={[
              ...(prueba.escala?.length ? [{
                key: 'escala',
                label: 'Escala de respuesta',
                children: (
                  <div className="flex flex-wrap gap-2">
                    {prueba.escala.map((e) => <Tag key={e.valor}>{e.valor} = {e.etiqueta}</Tag>)}
                  </div>
                ),
              }] : []),
              {
                key: 'dimensiones',
                label: `Dimensiones y guía de corrección (${prueba.dimensiones?.length ?? 0})`,
                children: (
                  <div className="space-y-2">
                    {(prueba.dimensiones ?? []).map((d) => (
                      <div key={d.id} className="rounded-lg border border-gray-100 p-2 text-sm">
                        <p className="font-medium">
                          {d.codigo && <Tag>{d.codigo}</Tag>}{d.nombre}
                          {d.es_validez ? <Tag color="orange" className="ml-2">Validez · alerta desde {d.alerta_desde ?? '—'}</Tag> : <span className="ml-2 text-xs text-gray-400">peso {d.peso}</span>}
                        </p>
                        {!d.es_validez && d.nivel_medio_desde != null && (
                          <p className="text-xs text-gray-500">Bajo &lt; {d.nivel_medio_desde} ≤ Medio &lt; {d.nivel_alto_desde} ≤ Alto</p>
                        )}
                        {d.interpretacion_alta && <p className="text-xs"><span className="text-gray-500">Alto:</span> {d.interpretacion_alta}</p>}
                        {d.interpretacion_baja && <p className="text-xs"><span className="text-gray-500">Bajo:</span> {d.interpretacion_baja}</p>}
                        {d.pregunta_entrevista && <p className="text-xs"><span className="text-gray-500">Entrevista:</span> {d.pregunta_entrevista}</p>}
                      </div>
                    ))}
                  </div>
                ),
              },
              ...(prueba.perfiles_puesto?.length ? [{
                key: 'perfiles',
                label: `Tipos de puesto sugeridos (${prueba.perfiles_puesto.length})`,
                children: (
                  <ul className="mb-0 space-y-1 text-sm">
                    {prueba.perfiles_puesto.map((p) => <li key={p.nombre}>{p.nombre}: {p.dimensiones.map((c) => <Tag key={c}>{c}</Tag>)}</li>)}
                  </ul>
                ),
              }] : []),
            ]}
          />

          <div className="flex flex-wrap items-center gap-2">
            <Segmented
              value={vista}
              onChange={setVista}
              options={[{ value: 'evaluador', label: 'Vista del evaluador' }, { value: 'candidato', label: 'Vista del candidato' }]}
            />
            <Select
              allowClear
              className="min-w-56"
              placeholder="Todas las dimensiones"
              value={dimension}
              onChange={setDimension}
              options={(prueba.dimensiones ?? []).map((d) => ({ value: String(d.id), label: d.codigo ? `${d.codigo} · ${d.nombre}` : d.nombre }))}
            />
            <Input.Search allowClear placeholder="Buscar en las preguntas" className="max-w-xs" onSearch={setBusqueda} onChange={(e) => !e.target.value && setBusqueda('')} />
          </div>

          {preguntas.length === 0 && <Empty description="No hay preguntas que coincidan" />}
          <ol className="m-0 list-none space-y-2 p-0">
            {preguntas.map((p) => {
              const dim = dimensiones[p.dimension];
              return (
                <li key={p.id} className="rounded-xl border border-gray-100 p-3">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <p className="mb-1 text-sm font-medium">{p.numero}. {p.enunciado}</p>
                    {evaluador && (
                      <div className="flex flex-wrap gap-1">
                        {dim && <Tag>{dim.codigo ?? dim.nombre}</Tag>}
                        {p.tipo === 'escala' ? <Tag color="blue">Escala</Tag> : <Tag color="purple">Opciones</Tag>}
                        {p.invertida && <Tag color="orange">Invertida (R)</Tag>}
                      </div>
                    )}
                  </div>
                  {p.imagen_url && <img src={urlRecurso(p.imagen_url)} alt={`Imagen de la pregunta ${p.numero}`} className="mb-2 max-h-56 max-w-full rounded-lg border border-gray-100 object-contain" />}
                  <div className={p.tipo === 'escala' ? 'flex flex-wrap gap-1' : 'space-y-1'}>
                    {(p.opciones ?? []).map((o, j) => (
                      <div key={o.id} className={`flex items-center gap-2 text-sm ${p.tipo === 'escala' ? 'rounded border border-gray-100 px-2 py-0.5' : ''}`}>
                        {p.tipo !== 'escala' && <span className="text-xs text-gray-400">{String.fromCharCode(97 + j)})</span>}
                        {o.imagen_url && <img src={urlRecurso(o.imagen_url)} alt={o.texto || `Opción ${j + 1}`} className="max-h-20 max-w-40 rounded border border-gray-100 object-contain" />}
                        {o.texto && <span>{o.texto}</span>}
                        {evaluador && <Tag className="m-0!" color={o.valor > 0 ? 'green' : 'default'}>{o.valor}</Tag>}
                      </div>
                    ))}
                  </div>
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </Modal>
  );
}
