import { DeleteOutlined, InboxOutlined, PlusOutlined } from '@ant-design/icons';
import {
  Alert, App, Button, Checkbox, Form, Input, InputNumber, Radio, Result, Select, Steps, Upload,
} from 'antd';
import { useEffect, useState } from 'react';
import { TEXTO_CONSENTIMIENTO, mensajeDeError } from '../constants/seleccion';
import { usePerfilCandidato } from '../hooks/usePortalEmpleos';

const MAX_MB = 5;

const PASOS = [
  { title: 'Datos', campos: ['tipo_documento', 'numero_documento', 'nombres', 'apellido_paterno', 'celular'] },
  { title: 'Experiencia', campos: [] },
  { title: 'Requisitos y CV', campos: ['requisitos', 'cv'] },
  { title: 'Confirmación', campos: ['consentimiento'] },
];

function ListaAntecedentes({ nombre, etiqueta }) {
  return (
    <Form.List name={nombre}>
      {(campos, { add, remove }) => (
        <div className="mb-4 space-y-2">
          <p className="text-sm font-medium text-gray-700">{etiqueta}</p>
          {campos.map((campo) => (
            <div key={campo.key} className="flex flex-wrap items-start gap-2">
              <Form.Item name={[campo.name, 'titulo']} className="mb-0! min-w-48 flex-1" rules={[{ required: true, message: 'Requerido' }]}>
                <Input placeholder={nombre === 'experiencias' ? 'Puesto' : 'Grado o título'} maxLength={150} aria-label="Título" />
              </Form.Item>
              <Form.Item name={[campo.name, 'institucion']} className="mb-0! min-w-48 flex-1">
                <Input placeholder={nombre === 'experiencias' ? 'Empresa' : 'Institución'} maxLength={150} aria-label="Institución" />
              </Form.Item>
              <Button icon={<DeleteOutlined />} aria-label="Quitar" onClick={() => remove(campo.name)} />
            </div>
          ))}
          <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add()}>Agregar</Button>
        </div>
      )}
    </Form.List>
  );
}

