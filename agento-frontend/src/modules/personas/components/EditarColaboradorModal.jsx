import { BankOutlined, IdcardOutlined, SafetyCertificateOutlined, TeamOutlined, WalletOutlined } from '@ant-design/icons';
import { Checkbox, DatePicker, Form, Input, InputNumber, Modal, Select, Tabs } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import AreaSelect from '../../configuracion/components/AreaSelect';
import SedeSelect from '../../configuracion/components/SedeSelect';
import { REGIMEN_OPTIONS } from '../../configuracion/constants/regimenLaboral';
import { useAfps } from '../../configuracion/hooks/useAfps';
import {
  BANCO_OPTIONS, CATEGORIA_TRABAJADOR_OPTIONS, MONEDA_OPTIONS, PERIODICIDAD_OPTIONS, TIPO_CONTRATO_OPTIONS, TIPO_CUENTA_OPTIONS, TIPO_DOCUMENTO_OPTIONS, TIPO_DOCUMENTO_OPTIONS_LOCADOR,
} from '../constants/opciones';

/**
 * A qué pestaña pertenece cada campo — con Tabs solo se ve una a la vez, así
 * que si "Guardar cambios" falla por un campo en una pestaña oculta, hay
 * que saltar ahí o el error queda invisible (mismo patrón que
 * NuevoColaboradorModal).
 */
const CAMPOS_POR_TAB = {
  personal: ['nombres', 'apellido_paterno', 'apellido_materno', 'tipo_documento', 'numero_documento', 'fecha_nacimiento', 'email', 'celular_colaborador', 'celular_referencia', 'direccion'],
  laboral: ['sede_id', 'area_id', 'cargo', 'tipo_contrato', 'regimen_laboral', 'categoria_trabajador', 'fecha_ingreso', 'fecha_fin_contrato', 'es_trabajador_confianza', 'contabilizar_tardanzas', 'contabilizar_faltas', 'contabilizar_horas_extra', 'condicion_vigencia_desde'],
  previsional: ['sistema_previsional', 'afp_id', 'tipo_comision', 'cuspp', 'tiene_suspension_renta_4ta'],
  remuneracion: ['salario', 'moneda_salario', 'periodicidad_pago', 'asignacion_familiar', 'vigencia_desde'],
  bancarios: ['banco', 'numero_cuenta', 'tipo_cuenta', 'moneda_cuenta', 'cci'],
};

/**
 * V3 P4/P5 — CUSPP/AFP/tipo de comisión/suspensión de 4ta viven acá Y en
 * ConfiguracionNominaModal (Remuneraciones): AMBOS escriben al mismo
 * endpoint (`PUT /colaboradores/{id}/configuracion-nomina` →
 * ColaboradorController::actualizarConfiguracionNomina(), que ya historiza
 * en ColaboradorCondicionLaboral) — no hay dos fuentes de verdad ni dos
 * reglas distintas, solo dos puntos de entrada a la MISMA lógica. No se
 * eliminó ConfiguracionNominaModal porque sigue siendo el único lugar para
 * regimen_laboral (ya vive también en la pestaña "Datos laborales" de este
 * formulario, sin duplicar) y tiene_hijos_asignacion_familiar (fuera del
 * alcance explícito de esta fase). Ese endpoint requiere el permiso
 * `nominas.gestionar_ciclos` — la pestaña completa se oculta sin él, en vez
 * de mostrar campos que el backend rechazaría con 403.
 */
