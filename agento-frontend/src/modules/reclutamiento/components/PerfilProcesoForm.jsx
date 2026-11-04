import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, App, Button, Form, Input, InputNumber, Select } from 'antd';
import { useEffect, useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';

const suma = (lista, campo) => (lista ?? []).reduce((total, item) => total + (Number(item?.[campo]) || 0), 0);

/**
 * Perfil versionado del proceso: guardar crea una versión nueva; las
 * evaluaciones ya registradas conservan la versión con que se calcularon.
 */
export default function PerfilProcesoForm({ perfil, editable, onGuardar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [guardando, setGuardando] = useState(false);
  const competencias = Form.useWatch('competencias', form);
  const pesos = Form.useWatch('pesos', form);
  const sumaCompetencias = suma(competencias, 'peso');
  const sumaPesos = (Number(pesos?.ats) || 0) + (Number(pesos?.psicometria) || 0) + (Number(pesos?.entrevistas) || 0);

  useEffect(() => {
    form.setFieldsValue({
      umbral_ats: perfil?.umbral_ats ?? 60,
      pesos: perfil?.pesos ?? { ats: 40, psicometria: 30, entrevistas: 30 },
      requisitos: perfil?.requisitos ?? [],
      competencias: perfil?.competencias?.length ? perfil.competencias : [{ nivel: 3, peso: 100 }],
    });
  }, [perfil, form]);

  const guardar = async () => {
    const valores = await form.validateFields();
    setGuardando(true);
    try {
      await onGuardar(valores);
      message.success('Perfil guardado como nueva versión.');
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Form form={form} layout="vertical" disabled={!editable}>
      <Alert
        type="info"
        showIcon
        className="mb-4"
        message={`Versión vigente: ${perfil?.version ?? '—'}. ATS "ats-v1": un excluyente incumplido da 0; competencias = 90 pts (o 100 sin deseables) ponderadas por peso; deseables suman hasta 10 pts. El puntaje recomienda, no descarta.`}
      />
      <div className="grid grid-cols-2 gap-x-4 sm:grid-cols-4">
        <Form.Item name="umbral_ats" label="Umbral ATS" rules={[{ required: true }]}>
          <InputNumber min={0} max={100} className="w-full!" />
        </Form.Item>
        <Form.Item name={['pesos', 'ats']} label="Peso ATS" rules={[{ required: true }]}>
          <InputNumber min={0} max={100} className="w-full!" />
        </Form.Item>
        <Form.Item name={['pesos', 'psicometria']} label="Peso psicometría" rules={[{ required: true }]}>
          <InputNumber min={0} max={100} className="w-full!" />
        </Form.Item>
        <Form.Item name={['pesos', 'entrevistas']} label="Peso entrevistas" rules={[{ required: true }]}>
          <InputNumber min={0} max={100} className="w-full!" />
        </Form.Item>
      </div>
      {sumaPesos !== 100 && <Alert type="warning" showIcon className="mb-4" message={`Los pesos del puntaje final suman ${sumaPesos}; deben sumar 100.`} />}

      <p className="mb-2 text-sm font-medium text-gray-700">Requisitos</p>
      <Form.List name="requisitos">
        {(campos, { add, remove }) => (
          <div className="mb-4 space-y-2">
            {campos.map((campo) => (
              <div key={campo.key} className="flex flex-wrap items-start gap-2">
                <Form.Item name={[campo.name, 'tipo']} className="mb-0! w-36" rules={[{ required: true }]}>
                  <Select aria-label="Tipo de requisito" options={[{ value: 'excluyente', label: 'Excluyente' }, { value: 'deseable', label: 'Deseable' }]} />
                </Form.Item>
                <Form.Item name={[campo.name, 'descripcion']} className="mb-0! min-w-60 flex-1" rules={[{ required: true, message: 'Describe el requisito' }]}>
                  <Input maxLength={300} aria-label="Descripción del requisito" />
                </Form.Item>
                <Button icon={<DeleteOutlined />} aria-label="Quitar requisito" onClick={() => remove(campo.name)} />
              </div>
            ))}
            <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ tipo: 'excluyente' })}>Agregar requisito</Button>
          </div>
        )}
      </Form.List>

      <p className="mb-2 text-sm font-medium text-gray-700">Competencias (nivel 1–5 y peso; los pesos suman 100)</p>
      <Form.List name="competencias">
        {(campos, { add, remove }) => (
          <div className="mb-2 space-y-2">
            {campos.map((campo) => (
              <div key={campo.key} className="flex flex-wrap items-start gap-2">
                <Form.Item name={[campo.name, 'nombre']} className="mb-0! min-w-48 flex-1" rules={[{ required: true, message: 'Nombre' }]}>
                  <Input maxLength={100} aria-label="Competencia" />
                </Form.Item>
                <Form.Item name={[campo.name, 'nivel']} className="mb-0!" rules={[{ required: true, message: 'Nivel' }]}>
                  <InputNumber min={1} max={5} addonBefore="Nivel" aria-label="Nivel" />
                </Form.Item>
                <Form.Item name={[campo.name, 'peso']} className="mb-0!" rules={[{ required: true, message: 'Peso' }]}>
                  <InputNumber min={1} max={100} addonBefore="Peso" aria-label="Peso" />
                </Form.Item>
                <Button icon={<DeleteOutlined />} aria-label="Quitar competencia" onClick={() => remove(campo.name)} disabled={campos.length === 1} />
              </div>
            ))}
            <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ nivel: 3, peso: 0 })}>Agregar competencia</Button>
          </div>
        )}
      </Form.List>
      {sumaCompetencias !== 100 && <Alert type="warning" showIcon className="mb-4" message={`Los pesos de las competencias suman ${sumaCompetencias}; deben sumar 100.`} />}

      <Form.Item name="motivo" label="Motivo del cambio (opcional)">
        <Input maxLength={500} />
      </Form.Item>
      {editable && (
        <Button type="primary" onClick={guardar} loading={guardando} disabled={sumaPesos !== 100 || sumaCompetencias !== 100}>
          Guardar nueva versión
        </Button>
      )}
    </Form>
  );
}