export default function PostulacionForm({ empleo, invitacion, onPostular, onTerminar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [paso, setPaso] = useState(0);
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState(null);
  const perfil = usePerfilCandidato(true);

  useEffect(() => {
    const previo = perfil.datos?.perfil;
    if (previo) {
      form.setFieldsValue(previo);
    }
  }, [perfil.datos, form]);

  const excluyentes = (empleo.requisitos ?? []).filter((r) => r.tipo === 'excluyente');

  const siguiente = async () => {
    try {
      await form.validateFields(paso === 2 ? [...excluyentes.map((r) => ['requisitos', String(r.id)]), 'cv'] : PASOS[paso].campos);
      setPaso(paso + 1);
    } catch {
      // Los errores se muestran en cada campo.
    }
  };

  const enviar = async () => {
    try {
      await form.validateFields();
    } catch {
      message.warning('Revisa los campos pendientes.');
      return;
    }
    const v = form.getFieldsValue(true);
    const datos = new FormData();
    ['tipo_documento', 'numero_documento', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular', 'ubicacion', 'resumen', 'pretension_salarial']
      .forEach((campo) => { if (v[campo] != null && v[campo] !== '') datos.append(campo, v[campo]); });
    datos.append('experiencias', JSON.stringify(v.experiencias ?? []));
    datos.append('estudios', JSON.stringify(v.estudios ?? []));
    datos.append('habilidades', JSON.stringify((v.habilidades ?? []).filter((h) => h?.nombre)));
    datos.append('requisitos', JSON.stringify(v.requisitos ?? {}));
    datos.append('cv', v.cv[0].originFileObj);
    datos.append('consentimiento', '1');
    datos.append('consentimiento_texto', TEXTO_CONSENTIMIENTO);
    if (invitacion) datos.append('invitacion', invitacion);

    setEnviando(true);
    try {
      setResultado(await onPostular(empleo.codigo, datos));
    } catch (e) {
      if (e?.response?.status === 401) {
        message.error('Tu verificación venció. Vuelve a verificar tu correo.');
      } else {
        message.error(mensajeDeError(e));
      }
    } finally {
      setEnviando(false);
    }
  };

  if (resultado) {
    return (
      <Result
        status="success"
        title="¡Postulación enviada!"
        subTitle={<span>Tu código de seguimiento es <strong>{resultado.codigo_seguimiento}</strong>. {resultado.mensaje}</span>}
        extra={<Button type="primary" onClick={onTerminar}>Ver mis postulaciones</Button>}
      />
    );
  }

  return (
    <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-6">
      <Steps size="small" current={paso} items={PASOS.map((p) => ({ title: p.title }))} className="mb-6" responsive />
      <Form form={form} layout="vertical" initialValues={{ tipo_documento: 'dni', habilidades: [{}] }}>
        <div className={paso === 0 ? 'grid grid-cols-1 gap-x-4 sm:grid-cols-2' : 'hidden'}>
          <Form.Item name="tipo_documento" label="Tipo de documento" rules={[{ required: true }]}>
            <Select options={[{ value: 'dni', label: 'DNI' }, { value: 'ce', label: 'Carné de extranjería' }, { value: 'pasaporte', label: 'Pasaporte' }]} />
          </Form.Item>
          <Form.Item name="numero_documento" label="Número de documento" rules={[{ required: true, max: 20 }]}>
            <Input maxLength={20} />
          </Form.Item>
          <Form.Item name="nombres" label="Nombres" rules={[{ required: true }]}>
            <Input maxLength={120} autoComplete="given-name" />
          </Form.Item>
          <Form.Item name="apellido_paterno" label="Apellido paterno" rules={[{ required: true }]}>
            <Input maxLength={100} />
          </Form.Item>
          <Form.Item name="apellido_materno" label="Apellido materno">
            <Input maxLength={100} />
          </Form.Item>
          <Form.Item name="celular" label="Celular" rules={[{ required: true }]}>
            <Input maxLength={20} inputMode="tel" autoComplete="tel" />
          </Form.Item>
          <Form.Item name="ubicacion" label="Distrito / ciudad">
            <Input maxLength={150} />
          </Form.Item>
          <Form.Item name="pretension_salarial" label="Pretensión salarial (S/)">
            <InputNumber min={0} className="w-full!" />
          </Form.Item>
        </div>

        <div className={paso === 1 ? 'block' : 'hidden'}>
          <ListaAntecedentes nombre="experiencias" etiqueta="Experiencia laboral" />
          <ListaAntecedentes nombre="estudios" etiqueta="Estudios" />
          <p className="mb-2 text-sm font-medium text-gray-700">Habilidades (nivel 1 = básico, 5 = experto)</p>
          <Form.List name="habilidades">
            {(campos, { add, remove }) => (
              <div className="mb-4 space-y-2">
                {campos.map((campo) => (
                  <div key={campo.key} className="flex items-start gap-2">
                    <Form.Item name={[campo.name, 'nombre']} className="mb-0! flex-1">
                      <Input placeholder="Ej. Excel" maxLength={100} aria-label="Habilidad" />
                    </Form.Item>
                    <Form.Item name={[campo.name, 'nivel']} className="mb-0!">
                      <InputNumber min={1} max={5} placeholder="Nivel" aria-label="Nivel" />
                    </Form.Item>
                    <Button icon={<DeleteOutlined />} aria-label="Quitar habilidad" onClick={() => remove(campo.name)} />
                  </div>
                ))}
                <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add()}>Agregar habilidad</Button>
              </div>
            )}
          </Form.List>
          <Form.Item name="resumen" label="Resumen profesional">
            <Input.TextArea rows={3} maxLength={2000} />
          </Form.Item>
        </div>

        <div className={paso === 2 ? 'block' : 'hidden'}>
          {excluyentes.length > 0 && <p className="mb-2 text-sm font-medium text-gray-700">Requisitos indispensables</p>}
          {excluyentes.map((r) => (
            <Form.Item key={r.id} name={['requisitos', String(r.id)]} label={r.descripcion} rules={[{ required: true, message: 'Responde esta pregunta.' }]}>
              <Radio.Group options={[{ value: true, label: 'Sí cumplo' }, { value: false, label: 'No cumplo' }]} />
            </Form.Item>
          ))}
          <Form.Item
            name="cv"
            label={`CV en PDF (máx. ${MAX_MB} MB)`}
            valuePropName="fileList"
            getValueFromEvent={(e) => (Array.isArray(e) ? e : e?.fileList)?.slice(-1)}
            rules={[{
              validator: (_, lista) => {
                const archivo = lista?.[0]?.originFileObj;
                if (!archivo) return Promise.reject(new Error('Adjunta tu CV.'));
                if (archivo.type !== 'application/pdf') return Promise.reject(new Error('El CV debe ser PDF.'));
                if (archivo.size > MAX_MB * 1024 * 1024) return Promise.reject(new Error(`Máximo ${MAX_MB} MB.`));
                return Promise.resolve();
              },
            }]}
          >
            <Upload.Dragger accept="application/pdf" beforeUpload={() => false} maxCount={1}>
              <p className="text-2xl text-agento-blue"><InboxOutlined /></p>
              <p className="text-sm">Haz clic o arrastra tu CV aquí</p>
            </Upload.Dragger>
          </Form.Item>
        </div>

        <div className={paso === 3 ? 'block' : 'hidden'}>
          <Alert type="info" showIcon className="mb-4" message="Revisa tus datos: se guardará una copia con esta postulación. Si después actualizas tu perfil, esta postulación conserva lo que enviaste hoy." />
          <Form.Item
            name="consentimiento"
            valuePropName="checked"
            rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error('Debes aceptar para postular.'))) }]}
          >
            <Checkbox>{TEXTO_CONSENTIMIENTO}</Checkbox>
          </Form.Item>
        </div>
      </Form>
      <div className="flex justify-between gap-2">
        <Button disabled={paso === 0} onClick={() => setPaso(paso - 1)}>Anterior</Button>
        {paso < PASOS.length - 1
          ? <Button type="primary" onClick={siguiente}>Siguiente</Button>
          : <Button type="primary" loading={enviando} onClick={enviar}>Enviar postulación</Button>}
      </div>
    </div>
  );
}