export default function EditarColaboradorModal({ open, colaborador, user, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();
  const [tabActiva, setTabActiva] = useState('personal');
  const { afps, fetchAfps } = useAfps();
  const tipoContrato = Form.useWatch('tipo_contrato', form);
  const regimenLaboral = Form.useWatch('regimen_laboral', form);
  const sistemaPrevisional = Form.useWatch('sistema_previsional', form);
  const vigente = colaborador?.remuneracion;
  const puedeGestionarNomina = user?.role === 'administrador' || user?.permisos?.includes('nominas.gestionar_ciclos');
  const esHonorarios = regimenLaboral === 'Locacion de Servicios';
  const esAfp = !esHonorarios && sistemaPrevisional && sistemaPrevisional !== 'onp';

  useEffect(() => {
    if (!open || !colaborador) return;
    setTabActiva('personal');
    if (puedeGestionarNomina) fetchAfps();
    form.setFieldsValue({
      ...colaborador,
      sede_id: colaborador.sede?.id,
      area_id: colaborador.area?.id,
      fecha_nacimiento: colaborador.fecha_nacimiento ? dayjs(colaborador.fecha_nacimiento) : null,
      fecha_fin_contrato: colaborador.fecha_fin_contrato ? dayjs(colaborador.fecha_fin_contrato) : null,
      fecha_ingreso: colaborador.fecha_ingreso ? dayjs(colaborador.fecha_ingreso) : null,
      salario: vigente?.salario ?? null,
      moneda_salario: vigente?.moneda_salario ?? 'PEN',
      periodicidad_pago: vigente?.periodicidad_pago ?? 'mensual',
      asignacion_familiar: vigente?.asignacion_familiar ?? 0,
      vigencia_desde: dayjs(),
      condicion_vigencia_desde: dayjs().startOf('month'),
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, colaborador, vigente, form]);

  const guardar = (values) => {
    const {
      salario, moneda_salario, periodicidad_pago, asignacion_familiar, vigencia_desde,
      sistema_previsional, afp_id, tipo_comision, cuspp, tiene_suspension_renta_4ta,
      condicion_vigencia_desde,
      ...datosBasicos
    } = values;

    // colaborador_remuneraciones se versiona por fecha — solo se crea una
    // fila nueva ahí si de verdad cambió algo del sueldo, nunca solo porque
    // se guardó el formulario (si no, cada "Guardar cambios" ensuciaría el
    // historial aunque la persona solo haya actualizado su dirección).
    const remuneracionCambio =
      Number(salario) !== Number(vigente?.salario ?? 0) ||
      moneda_salario !== (vigente?.moneda_salario ?? 'PEN') ||
      periodicidad_pago !== (vigente?.periodicidad_pago ?? 'mensual') ||
      Number(asignacion_familiar ?? 0) !== Number(vigente?.asignacion_familiar ?? 0);

    onGuardar(
      {
        ...datosBasicos,
        fecha_nacimiento: values.fecha_nacimiento?.format('YYYY-MM-DD') ?? null,
        fecha_fin_contrato: values.fecha_fin_contrato?.format('YYYY-MM-DD') ?? null,
        fecha_ingreso: values.fecha_ingreso?.format('YYYY-MM-DD') ?? null,
        condicion_vigencia_desde: condicion_vigencia_desde?.format('YYYY-MM-DD') ?? dayjs().format('YYYY-MM-DD'),
      },
      remuneracionCambio
        ? { salario, moneda_salario, periodicidad_pago, asignacion_familiar, vigencia_desde: vigencia_desde?.format('YYYY-MM-DD') ?? dayjs().format('YYYY-MM-DD') }
        : null,
      // V3 P4/P5 — null cuando el usuario no puede gestionar nómina (la
      // pestaña ni siquiera se renderizó, no hay nada que guardar acá).
      puedeGestionarNomina
        ? { regimen_laboral: regimenLaboral, sistema_previsional, afp_id, tipo_comision, cuspp, tiene_suspension_renta_4ta }
        : null,
    );
  };

  const handleOk = async () => {
    try {
      await form.validateFields();
      form.submit();
    } catch (error) {
      const primerCampo = error.errorFields?.[0]?.name?.[0];
      const tabDelCampo = Object.entries(CAMPOS_POR_TAB).find(([, campos]) => campos.includes(primerCampo))?.[0];
      if (tabDelCampo) setTabActiva(tabDelCampo);
    }
  };

  const items = [
    {
      key: 'personal',
      forceRender: true,
      label: <span><IdcardOutlined /> Datos personales</span>,
      children: (
        <div className="grid gap-x-3 sm:grid-cols-2">
          <Form.Item label="Nombres" name="nombres" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item
            label="Apellido paterno"
            name="apellido_paterno"
            rules={[{ required: true }]}
            extra={!colaborador?.apellido_paterno ? 'Falta completar — requerido por SUNAT (PLAME)' : undefined}
          >
            <Input />
          </Form.Item>
          <Form.Item label="Apellido materno" name="apellido_materno">
            <Input />
          </Form.Item>
          <Form.Item label="Tipo de documento" name="tipo_documento" rules={[{ required: true }]}>
            <Select options={colaborador?.tipo_trabajador === 'locador' ? TIPO_DOCUMENTO_OPTIONS_LOCADOR : TIPO_DOCUMENTO_OPTIONS} />
          </Form.Item>
          <Form.Item label="Número de documento" name="numero_documento" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item label="Fecha de nacimiento" name="fecha_nacimiento"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
          <Form.Item label="Email" name="email" rules={[{ type: 'email' }]}><Input /></Form.Item>
          <Form.Item label="Celular" name="celular_colaborador" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item label="Celular de referencia" name="celular_referencia" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item label="Dirección" name="direccion" className="sm:col-span-2"><Input /></Form.Item>
        </div>
      ),
    },
    {
      key: 'laboral',
      forceRender: true,
      label: <span><TeamOutlined /> Datos laborales</span>,
      children: (
        <div className="grid gap-x-3 sm:grid-cols-2">
          <Form.Item label="Empresa"><Input value={colaborador?.empresa?.nombre_comercial} disabled /></Form.Item>
          <Form.Item label="Sede" name="sede_id" rules={[{ required: true }]}><SedeSelect empresaId={colaborador?.empresa?.id} /></Form.Item>
          <Form.Item label="Área" name="area_id" rules={[{ required: true }]}><AreaSelect empresaId={colaborador?.empresa?.id} /></Form.Item>
          <Form.Item label="Cargo" name="cargo" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item label="Tipo de contrato" name="tipo_contrato" rules={[{ required: true }]}><Select options={TIPO_CONTRATO_OPTIONS} /></Form.Item>
          <Form.Item label="Régimen laboral" name="regimen_laboral"><Select allowClear options={REGIMEN_OPTIONS} /></Form.Item>
          {colaborador?.tipo_trabajador === 'trabajador' && (
            <Form.Item
              label="Categoría laboral"
              name="categoria_trabajador"
              rules={[{ required: true, message: 'Indica si es Empleado u Obrero' }]}
              extra="Requerido por SUNAT (Tabla 8) — Empleado u Obrero."
            >
              <Select options={CATEGORIA_TRABAJADOR_OPTIONS} />
            </Form.Item>
          )}
          <Form.Item label="Fecha de ingreso" name="fecha_ingreso" rules={[{ required: true, message: 'Indica la fecha de ingreso' }]}>
            <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(fecha) => fecha?.isAfter(dayjs(), 'day')} />
          </Form.Item>
          <Form.Item
            label="Fin de contrato"
            name="fecha_fin_contrato"
            dependencies={['fecha_ingreso']}
            rules={[
              { required: tipoContrato === 'plazo_fijo', message: 'Requerido para plazo fijo' },
              ({ getFieldValue }) => ({
                validator(_, value) {
                  const ingreso = getFieldValue('fecha_ingreso');
                  return !value || !ingreso || !value.isBefore(ingreso, 'day')
                    ? Promise.resolve()
                    : Promise.reject(new Error('No puede ser anterior a la fecha de ingreso'));
                },
              }),
            ]}
          >
            <DatePicker className="w-full" format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item
            name="es_trabajador_confianza"
            valuePropName="checked"
            extra="No se le descuenta por faltas, tardanzas, horario desplazado ni horas incompletas, no se le paga horas extra — se le paga su sueldo básico completo cada período. AFP/ONP, EsSalud y renta 5ta se siguen calculando normal."
            className="sm:col-span-2"
          >
            <Checkbox>Trabajador de confianza</Checkbox>
          </Form.Item>
          {/* V3 P2 — antes era un flag huérfano (se guardaba pero
              CalcularBoletaColaborador nunca lo leía); ahora sí determina si
              la tardanza detectada tiene efecto remunerativo. */}
          <Form.Item
            name="contabilizar_tardanzas"
            valuePropName="checked"
            extra="Si está desactivado, las tardanzas seguirán registrándose en Asistencia, pero no generarán descuento en la nómina."
            className="sm:col-span-2"
          >
            <Checkbox>Contabilizar tardanzas</Checkbox>
          </Form.Item>
          <Form.Item
            name="contabilizar_faltas"
            valuePropName="checked"
            extra="Si está desactivado, las ausencias seguirán visibles en Asistencia, pero no descontarán el pago del colaborador."
            className="sm:col-span-2"
          >
            <Checkbox>Contabilizar faltas</Checkbox>
          </Form.Item>
          <Form.Item
            name="contabilizar_horas_extra"
            valuePropName="checked"
            extra="Solo se pagan horas extra aprobadas. Si está desactivado, la evidencia permanece en Asistencia sin incrementar el pago."
            className="sm:col-span-2"
          >
            <Checkbox>Contabilizar horas extra</Checkbox>
          </Form.Item>
          <Form.Item
            label="Vigencia de la condición laboral"
            name="condicion_vigencia_desde"
            rules={[{ required: true, message: 'Indica desde qué fecha aplica esta configuración' }]}
            extra="Para corregir la planilla del mes actual, usa el primer día de ese mes. No modifica boletas históricas hasta que se recalculen."
            className="sm:col-span-2"
          >
            <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(fecha) => fecha?.isAfter(dayjs(), 'day')} />
          </Form.Item>
        </div>
      ),
    },
    ...(puedeGestionarNomina ? [{
      key: 'previsional',
      forceRender: true,
      label: <span><SafetyCertificateOutlined /> Previsional</span>,
      children: (
        <div className="grid gap-x-3 sm:grid-cols-2">
          {esHonorarios ? (
            <Form.Item name="tiene_suspension_renta_4ta" valuePropName="checked" className="sm:col-span-2">
              <Checkbox>¿Presentó suspensión de retenciones de cuarta categoría?</Checkbox>
            </Form.Item>
          ) : (
            <>
              <Form.Item
                name="sistema_previsional"
                label="Sistema previsional"
                rules={[{ required: true, message: 'Selecciona ONP o una AFP' }]}
                className="sm:col-span-2"
              >
                <Select
                  options={[{ value: 'onp', label: 'ONP' }, ...afps.map((afp) => ({ value: afp.clave, label: `AFP ${afp.nombre}` }))]}
                  onChange={(value) => { if (value === 'onp') form.setFieldsValue({ afp_id: undefined, tipo_comision: undefined, cuspp: '' }); }}
                />
              </Form.Item>
              {esAfp && (
                <>
                  <Form.Item name="afp_id" label="Administradora" rules={[{ required: true, message: 'Requerido' }]}>
                    <Select options={afps.map((afp) => ({ value: afp.id, label: afp.nombre }))} />
                  </Form.Item>
                  <Form.Item name="tipo_comision" label="Tipo de comisión" rules={[{ required: true, message: 'Requerido' }]}>
                    <Select options={[{ value: 'flujo', label: 'Comisión por flujo' }, { value: 'mixta', label: 'Comisión mixta' }]} />
                  </Form.Item>
                  <Form.Item
                    name="cuspp"
                    label="CUSPP"
                    className="sm:col-span-2"
                    rules={[
                      { required: true, message: 'El CUSPP es obligatorio para AFP' },
                      { len: 12, message: 'El CUSPP debe tener exactamente 12 caracteres (requerido por PLAME)' },
                    ]}
                  >
                    <Input placeholder="Código único del SPP (12 caracteres)" maxLength={12} />
                  </Form.Item>
                </>
              )}
            </>
          )}
        </div>
      ),
    }] : []),
    {
      key: 'remuneracion',
      forceRender: true,
      label: <span><WalletOutlined /> Remuneración</span>,
      children: (
        <div>
          <p className="mb-3 text-xs text-gray-500">
            Solo se crea un registro nuevo en el historial si de verdad cambias el sueldo, la moneda, la periodicidad o la asignación familiar.
          </p>
          <div className="grid grid-cols-2 gap-x-3 sm:grid-cols-5">
            <Form.Item label="Salario" required className="sm:col-span-2">
              <div className="flex gap-1.5">
                <Form.Item name="moneda_salario" noStyle rules={[{ required: true }]}>
                  <Select style={{ width: 80, flex: '0 0 auto' }} options={MONEDA_OPTIONS.map((o) => ({ ...o, label: o.value }))} />
                </Form.Item>
                <Form.Item name="salario" noStyle rules={[{ required: true, message: 'Requerido' }]}>
                  <InputNumber min={0} step={0.01} style={{ flex: '1 1 auto', minWidth: 0 }} />
                </Form.Item>
              </div>
            </Form.Item>
            <Form.Item label="Periodicidad" name="periodicidad_pago" rules={[{ required: true }]}>
              <Select options={PERIODICIDAD_OPTIONS} />
            </Form.Item>
            <Form.Item label="Asignación familiar" name="asignacion_familiar">
              <InputNumber min={0} step={0.01} className="w-full" placeholder="0.00" />
            </Form.Item>
            <Form.Item
              label="Vigente desde"
              name="vigencia_desde"
              rules={[{ required: true }]}
              extra="Solo aplica si cambiaste el sueldo."
            >
              <DatePicker className="w-full" format="DD/MM/YYYY" />
            </Form.Item>
          </div>
        </div>
      ),
    },
    {
      key: 'bancarios',
      forceRender: true,
      label: <span><BankOutlined /> Datos bancarios</span>,
      children: (
        <div className="grid gap-x-3 sm:grid-cols-2">
          <Form.Item label="Banco" name="banco"><Select allowClear options={BANCO_OPTIONS} /></Form.Item>
          <Form.Item label="Número de cuenta" name="numero_cuenta"><Input /></Form.Item>
          <Form.Item label="Tipo de cuenta" name="tipo_cuenta"><Select allowClear options={TIPO_CUENTA_OPTIONS} /></Form.Item>
          <Form.Item label="Moneda" name="moneda_cuenta"><Select allowClear options={MONEDA_OPTIONS} /></Form.Item>
          <Form.Item label="CCI" name="cci"><Input maxLength={20} /></Form.Item>
        </div>
      ),
    },
  ];

  return (
    <Modal
      title="Editar colaborador"
      open={open}
      onCancel={onCancel}
      onOk={handleOk}
      okText="Guardar cambios"
      cancelText="Cancelar"
      confirmLoading={submitting}
      width={{ xs: '94%', sm: '90%', md: 860 }}
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" size="small" onFinish={guardar}>
        <Tabs activeKey={tabActiva} onChange={setTabActiva} items={items} />
      </Form>
    </Modal>
  );
}
