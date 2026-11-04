import { Alert, App, DatePicker, Form, Input, InputNumber, Modal, Result, Select, Switch } from 'antd';
import { useEffect, useState } from 'react';
import { TIPOS_CONTRATO, mensajeDeError } from '../constants/seleccion';
import { useOpcionesContratacion } from '../hooks/useSeleccion';

const REGIMENES = ['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios'];

/**
 * Contratación: crea (o vincula, si es reingreso) al colaborador en Personas
 * con ficha incompleta, cubre una plaza y genera tareas para Nóminas.
 * Nombres, documento, correo, cargo, área y empresa vienen de la candidatura.
 */
export default function ContratarModal({ postulacion, proceso, onContratar, onCerrar }) {
  const { message } = App.useApp();
  const [form] = Form.useForm();
  const [enviando, setEnviando] = useState(false);
  const [cesado, setCesado] = useState(null);
  const [resultado, setResultado] = useState(null);
  const opciones = useOpcionesContratacion(Boolean(postulacion));
  const tipoTrabajador = Form.useWatch('tipo_trabajador', form);
  const confianza = Form.useWatch('es_trabajador_confianza', form);

  // Se monta solo al abrirse (ver PostulacionDrawer), así el estado local
  // empieza limpio en cada contratación.
  useEffect(() => {
    if (postulacion) {
      form.resetFields();
      form.setFieldsValue({ tipo_trabajador: 'trabajador', categoria_trabajador: 'empleado', regimen_laboral: 'General', tipo_contrato: proceso?.tipo_contrato, salario: proceso?.salario_min ? Number(proceso.salario_min) : undefined });
    }
  }, [postulacion, proceso, form]);

  const contratar = async () => {
    const valores = await form.validateFields();
    setEnviando(true);
    try {
      const datos = await onContratar(postulacion.id, {
        ...valores,
        fecha_ingreso: valores.fecha_ingreso.format('YYYY-MM-DD'),
        fecha_fin_prueba: valores.fecha_fin_prueba?.format('YYYY-MM-DD'),
        fecha_fin_contrato: valores.fecha_fin_contrato?.format('YYYY-MM-DD'),
        resolucion_cesado: cesado ? 'vincular_reingreso' : undefined,
      });
      setResultado(datos);
    } catch (e) {
      if (e?.response?.status === 409 && e.response.data?.requiere_resolucion === 'cesado') {
        setCesado(e.response.data);
      } else {
        message.error(mensajeDeError(e));
      }
    } finally {
      setEnviando(false);
    }
  };

  if (resultado) {
    return (
      <Modal open title="Contratación registrada" footer={null} onCancel={() => onCerrar(true)}>
        <Result
          status="success"
          title={`${resultado.colaborador.nombre} · Legajo ${resultado.colaborador.legajo}`}
          subTitle={`Plaza ${resultado.plaza.numero} cubierta. Proceso: ${resultado.proceso.estado}.`}
        />
        {resultado.reingreso
          ? <Alert type="warning" showIcon className="mb-2" message="Reingreso vinculado: se generó una tarea para que Nóminas gestione el reingreso en Personas." />
          : resultado.ficha_incompleta && (
            <Alert type="warning" showIcon className="mb-2" message={`Ficha incompleta. Faltan: ${resultado.faltantes.map((f) => f.etiqueta).join(', ')}. Se creó una tarea para Nóminas.`} />
          )}
        <Alert
          type={resultado.onboarding.estado === 'iniciado' ? 'success' : 'info'}
          showIcon
          message={resultado.onboarding.estado === 'iniciado' ? 'Onboarding iniciado.' : `Onboarding pendiente: ${resultado.onboarding.detalle}`}
        />
      </Modal>
    );
  }

  return (
    <Modal
      open={Boolean(postulacion)}
      title={`Contratar a ${postulacion?.candidato?.nombre ?? ''}`}
      okText={cesado ? 'Vincular reingreso y contratar' : 'Contratar'}
      cancelText="Cancelar"
      onOk={contratar}
      okButtonProps={{ loading: enviando }}
      onCancel={() => onCerrar(false)}
      width={720}
      destroyOnHidden
    >
      <Alert
        type="info"
        showIcon
        className="mb-4"
        message={`Se asignará una plaza disponible de ${proceso?.codigo ?? 'este proceso'} y se dará de alta al colaborador en Personas con cargo "${proceso?.cargo ?? ''}" y área "${proceso?.area?.nombre ?? ''}".`}
      />
      {cesado && (
        <Alert
          type="warning"
          showIcon
          className="mb-4"
          message={cesado.message}
          description={`Legajo ${cesado.colaborador.legajo}, cesado el ${cesado.colaborador.fecha_cese ?? '—'}. No se creará un colaborador duplicado ni se modificará su ficha.`}
        />
      )}
      <Form form={form} layout="vertical">
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
          <Form.Item name="sede_id" label="Sede" rules={[{ required: true, message: 'Selecciona la sede.' }]}>
            <Select loading={opciones.cargando} options={(opciones.datos?.sedes ?? []).map((s) => ({ value: s.id, label: s.nombre }))} />
          </Form.Item>
          <Form.Item name="es_trabajador_confianza" label="Trabajador de confianza" valuePropName="checked">
            <Switch />
          </Form.Item>
          <Form.Item name="horario_id" label="Horario" rules={[{ required: !confianza, message: 'Selecciona el horario.' }]}>
            <Select allowClear loading={opciones.cargando} options={(opciones.datos?.horarios ?? []).map((h) => ({ value: h.id, label: `${h.nombre}${h.tipo_turno === 'rotativo' ? ' (rotativo)' : ''}` }))} />
          </Form.Item>
          <Form.Item name="dias_descanso_rotativo_por_semana" label="Descansos por semana (solo rotativo)">
            <InputNumber min={1} max={6} className="w-full!" />
          </Form.Item>
          <Form.Item name="fecha_ingreso" label="Fecha de ingreso" rules={[{ required: true, message: 'Indica la fecha de ingreso.' }]}>
            <DatePicker format="DD/MM/YYYY" className="w-full!" />
          </Form.Item>
          <Form.Item name="fecha_fin_prueba" label="Fin del período de prueba (opcional)" tooltip="Se registra en Personas con resultado “en curso”.">
            <DatePicker format="DD/MM/YYYY" className="w-full!" />
          </Form.Item>
          <Form.Item name="salario" label="Salario (S/)" rules={[{ required: true, message: 'Indica el salario.' }]}>
            <InputNumber min={0} className="w-full!" />
          </Form.Item>
          <Form.Item name="tipo_contrato" label="Tipo de contrato">
            <Select options={TIPOS_CONTRATO} />
          </Form.Item>
          <Form.Item name="fecha_fin_contrato" label="Fin de contrato (opcional)">
            <DatePicker format="DD/MM/YYYY" className="w-full!" />
          </Form.Item>
          <Form.Item name="regimen_laboral" label="Régimen laboral">
            <Select options={REGIMENES.map((r) => ({ value: r, label: r }))} />
          </Form.Item>
          <Form.Item name="tipo_trabajador" label="Tipo de trabajador" rules={[{ required: true }]}>
            <Select options={[{ value: 'trabajador', label: 'Trabajador' }, { value: 'practicante', label: 'Practicante' }, { value: 'locador', label: 'Locador' }]} />
          </Form.Item>
          {tipoTrabajador === 'trabajador' && (
            <Form.Item name="categoria_trabajador" label="Categoría" rules={[{ required: true }]}>
              <Select options={[{ value: 'empleado', label: 'Empleado' }, { value: 'obrero', label: 'Obrero' }]} />
            </Form.Item>
          )}
          <Form.Item name="celular_referencia" label="Celular de referencia" rules={[{ required: true, message: 'Requerido por Personas.' }]}>
            <Input maxLength={20} />
          </Form.Item>
        </div>
      </Form>
    </Modal>
  );
}
