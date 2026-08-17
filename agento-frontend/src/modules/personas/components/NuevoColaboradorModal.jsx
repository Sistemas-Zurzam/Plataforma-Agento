import {
  BankOutlined,
  ClockCircleOutlined,
  IdcardOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { App, Button, Checkbox, DatePicker, Form, Input, InputNumber, Modal, Select, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import TelefonoInput from '../../../components/TelefonoInput';
import AreaSelect from '../../configuracion/components/AreaSelect';
import SedeSelect from '../../configuracion/components/SedeSelect';
import { REGIMEN_OPTIONS } from '../../configuracion/constants/regimenLaboral';
import HorarioFormModal from '../../asistencia/components/HorarioFormModal';
import { useHorarios } from '../../asistencia/hooks/useHorarios';
import CalendarioInicialColaborador, { siguienteTipoCiclo } from './CalendarioInicialColaborador';
import { useColaboradores } from '../hooks/useColaboradores';
import {
  BANCO_OPTIONS,
  MODALIDAD_TRABAJO_OPTIONS,
  MONEDA_OPTIONS,
  PERIODICIDAD_OPTIONS,
  SISTEMA_PREVISIONAL_OPTIONS,
  TIPO_CONTRATO_OPTIONS,
  TIPO_CUENTA_OPTIONS,
  TIPO_DOCUMENTO_OPTIONS,
  TIPO_TRABAJADOR_OPTIONS,
} from '../constants/opciones';

const CREAR_HORARIO = '__crear_horario__';

/**
 * Valida un TelefonoInput ("+51 999999999"): con el required genérico no
 * alcanza, porque elegir un país sin escribir el número todavía deja un
 * valor no-vacío ("+51 ") que pasaría esa regla sin tener número real.
 */
const reglaTelefonoRequerido = {
  validator: (_, value) => {
    const numero = (value ?? '').split(' ').slice(1).join(' ').trim();
    return numero ? Promise.resolve() : Promise.reject(new Error('Requerido'));
  },
};

/**
 * Todas las labels pasan por acá: en una grilla de 3 columnas, algunas
 * ("Celular del colaborador", "Modalidad de trabajo", etc.) envolvían a 2
 * líneas y desalineaban verticalmente el resto de la fila. Truncar a una
 * sola línea (con el texto completo en el tooltip) garantiza que todas las
 * filas midan lo mismo sin importar el largo de cada label.
 */
function campoLabel(texto) {
  return (
    <span className="block truncate" title={texto}>
      {texto}
    </span>
  );
}

/**
 * Controlado por el Form.Item que lo envuelve (recibe value/onChange como
 * props, igual que AreaSelect): intercepta la opción "+ Crear nuevo
 * horario" internamente en vez de dejar que Form.Item enganche su propio
 * onChange directo sobre el <Select>, que lo sobrescribiría.
 */
function HorarioSelect({ value, onChange, horarios, disabled, puedeCrear, onCrearNuevo }) {
  const handleChange = (seleccionado) => {
    if (seleccionado === CREAR_HORARIO) {
      onCrearNuevo();
      return;
    }
    onChange(seleccionado);
  };

  return (
    <Select
      placeholder={horarios.length ? 'Selecciona un horario' : 'No hay horarios disponibles'}
      value={value}
      onChange={handleChange}
      disabled={disabled}
      options={[
        ...horarios.map((h) => ({ value: h.id, label: h.nombre })),
        ...(puedeCrear ? [{ value: CREAR_HORARIO, label: '+ Crear nuevo horario' }] : []),
      ]}
    />
  );
}

/**
 * Sección siempre visible (no colapsable), en grilla de 3 columnas de
 * campos para minimizar la altura total — junto con las 4 secciones
 * acomodadas en 2×2 en el modal, esto evita necesitar scroll en la
 * mayoría de pantallas.
 */
function Seccion({ icono, titulo, subtitulo, children }) {
  return (
    <div className="rounded-xl border border-gray-100 p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-sm font-semibold text-gray-900">
          <span className="text-agento-blue">{icono}</span>
          {titulo}
        </div>
        {subtitulo && <span className="truncate text-xs text-gray-400">{subtitulo}</span>}
      </div>
      <div className="flex flex-col gap-2">{children}</div>
    </div>
  );
}

export default function NuevoColaboradorModal({ open, user, onSubmit, onCancel, submitting }) {
  const [form] = Form.useForm();
  const { message } = App.useApp();
  const { horarios, fetchHorarios, crearHorario } = useHorarios();
  const { fetchCalendarioDefecto } = useColaboradores();
  const [horarioModalOpen, setHorarioModalOpen] = useState(false);
  const [creandoHorario, setCreandoHorario] = useState(false);
  const [paso, setPaso] = useState(1);
  const [calendarioDias, setCalendarioDias] = useState([]);
  const [calendarioDiasOriginal, setCalendarioDiasOriginal] = useState([]);
  const [cargandoCalendario, setCargandoCalendario] = useState(false);

  const empresaId = user?.empresa?.id;
  const puedeCrearArea = user?.permisos?.includes('areas.crear');
  const puedeCrearHorario = user?.permisos?.includes('horarios.crear');
  const tipoContrato = Form.useWatch('tipo_contrato', form);
  const periodicidadOptions = tipoContrato === 'locacion_servicios'
    ? PERIODICIDAD_OPTIONS
    : PERIODICIDAD_OPTIONS.filter((opcion) => opcion.value === 'mensual');
  const horarioSeleccionado = horarios.find((h) => h.id === form.getFieldValue('horario_id'));

  useEffect(() => {
    if (open) {
      form.resetFields();
      form.setFieldsValue({
        pais_residencia: 'Perú',
        contabilizar_tardanzas: true,
        contabilizar_horas_extra: true,
        moneda_salario: 'PEN',
        moneda_cuenta: 'PEN',
        periodicidad_pago: 'mensual',
        fecha_ingreso: dayjs(),
      });
      fetchHorarios(1, 100, '', 'activo');
      setPaso(1);
      setCalendarioDias([]);
      setCalendarioDiasOriginal([]);
    }
  }, [open, form, fetchHorarios]);

  const handleCrearHorario = async (values) => {
    setCreandoHorario(true);
    try {
      const nuevoHorario = await crearHorario(values);
      await fetchHorarios(1, 100, '', 'activo');
      form.setFieldValue('horario_id', nuevoHorario.id);
      setHorarioModalOpen(false);
      message.success('Horario creado correctamente');
    } catch {
      message.error('No se pudo crear el horario');
    } finally {
      setCreandoHorario(false);
    }
  };

  const handleSiguiente = async () => {
    let valores;
    try {
      valores = await form.validateFields();
    } catch {
      return; // AntD ya marca los campos inválidos.
    }

    setCargandoCalendario(true);
    try {
      const dias = await fetchCalendarioDefecto(valores.horario_id, valores.fecha_ingreso.format('YYYY-MM-DD'));
      setCalendarioDias(dias);
      setCalendarioDiasOriginal(dias);
      setPaso(2);
    } catch {
      message.error('No se pudo cargar el calendario inicial');
    } finally {
      setCargandoCalendario(false);
    }
  };

  const handleCambiarDia = (fecha) => {
    setCalendarioDias((prev) =>
      prev.map((dia) =>
        dia.fecha === fecha && dia.editable && dia.tipo !== 'feriado'
          ? { ...dia, tipo: siguienteTipoCiclo(dia.tipo) }
          : dia,
      ),
    );
  };

  const handleBulkSet = (tipo) => {
    setCalendarioDias((prev) =>
      prev.map((dia) => (dia.editable && dia.tipo !== 'feriado' ? { ...dia, tipo } : dia)),
    );
  };

  const handleRestablecer = () => setCalendarioDias(calendarioDiasOriginal);

  const handleRegistrar = async () => {
    let valores;
    try {
      valores = await form.validateFields();
    } catch {
      setPaso(1);
      return;
    }

    const calendario = calendarioDias
      .filter((dia) => dia.editable || dia.tipo === 'feriado')
      .map((dia) => ({ fecha: dia.fecha, tipo: dia.tipo }));

    onSubmit({
      ...valores,
      fecha_nacimiento: valores.fecha_nacimiento ? valores.fecha_nacimiento.format('YYYY-MM-DD') : null,
      fecha_ingreso: valores.fecha_ingreso.format('YYYY-MM-DD'),
      fecha_fin_contrato: valores.fecha_fin_contrato ? valores.fecha_fin_contrato.format('YYYY-MM-DD') : null,
      calendario,
    });
  };

  const footer =
    paso === 1
      ? [
          <Button key="cancelar" onClick={onCancel}>
            Cancelar
          </Button>,
          <Button key="siguiente" type="primary" loading={cargandoCalendario} onClick={handleSiguiente}>
            Siguiente
          </Button>,
        ]
      : [
          <Button key="atras" onClick={() => setPaso(1)}>
            Atrás
          </Button>,
          <Button key="cancelar" onClick={onCancel}>
            Cancelar
          </Button>,
          <Button key="registrar" type="primary" loading={submitting} onClick={handleRegistrar}>
            Registrar colaborador
          </Button>,
        ];

  return (
    <Modal
      title={
        <div className="flex items-center gap-2">
          Nuevo Colaborador
          <Tag>Paso {paso} de 2</Tag>
        </div>
      }
      open={open}
      onCancel={onCancel}
      footer={footer}
      width={paso === 1 ? 1300 : 760}
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" size="small">
        <div
          className="grid max-h-[85vh] grid-cols-2 items-start gap-3 overflow-y-auto pr-1"
          style={{ display: paso === 1 ? 'grid' : 'none' }}
        >
          <Seccion icono={<IdcardOutlined />} titulo="Información personal">
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Nombres')}
                name="nombres"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Input placeholder="Ej: JUAN" />
              </Form.Item>
              <Form.Item
                label={campoLabel('Apellidos')}
                name="apellidos"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Input placeholder="Ej: PÉREZ" />
              </Form.Item>
              <Form.Item
                label={campoLabel('Tipo de documento')}
                name="tipo_documento"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Select placeholder="Selecciona" options={TIPO_DOCUMENTO_OPTIONS} />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Número de documento')}
                name="numero_documento"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Input placeholder="12345678" />
              </Form.Item>
              <Form.Item label={campoLabel('Fecha de nacimiento')} name="fecha_nacimiento">
                <DatePicker className="w-full" format="DD/MM/YYYY" />
              </Form.Item>
              <Form.Item label={campoLabel('País de residencia')} name="pais_residencia">
                <Input />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item label={campoLabel('Ciudad de residencia')} name="ciudad_residencia">
                <Input placeholder="Ej: Lima" />
              </Form.Item>
              <Form.Item label={campoLabel('Distrito de residencia')} name="distrito_residencia">
                <Input placeholder="Ej: Miraflores" />
              </Form.Item>
              <Form.Item label={campoLabel('Dirección')} name="direccion">
                <Input placeholder="Av. / Calle, número" />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Email')}
                name="email"
                rules={[{ type: 'email', message: 'Email inválido' }]}
              >
                <Input placeholder="ejemplo@correo.com" />
              </Form.Item>
              <Form.Item
                label={campoLabel('Celular del colaborador')}
                name="celular_colaborador"
                rules={[reglaTelefonoRequerido]}
              >
                <TelefonoInput placeholder="999999999" />
              </Form.Item>
              <Form.Item
                label={campoLabel('Celular de referencia')}
                name="celular_referencia"
                rules={[reglaTelefonoRequerido]}
              >
                <TelefonoInput placeholder="999999999" />
              </Form.Item>
            </div>
          </Seccion>

          <Seccion icono={<BankOutlined />} titulo="Información de contrato" subtitulo={user?.empresa?.nombre}>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Sede')}
                name="sede_id"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <SedeSelect key={empresaId} empresaId={empresaId} />
              </Form.Item>
              <Form.Item
                label={campoLabel('Área')}
                name="area_id"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <AreaSelect key={empresaId} empresaId={empresaId} puedeCrear={puedeCrearArea} />
              </Form.Item>
              <Form.Item
                label={campoLabel('Cargo')}
                name="cargo"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Input placeholder="Ej: Analista" />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Tipo de Contrato')}
                name="tipo_contrato"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Select
                  options={TIPO_CONTRATO_OPTIONS}
                  placeholder="Selecciona"
                  onChange={(valor) => {
                    if (valor !== 'locacion_servicios') form.setFieldValue('periodicidad_pago', 'mensual');
                  }}
                />
              </Form.Item>
              <Form.Item label={campoLabel('Régimen laboral')} name="regimen_laboral">
                <Select allowClear placeholder="Sin especificar" options={REGIMEN_OPTIONS} />
              </Form.Item>
              <Form.Item
                label={campoLabel('Tipo de trabajador')}
                name="tipo_trabajador"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Select options={TIPO_TRABAJADOR_OPTIONS} placeholder="Selecciona" />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Fecha de Ingreso')}
                name="fecha_ingreso"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <DatePicker className="w-full" format="DD/MM/YYYY" />
              </Form.Item>
              <Form.Item
                label={campoLabel('Fin de contrato')}
                name="fecha_fin_contrato"
                rules={[{
                  required: tipoContrato === 'plazo_fijo',
                  message: 'Requerido para contratos a plazo fijo',
                }]}
              >
                <DatePicker className="w-full" format="DD/MM/YYYY" placeholder="Opcional" />
              </Form.Item>
              <Form.Item
                label={campoLabel('Periodicidad')}
                name="periodicidad_pago"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Select placeholder="Selecciona" options={periodicidadOptions} />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item label={campoLabel('Salario')} required>
                <div className="flex gap-1">
                  <Form.Item name="moneda_salario" noStyle rules={[{ required: true }]}>
                    <Select className="w-20" options={MONEDA_OPTIONS.map((o) => ({ ...o, label: o.value }))} />
                  </Form.Item>
                  <Form.Item name="salario" noStyle rules={[{ required: true, message: 'Requerido' }]}>
                    <InputNumber min={0} step={0.01} className="w-full" />
                  </Form.Item>
                </div>
              </Form.Item>
              <Form.Item
                label={<span className="invisible">.</span>}
                name="contabilizar_tardanzas"
                valuePropName="checked"
              >
                <Checkbox>Contabilizar tardanzas</Checkbox>
              </Form.Item>
              <Form.Item
                label={<span className="invisible">.</span>}
                name="contabilizar_horas_extra"
                valuePropName="checked"
              >
                <Checkbox>Contabilizar horas extra</Checkbox>
              </Form.Item>
            </div>
          </Seccion>

          <Seccion icono={<WalletOutlined />} titulo="Información remunerativa">
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item label={campoLabel('CTS')} name="cts_cuenta">
                <Input placeholder="Cuenta / entidad CTS" />
              </Form.Item>
              <Form.Item label={campoLabel('Asignación Familiar')} name="asignacion_familiar">
                <InputNumber min={0} step={0.01} className="w-full" placeholder="0.00" />
              </Form.Item>
              <Form.Item label={campoLabel('Sistema previsional')} name="sistema_previsional">
                <Select allowClear placeholder="Sin especificar" options={SISTEMA_PREVISIONAL_OPTIONS} />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item label={campoLabel('Banco')} name="banco">
                <Select allowClear placeholder="Selecciona banco" options={BANCO_OPTIONS} />
              </Form.Item>
              <Form.Item label={campoLabel('Número de cuenta')} name="numero_cuenta">
                <Input placeholder="Número de cuenta" />
              </Form.Item>
              <Form.Item label={campoLabel('Tipo de cuenta')} name="tipo_cuenta">
                <Select allowClear placeholder="Selecciona" options={TIPO_CUENTA_OPTIONS} />
              </Form.Item>
            </div>
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item label={campoLabel('Moneda de la cuenta')} name="moneda_cuenta">
                <Select allowClear placeholder="Selecciona" options={MONEDA_OPTIONS} />
              </Form.Item>
              <Form.Item
                label={campoLabel('CCI (Código de Cuenta Interbancario)')}
                name="cci"
                className="col-span-2"
              >
                <Input placeholder="20 dígitos" maxLength={20} />
              </Form.Item>
            </div>
          </Seccion>

          <Seccion icono={<ClockCircleOutlined />} titulo="Información de trabajo">
            <div className="grid grid-cols-3 gap-x-3">
              <Form.Item
                label={campoLabel('Horario asignado')}
                name="horario_id"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <HorarioSelect
                  horarios={horarios}
                  puedeCrear={puedeCrearHorario}
                  onCrearNuevo={() => setHorarioModalOpen(true)}
                />
              </Form.Item>
              <Form.Item
                label={campoLabel('Modalidad de trabajo')}
                name="modalidad_trabajo"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Select placeholder="Selecciona" options={MODALIDAD_TRABAJO_OPTIONS} />
              </Form.Item>
              <Form.Item label={campoLabel('Tolerancia particular (min)')} name="tolerancia_particular_minutos">
                <InputNumber min={0} className="w-full" placeholder="Usar la del horario" />
              </Form.Item>
            </div>
          </Seccion>
        </div>
      </Form>

      {paso === 2 && (
        <CalendarioInicialColaborador
          dias={calendarioDias}
          horario={horarioSeleccionado}
          onCambiarDia={handleCambiarDia}
          onBulkSet={handleBulkSet}
          onRestablecer={handleRestablecer}
        />
      )}

      <HorarioFormModal
        open={horarioModalOpen}
        horario={null}
        onSubmit={handleCrearHorario}
        onCancel={() => setHorarioModalOpen(false)}
        submitting={creandoHorario}
      />
    </Modal>
  );
}
