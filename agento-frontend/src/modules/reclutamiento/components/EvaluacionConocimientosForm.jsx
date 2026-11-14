import { Alert, App, Button, Card, Form, InputNumber, Radio, Select } from 'antd';
import { useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { useAreasPruebas, usePruebas } from '../hooks/useSeleccion';

const FILTRO_CONOCIMIENTOS = { estado: 'publicada', tipo: 'conocimientos', per_page: 100 };

/**
 * Prueba de conocimientos del proceso: se elige por profesión o área, se
 * envía sola al postular o al pasar el filtro de CV, y quien no alcanza el
 * puntaje mínimo queda marcado para RR. HH. (no se descarta solo).
 */
export default function EvaluacionConocimientosForm({ procesoId, configuracion, editable, onGuardar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const pruebas = usePruebas(FILTRO_CONOCIMIENTOS);
  const areas = useAreasPruebas();
  const [area, setArea] = useState(configuracion?.prueba?.area_conocimiento ?? undefined);
  const [guardando, setGuardando] = useState(false);
  const familia = Form.useWatch('prueba_familia', form);

  const opciones = (pruebas.datos?.data ?? [])
    .filter((p) => !area || p.area_conocimiento === area || p.familia === familia)
    .map((p) => ({
      value: p.familia,
      label: `${p.nombre} v${p.version}${p.area_conocimiento ? ` · ${p.area_conocimiento}` : ''}${p.origen === 'agento' ? ' · Banco Agento' : ''}`,
    }));

  const guardar = async () => {
    const { prueba_familia: pruebaFamilia, momento, puntaje_minimo: puntajeMinimo } = form.getFieldsValue();
    setGuardando(true);
    try {
      await onGuardar(procesoId, { prueba_familia: pruebaFamilia ?? null, momento, puntaje_minimo: puntajeMinimo ?? null });
      message.success(pruebaFamilia ? 'Prueba de conocimientos configurada.' : 'El proceso ya no envía prueba de conocimientos.');
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Card size="small" title="Prueba de conocimientos" className="mb-4">
      <Form
        form={form}
        layout="vertical"
        disabled={!editable}
        initialValues={{
          prueba_familia: configuracion?.prueba_familia,
          momento: configuracion?.momento ?? 'filtro_cv',
          puntaje_minimo: configuracion?.puntaje_minimo ?? undefined,
        }}
      >
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
          <Form.Item label="Profesión o área del puesto">
            <Select
              allowClear
              showSearch
              placeholder="Todas"
              value={area}
              onChange={setArea}
              loading={areas.cargando}
              options={(areas.datos ?? []).map((a) => ({ value: a, label: a }))}
            />
          </Form.Item>
          <Form.Item name="prueba_familia" label="Prueba que se envía" extra="Sin prueba, no se envía nada.">
            <Select
              allowClear
              placeholder="Sin prueba de conocimientos"
              loading={pruebas.cargando}
              options={opciones}
              notFoundContent={area ? `No hay pruebas publicadas de ${area}` : 'No hay pruebas de conocimientos publicadas'}
            />
          </Form.Item>
        </div>

        {familia && (
          <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <Form.Item name="momento" label="¿Cuándo se envía?">
              <Radio.Group
                className="flex! flex-col! gap-1"
                options={[
                  { value: 'filtro_cv', label: 'Al pasar el filtro de CV (solo a quienes avanzan)' },
                  { value: 'postular', label: 'Al postular (a todos)' },
                ]}
              />
            </Form.Item>
            <Form.Item name="puntaje_minimo" label="Puntaje mínimo (opcional)" extra="Quien no lo alcance queda marcado; RR. HH. decide si continúa.">
              <InputNumber min={0} max={100} addonAfter="%" className="w-full!" placeholder="Ej. 60" />
            </Form.Item>
          </div>
        )}
        {familia && configuracion?.prueba_familia !== familia && (
          <Alert type="info" showIcon className="mb-3" message="Aplica a quienes postulen o avancen desde ahora. A los candidatos actuales puedes asignarla desde su ficha." />
        )}
        {editable && <Button type="primary" onClick={guardar} loading={guardando}>Guardar prueba de conocimientos</Button>}
      </Form>
    </Card>
  );
}
