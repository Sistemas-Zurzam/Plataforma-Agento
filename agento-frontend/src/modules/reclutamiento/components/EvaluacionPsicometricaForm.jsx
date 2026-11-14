import { Alert, App, Button, Card, Checkbox, Form, Select } from 'antd';
import { useState } from 'react';
import { mensajeDeError } from '../constants/seleccion';
import { usePruebas } from '../hooks/useSeleccion';

const FILTRO_PSICOMETRICAS = { estado: 'publicada', tipo: 'psicometrica', per_page: 100 };

const NIVELES_MINIMOS = [
  { value: 'medio', label: 'Medio o más' },
  { value: 'alto', label: 'Alto' },
];

/**
 * Evaluación psicométrica del proceso: qué prueba se envía automáticamente
 * al postular y qué dimensiones deben alcanzar el nivel mínimo. Quien no lo
 * alcanza queda marcado para RR. HH. (no se descarta solo).
 */
export default function EvaluacionPsicometricaForm({ procesoId, configuracion, editable, onGuardar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const pruebas = usePruebas(FILTRO_PSICOMETRICAS);
  const [detalle, setDetalle] = useState(configuracion?.prueba ?? null);
  const [cargandoPrueba, setCargandoPrueba] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const familia = Form.useWatch('prueba_familia', form);

  const opcionesPrueba = (pruebas.datos?.data ?? []).map((p) => ({
    value: p.familia,
    label: `${p.nombre} v${p.version}${p.origen === 'agento' ? ' · Banco Agento' : ''}`,
    id: p.id,
  }));

  const elegirPrueba = async (valor, opcion) => {
    form.setFieldsValue({ dimensiones: [], perfil: undefined });
    if (!valor) {
      setDetalle(null);
      return;
    }
    setCargandoPrueba(true);
    try {
      const prueba = await pruebas.acciones.obtener(opcion.id);
      setDetalle({
        id: prueba.id,
        nombre: prueba.nombre,
        dimensiones: prueba.dimensiones.filter((d) => !d.es_validez && d.codigo).map((d) => ({ codigo: d.codigo, nombre: d.nombre })),
        perfiles_puesto: prueba.perfiles_puesto ?? [],
      });
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo cargar la prueba.'));
    } finally {
      setCargandoPrueba(false);
    }
  };

  const guardar = async () => {
    const { prueba_familia: pruebaFamilia, dimensiones, nivel_minimo: nivelMinimo } = await form.validateFields();
    setGuardando(true);
    try {
      await onGuardar(procesoId, { prueba_familia: pruebaFamilia ?? null, dimensiones: pruebaFamilia ? dimensiones ?? [] : [], nivel_minimo: nivelMinimo ?? null });
      message.success(pruebaFamilia ? 'Evaluación configurada: se enviará a quienes postulen desde ahora.' : 'El proceso ya no envía evaluación al postular.');
    } catch (e) {
      message.error(mensajeDeError(e));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Card size="small" title="Evaluación psicológica al postular" className="mb-4">
      <Form
        form={form}
        layout="vertical"
        disabled={!editable}
        initialValues={{
          prueba_familia: configuracion?.prueba_familia,
          dimensiones: configuracion?.dimensiones ?? [],
          nivel_minimo: configuracion?.nivel_minimo ?? 'medio',
        }}
      >
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
          <Form.Item name="prueba_familia" label="Prueba que recibe cada postulante" extra="Se envía por correo y aparece en “Mis postulaciones”. Sin prueba, no se envía nada.">
            <Select
              allowClear
              placeholder="Sin evaluación"
              loading={pruebas.cargando || cargandoPrueba}
              options={opcionesPrueba}
              onChange={elegirPrueba}
              notFoundContent="No hay pruebas psicométricas publicadas"
            />
          </Form.Item>
          {familia && detalle?.perfiles_puesto?.length > 0 && (
            <Form.Item name="perfil" label="Tipo de puesto (sugerencia de la guía)">
              <Select
                allowClear
                placeholder="Elegir para marcar sus dimensiones"
                options={detalle.perfiles_puesto.map((p) => ({ value: p.nombre, label: p.nombre }))}
                onChange={(nombre) => {
                  const perfil = detalle.perfiles_puesto.find((p) => p.nombre === nombre);
                  if (perfil) form.setFieldsValue({ dimensiones: perfil.dimensiones });
                }}
              />
            </Form.Item>
          )}
        </div>

        {familia && detalle && (
          <>
            <Form.Item name="dimensiones" label="Dimensiones prioritarias para el puesto">
              <Checkbox.Group
                className="grid! grid-cols-1 gap-1 sm:grid-cols-2"
                options={detalle.dimensiones.map((d) => ({ value: d.codigo, label: d.nombre }))}
              />
            </Form.Item>
            <Form.Item name="nivel_minimo" label="Nivel mínimo en las prioritarias" className="max-w-xs">
              <Select options={NIVELES_MINIMOS} />
            </Form.Item>
            <Alert
              type="info"
              showIcon
              className="mb-3"
              message="Quien no alcance el mínimo queda marcado en el tablero y en su ficha; RR. HH. decide si continúa. La prueba es de tamizaje y no descarta por sí sola."
            />
          </>
        )}
        {editable && <Button type="primary" onClick={guardar} loading={guardando}>Guardar evaluación</Button>}
      </Form>
    </Card>
  );
}
