import {
  ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PictureOutlined, PlusOutlined, SnippetsOutlined,
} from '@ant-design/icons';
import {
  Alert, App, AutoComplete, Button, Card, Checkbox, Collapse, Form, Input, InputNumber, Modal, Select, Switch, Tooltip, Upload,
} from 'antd';
import { useEffect, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { seleccionApi } from '../services/seleccionApi';
import { urlRecurso } from '../services/urlRecurso';

const ESCALA_ACUERDO = [
  { valor: 1, etiqueta: 'Totalmente en desacuerdo' },
  { valor: 2, etiqueta: 'En desacuerdo' },
  { valor: 3, etiqueta: 'Ni de acuerdo ni en desacuerdo' },
  { valor: 4, etiqueta: 'De acuerdo' },
  { valor: 5, etiqueta: 'Totalmente de acuerdo' },
];

const TIPOS_PREGUNTA = [
  { value: 'escala', label: 'Escala' },
  { value: 'opciones', label: 'Opciones' },
];

const imagenDe = (id, url) => (id ? { id, url } : null);

/** Datos de la API o de una importación → valores del formulario. */
function aFormulario(prueba) {
  return {
    ...prueba,
    escala: prueba.escala ?? [],
    perfiles_puesto: prueba.perfiles_puesto ?? [],
    preguntas: (prueba.preguntas ?? []).map((p) => ({
      ...p,
      tipo: p.tipo ?? 'opciones',
      invertida: Boolean(p.invertida),
      imagen: imagenDe(p.imagen_id, p.imagen_url),
      opciones: (p.tipo === 'escala' ? [] : p.opciones ?? []).map((o) => ({ ...o, imagen: imagenDe(o.imagen_id, o.imagen_url) })),
    })),
  };
}

/** Valores del formulario → payload de la API. */
function aPayload(valores) {
  return {
    ...valores,
    dimensiones: (valores.dimensiones ?? []).map((d) => ({ ...d, peso: d.es_validez ? 0 : d.peso ?? 1, es_validez: Boolean(d.es_validez) })),
    preguntas: (valores.preguntas ?? []).map(({ imagen, opciones, ...p }) => ({
      ...p,
      imagen_id: imagen?.id ?? null,
      opciones: p.tipo === 'escala' ? [] : (opciones ?? []).map(({ imagen: imagenOpcion, ...o }) => ({ ...o, imagen_id: imagenOpcion?.id ?? null })),
    })),
  };
}

/**
 * Imagen opcional de una pregunta u opción: se sube al elegirla y se guarda
 * su id. Se muestra con la URL firmada que entrega el backend.
 */
function CampoImagen({ value, onChange, compacto = false }) {
  const { message } = App.useApp();
  const [subiendo, setSubiendo] = useState(false);

  const subir = async (archivo) => {
    if (archivo.size > 2 * 1024 * 1024) {
      message.error('La imagen debe pesar máximo 2 MB.');
      return false;
    }
    setSubiendo(true);
    try {
      onChange(await seleccionApi.pruebas.subirImagen(archivo));
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo subir la imagen.'));
    } finally {
      setSubiendo(false);
    }
    return false;
  };

  if (value?.url) {
    return (
      <div className="flex items-start gap-1">
        <img src={urlRecurso(value.url)} alt="" className={`${compacto ? 'h-12' : 'h-20'} rounded border border-gray-200 object-contain`} />
        <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Quitar imagen" onClick={() => onChange(null)} />
      </div>
    );
  }

  return (
    <Upload accept="image/png,image/jpeg,image/webp,image/gif" showUploadList={false} beforeUpload={subir}>
      <Tooltip title="Agregar imagen (PNG, JPG, WEBP o GIF, máx. 2 MB)">
        <Button size="small" icon={<PictureOutlined />} loading={subiendo} aria-label="Agregar imagen">{compacto ? null : 'Imagen'}</Button>
      </Tooltip>
    </Upload>
  );
}

/**
 * Editor de una prueba en borrador.
 * - Escala de la prueba (ej. 1 a 5): las afirmaciones de tipo "escala" la
 *   usan; "invertida" invierte sus valores al calificar.
 * - Preguntas de tipo "opciones": opciones propias (texto y/o imagen) con su
 *   valor; sirven para conocimientos, razonamiento o ítems visuales.
 * - Guía de corrección por dimensión y tipos de puesto sugeridos.
 * El "valor" es la clave de calificación: nunca se envía al candidato.
 */
export default function PruebaEditorModal({ abierto, prueba, inicial, advertencias = [], areas = [], puedeElegirBanco = false, onGuardar, onCerrar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const [pegar, setPegar] = useState({ abierto: false, texto: '', dimension: undefined });
  const dimensiones = Form.useWatch('dimensiones', form) ?? [];
  const preguntasVista = Form.useWatch('preguntas', form) ?? [];
  const escala = Form.useWatch('escala', form) ?? [];
  const perfilesVista = Form.useWatch('perfiles_puesto', form) ?? [];
  const opcionesDimension = dimensiones.filter((d) => d?.clave).map((d) => ({ value: d.clave, label: d.codigo ? `${d.codigo} · ${d.nombre ?? ''}` : d.nombre || d.clave }));
  const codigos = dimensiones.filter((d) => d?.codigo && !d?.es_validez).map((d) => ({ value: d.codigo.toUpperCase(), label: `${d.codigo.toUpperCase()} · ${d.nombre ?? ''}` }));
  const usaEscala = preguntasVista.some((p) => p?.tipo === 'escala');
  const tipoPrueba = Form.useWatch('tipo', form);
  const esConocimientos = tipoPrueba === 'conocimientos';
  const etiquetaDimensiones = esConocimientos ? 'Temas evaluados' : 'Dimensiones';

  useEffect(() => {
    if (!abierto) {
      return;
    }
    form.resetFields();
    const origen = prueba ?? inicial;
    form.setFieldsValue(origen ? { banco_agento: puedeElegirBanco, ...aFormulario(origen) } : {
      tipo: 'psicometrica',
      tiempo_limite_minutos: 20,
      banco_agento: puedeElegirBanco,
      escala: ESCALA_ACUERDO,
      dimensiones: [{ clave: 'd1', nombre: '', peso: 1 }],
      preguntas: [],
      perfiles_puesto: [],
    });
  }, [abierto, prueba, inicial, puedeElegirBanco, form]);

  const guardar = async () => {
    try {
      await form.validateFields();
    } catch {
      message.warning('Revisa los campos marcados en rojo.');
      return;
    }
    // Todo el contenido, incluido lo que no está a la vista (paneles
    // cerrados, peso de la escala de validez).
    const valores = aPayload(form.getFieldsValue(true));
    if (valores.preguntas.some((p) => p.tipo === 'escala') && !(valores.escala?.length >= 2)) {
      message.error('Define al menos dos puntos en la escala de la prueba.');
      return;
    }
    setGuardando(true);
    try {
      await onGuardar(valores);
      onCerrar();
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  const agregarPegadas = () => {
    const nuevas = pegar.texto.split('\n')
      .map((linea) => linea.replace(/^\s*(?:[-•*·▪●]|\d+\s*[.)-]?)\s+/u, '').trim())
      .filter((linea) => linea.length >= 3)
      .map((linea) => {
        const invertida = /\((r|invertida)\)\s*$/iu.test(linea);
        return {
          tipo: 'escala',
          enunciado: linea.replace(/\((r|invertida)\)\s*$/iu, '').trim(),
          dimension: pegar.dimension,
          invertida,
          peso: 1,
          opciones: [],
          imagen: null,
        };
      });
    if (nuevas.length === 0) {
      message.warning('Pega al menos una afirmación (una por línea).');
      return;
    }
    form.setFieldsValue({
      preguntas: [...(form.getFieldValue('preguntas') ?? []), ...nuevas],
      escala: escala.length ? escala : ESCALA_ACUERDO,
    });
    message.success(`${nuevas.length} afirmación(es) agregadas.`);
    setPegar({ abierto: false, texto: '', dimension: undefined });
  };

  const titulo = prueba ? `Editar ${prueba.nombre} (v${prueba.version})` : inicial ? 'Revisar prueba importada' : 'Nueva prueba';

  return (
    <Modal
      open={abierto}
      title={titulo}
      width={1000}
      centered
      styles={{ body: { maxHeight: 'calc(100vh - 220px)', overflowY: 'auto', overflowX: 'hidden' } }}
      onOk={guardar}
      okText="Guardar borrador"
      cancelText="Cancelar"
      okButtonProps={{ loading: guardando }}
      onCancel={onCerrar}
      destroyOnHidden
    >
      {inicial && (
        <Alert
          type={advertencias.length ? 'warning' : 'success'}
          showIcon
          className="mb-4"
          message={advertencias.length ? 'Revisa la importación antes de guardar' : 'Importación lista: revisa y guarda como borrador'}
          description={advertencias.length > 0 && <ul className="mb-0 list-disc pl-5">{advertencias.map((a) => <li key={a}>{a}</li>)}</ul>}
        />
      )}
      <Form form={form} layout="vertical">
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
          <Form.Item name="nombre" label="Nombre" className="sm:col-span-2" rules={[{ required: true, message: 'Indica el nombre.' }]}>
            <Input maxLength={150} />
          </Form.Item>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select options={[{ value: 'psicometrica', label: 'Psicométrica' }, { value: 'conocimientos', label: 'Conocimientos' }]} />
          </Form.Item>
          {esConocimientos && (
            <Form.Item
              name="area_conocimiento"
              label="Profesión o área"
              className="sm:col-span-3"
              rules={[{ required: true, message: 'Indica la profesión o área que evalúa.' }]}
              extra="Ej. Contabilidad, Diseño gráfico, Ventas, Almacén. Permite elegir la prueba que corresponde al puesto."
            >
              <AutoComplete
                options={areas.map((a) => ({ value: a }))}
                maxLength={100}
                placeholder="Escribe o elige una existente"
                filterOption={(texto, opcion) => opcion?.value?.toLowerCase().includes(texto.toLowerCase())}
              />
            </Form.Item>
          )}
          <Form.Item name="tiempo_limite_minutos" label="Tiempo límite (min)" rules={[{ required: true }]}>
            <InputNumber min={1} max={240} className="w-full!" />
          </Form.Item>
          <Form.Item name="descripcion" label="Instrucciones para el candidato" className="sm:col-span-2">
            <Input.TextArea rows={2} maxLength={2000} />
          </Form.Item>
          {!prueba && puedeElegirBanco && (
            <Form.Item name="banco_agento" label="Banco de Agento" valuePropName="checked" extra="Disponible para los procesos de todas las empresas." className="sm:col-span-3">
              <Switch />
            </Form.Item>
          )}
        </div>

        <Collapse
          className="mb-4"
          defaultActiveKey={inicial || !prueba ? ['dimensiones'] : []}
          items={[
            {
              key: 'escala',
              forceRender: true,
              label: `Escala de respuesta${escala.length ? ` (${escala.map((e) => e?.valor).join(' · ')})` : ''}`,
              extra: !usaEscala && <span className="text-xs text-gray-400">Solo para afirmaciones de escala</span>,
              children: (
                <Form.List name="escala">
                  {(puntos, { add, remove }) => (
                    <div className="space-y-2">
                      <p className="text-xs text-gray-500">Se define una vez y la usan todas las afirmaciones de escala. Una afirmación invertida se califica al revés (en 1–5: 1→5, 2→4…).</p>
                      {puntos.map((punto) => (
                        <div key={punto.key} className="flex items-start gap-2">
                          <Form.Item name={[punto.name, 'valor']} className="mb-0!" rules={[{ required: true, message: 'Valor' }]}>
                            <InputNumber min={0} max={100} addonBefore="Valor" aria-label="Valor del punto de la escala" />
                          </Form.Item>
                          <Form.Item name={[punto.name, 'etiqueta']} className="mb-0! flex-1" rules={[{ required: true, message: 'Etiqueta' }]}>
                            <Input maxLength={120} placeholder="Ej. Totalmente de acuerdo" aria-label="Etiqueta" />
                          </Form.Item>
                          <Button icon={<DeleteOutlined />} aria-label="Quitar punto" onClick={() => remove(punto.name)} />
                        </div>
                      ))}
                      <div className="flex flex-wrap gap-2">
                        <Button size="small" type="dashed" icon={<PlusOutlined />} disabled={puntos.length >= 10} onClick={() => add({ valor: puntos.length + 1 })}>Punto</Button>
                        <Button size="small" onClick={() => form.setFieldsValue({ escala: ESCALA_ACUERDO })}>Usar escala de acuerdo 1–5</Button>
                      </div>
                    </div>
                  )}
                </Form.List>
              ),
            },
            {
              key: 'dimensiones',
              forceRender: true,
              label: `${etiquetaDimensiones} (${dimensiones.length})`,
              children: (
                <Form.List name="dimensiones">
                  {(campos, { add, remove }) => (
                    <div className="space-y-2">
                      {campos.map((campo) => {
                        const esValidez = dimensiones[campo.name]?.es_validez;
                        return (
                          <Card key={campo.key} size="small">
                            <div className="flex flex-wrap items-start gap-2">
                              <Form.Item name={[campo.name, 'clave']} hidden><Input /></Form.Item>
                              <Form.Item name={[campo.name, 'codigo']} className="mb-0! w-24">
                                <Input placeholder="Código" maxLength={10} aria-label="Código de la dimensión" />
                              </Form.Item>
                              <Form.Item name={[campo.name, 'nombre']} className="mb-0! min-w-48 flex-1" rules={[{ required: true, message: 'Nombre' }]}>
                                <Input placeholder={esConocimientos ? 'Ej. Contabilidad de costos' : 'Ej. Responsabilidad y organización'} maxLength={100} aria-label={esConocimientos ? 'Tema' : 'Dimensión'} />
                              </Form.Item>
                              {!esValidez && (
                                <Form.Item name={[campo.name, 'peso']} className="mb-0!" rules={[{ required: true }]}>
                                  <InputNumber min={1} max={100} addonBefore="Peso" aria-label="Peso" />
                                </Form.Item>
                              )}
                              {!esConocimientos && (
                                <Form.Item name={[campo.name, 'es_validez']} valuePropName="checked" className="mb-0!">
                                  <Switch checkedChildren="Validez" unCheckedChildren="Rasgo" aria-label="Escala de validez" />
                                </Form.Item>
                              )}
                              <Button icon={<DeleteOutlined />} aria-label="Quitar dimensión" disabled={campos.length === 1} onClick={() => remove(campo.name)} />
                            </div>
                            <Collapse
                              ghost
                              size="small"
                              className="mt-1"
                              items={[{
                                key: 'guia',
                                label: <span className="text-xs text-gray-500">Guía de corrección</span>,
                                forceRender: true,
                                children: esValidez ? (
                                  <Form.Item name={[campo.name, 'alerta_desde']} label="Alerta desde (puntaje)" extra="Si el puntaje llega a este valor, el perfil se interpreta con cautela." className="mb-0! max-w-xs">
                                    <InputNumber min={0} className="w-full!" />
                                  </Form.Item>
                                ) : (
                                  <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                                    <Form.Item name={[campo.name, 'nivel_medio_desde']} label="Nivel medio desde (puntaje)">
                                      <InputNumber min={0} className="w-full!" />
                                    </Form.Item>
                                    <Form.Item name={[campo.name, 'nivel_alto_desde']} label="Nivel alto desde (puntaje)">
                                      <InputNumber min={0} className="w-full!" />
                                    </Form.Item>
                                    <Form.Item name={[campo.name, 'interpretacion_alta']} label="Nivel alto indica…">
                                      <Input.TextArea rows={2} maxLength={1000} />
                                    </Form.Item>
                                    <Form.Item name={[campo.name, 'interpretacion_baja']} label="Nivel bajo indica…">
                                      <Input.TextArea rows={2} maxLength={1000} />
                                    </Form.Item>
                                    <Form.Item name={[campo.name, 'pregunta_entrevista']} label="Pregunta de profundización en entrevista" className="mb-0! sm:col-span-2">
                                      <Input.TextArea rows={2} maxLength={1000} />
                                    </Form.Item>
                                  </div>
                                ),
                              }]}
                            />
                          </Card>
                        );
                      })}
                      <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ clave: `d${Date.now()}`, peso: 1, es_validez: false })}>{esConocimientos ? 'Agregar tema' : 'Agregar dimensión'}</Button>
                    </div>
                  )}
                </Form.List>
              ),
            },
            ...(esConocimientos ? [] : [{
              key: 'perfiles',
              forceRender: true,
              label: `Tipos de puesto sugeridos (${perfilesVista.length})`,
              children: (
                <Form.List name="perfiles_puesto">
                  {(perfiles, { add, remove }) => (
                    <div className="space-y-2">
                      <p className="text-xs text-gray-500">Dimensiones a priorizar por tipo de puesto. Al configurar un proceso, elegir el tipo marca sus dimensiones.</p>
                      {perfiles.map((perfil) => (
                        <div key={perfil.key} className="flex flex-wrap items-start gap-2">
                          <Form.Item name={[perfil.name, 'nombre']} className="mb-0! min-w-48 flex-1" rules={[{ required: true, message: 'Nombre' }]}>
                            <Input placeholder="Ej. Atención al cliente / recepción / caja" maxLength={120} aria-label="Tipo de puesto" />
                          </Form.Item>
                          <Form.Item name={[perfil.name, 'dimensiones']} className="mb-0! min-w-60 flex-1" rules={[{ required: true, message: 'Elige dimensiones' }]}>
                            <Select mode="multiple" options={codigos} placeholder="Dimensiones (por código)" aria-label="Dimensiones a priorizar" />
                          </Form.Item>
                          <Button icon={<DeleteOutlined />} aria-label="Quitar tipo de puesto" onClick={() => remove(perfil.name)} />
                        </div>
                      ))}
                      <Button type="dashed" icon={<PlusOutlined />} disabled={codigos.length === 0} onClick={() => add({ dimensiones: [] })}>
                        Agregar tipo de puesto
                      </Button>
                      {codigos.length === 0 && <p className="text-xs text-gray-400">Asigna un código a las dimensiones para usarlas aquí.</p>}
                    </div>
                  )}
                </Form.List>
              ),
            }]),
          ]}
        />

        <Form.List name="preguntas">
          {(preguntas, { add, remove, move }) => (
            <div className="space-y-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="mb-0 text-sm font-medium">Preguntas ({preguntas.length})</p>
                <div className="flex flex-wrap gap-2">
                  <Button size="small" icon={<SnippetsOutlined />} onClick={() => setPegar((s) => ({ ...s, abierto: true }))}>Pegar afirmaciones</Button>
                  <Button
                    size="small"
                    icon={<PlusOutlined />}
                    onClick={() => {
                      if (!escala.length) form.setFieldsValue({ escala: ESCALA_ACUERDO });
                      add({ tipo: 'escala', dimension: dimensiones[0]?.clave, invertida: false, peso: 1, opciones: [], imagen: null });
                    }}
                  >
                    Afirmación de escala
                  </Button>
                  <Button
                    size="small"
                    icon={<PlusOutlined />}
                    onClick={() => add({ tipo: 'opciones', dimension: dimensiones[0]?.clave, peso: 1, imagen: null, opciones: [{ valor: 1, imagen: null }, { valor: 0, imagen: null }] })}
                  >
                    Pregunta con opciones
                  </Button>
                </div>
              </div>
              {preguntas.length === 0 && <p className="py-4 text-center text-sm text-gray-400">Aún no hay preguntas.</p>}
              {preguntas.map((pregunta, indice) => {
                const tipo = preguntasVista[pregunta.name]?.tipo;
                return (
                  <div key={pregunta.key} className="rounded-xl border border-gray-200 p-2">
                    <div className="flex flex-wrap items-start gap-2">
                      <span className="w-6 pt-1 text-right text-xs text-gray-400">{indice + 1}</span>
                      <Form.Item name={[pregunta.name, 'tipo']} className="mb-0! w-28">
                        <Select size="small" options={TIPOS_PREGUNTA} aria-label="Tipo de pregunta" />
                      </Form.Item>
                      <Form.Item name={[pregunta.name, 'enunciado']} className="mb-0! min-w-64 flex-1" rules={[{ required: true, message: 'Escribe la pregunta' }]}>
                        <Input.TextArea autoSize={{ minRows: 1, maxRows: 4 }} maxLength={2000} placeholder={tipo === 'escala' ? 'Afirmación' : 'Pregunta'} aria-label="Enunciado" />
                      </Form.Item>
                      <Form.Item name={[pregunta.name, 'dimension']} className="mb-0! w-48" rules={[{ required: true, message: esConocimientos ? 'Tema' : 'Dimensión' }]}>
                        <Select size="small" placeholder={esConocimientos ? 'Tema' : 'Dimensión'} options={opcionesDimension} aria-label={esConocimientos ? 'Tema' : 'Dimensión'} />
                      </Form.Item>
                      {tipo === 'escala' && (
                        <Form.Item name={[pregunta.name, 'invertida']} valuePropName="checked" className="mb-0!">
                          <Checkbox>Invertida</Checkbox>
                        </Form.Item>
                      )}
                      <Form.Item name={[pregunta.name, 'imagen']} className="mb-0!">
                        <CampoImagen compacto />
                      </Form.Item>
                      <div className="flex">
                        <Button size="small" type="text" icon={<ArrowUpOutlined />} aria-label="Subir" disabled={indice === 0} onClick={() => move(indice, indice - 1)} />
                        <Button size="small" type="text" icon={<ArrowDownOutlined />} aria-label="Bajar" disabled={indice === preguntas.length - 1} onClick={() => move(indice, indice + 1)} />
                        <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Quitar pregunta" onClick={() => remove(pregunta.name)} />
                      </div>
                    </div>
                    {tipo === 'opciones' && (
                      <Form.List name={[pregunta.name, 'opciones']}>
                        {(opciones, { add: agregar, remove: quitar }) => (
                          <div className="mt-2 ml-8 space-y-1">
                            {opciones.map((opcion, i) => (
                              <div key={opcion.key} className="flex flex-wrap items-start gap-2">
                                <span className="w-5 pt-1 text-xs text-gray-400">{String.fromCharCode(97 + i)})</span>
                                <Form.Item name={[opcion.name, 'texto']} className="mb-0! min-w-48 flex-1">
                                  <Input size="small" placeholder="Texto (opcional si tiene imagen)" maxLength={500} aria-label="Texto de la opción" />
                                </Form.Item>
                                <Form.Item name={[opcion.name, 'imagen']} className="mb-0!">
                                  <CampoImagen compacto />
                                </Form.Item>
                                <Form.Item name={[opcion.name, 'valor']} className="mb-0!" rules={[{ required: true, message: 'Valor' }]}>
                                  <InputNumber size="small" min={0} max={100} addonBefore="Valor" aria-label="Valor (clave)" className="w-32!" />
                                </Form.Item>
                                <Button size="small" type="text" icon={<DeleteOutlined />} aria-label="Quitar opción" disabled={opciones.length <= 2} onClick={() => quitar(opcion.name)} />
                              </div>
                            ))}
                            <Button size="small" type="dashed" icon={<PlusOutlined />} disabled={opciones.length >= 10} onClick={() => agregar({ valor: 0, imagen: null })}>Opción</Button>
                          </div>
                        )}
                      </Form.List>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </Form.List>
      </Form>

      <Modal
        open={pegar.abierto}
        title="Pegar afirmaciones"
        okText="Agregar"
        cancelText="Cancelar"
        onOk={agregarPegadas}
        onCancel={() => setPegar({ abierto: false, texto: '', dimension: undefined })}
        destroyOnHidden
      >
        <p className="mb-2 text-sm text-gray-600">
          Copia las afirmaciones desde el PDF, Word o Excel y pégalas aquí, una por línea. Se quitan la numeración y las viñetas; termina una línea con “(R)” para marcarla como invertida.
        </p>
        <Input.TextArea
          rows={10}
          value={pegar.texto}
          onChange={(e) => setPegar((s) => ({ ...s, texto: e.target.value }))}
          placeholder={'1. Prefiero trabajar sola(o) antes que depender de un equipo. (R)\n2. Resuelvo los desacuerdos con mis compañeros mediante el diálogo.'}
        />
        <Select
          className="mt-2 w-full"
          allowClear
          placeholder="Dimensión para todas (opcional; luego puedes cambiarla una por una)"
          options={opcionesDimension}
          value={pegar.dimension}
          onChange={(dimension) => setPegar((s) => ({ ...s, dimension }))}
        />
      </Modal>
    </Modal>
  );
}
