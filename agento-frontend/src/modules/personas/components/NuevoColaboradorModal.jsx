import {
  BankOutlined,
  CameraOutlined,
  ClockCircleOutlined,
  IdcardOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Alert, App, Avatar, Button, Checkbox, DatePicker, Form, Input, InputNumber, Modal, Select, Tabs, Tag, Upload } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import TelefonoInput from '../../../components/TelefonoInput';
import AreaSelect from '../../configuracion/components/AreaSelect';
import SedeSelect from '../../configuracion/components/SedeSelect';
import { REGIMEN_OPTIONS } from '../../configuracion/constants/regimenLaboral';
import { useAfps } from '../../configuracion/hooks/useAfps';
import HorarioFormModal from '../../asistencia/components/HorarioFormModal';
import { useHorarios } from '../../asistencia/hooks/useHorarios';
import CalendarioInicialColaborador, { siguienteTipoCiclo } from './CalendarioInicialColaborador';
import { useColaboradores } from '../hooks/useColaboradores';
import {
  BANCO_OPTIONS,
  MODALIDAD_TRABAJO_OPTIONS,
  MONEDA_OPTIONS,
  PERIODICIDAD_OPTIONS,
  TIPO_CONTRATO_OPTIONS,
  TIPO_CUENTA_OPTIONS,
  TIPO_DOCUMENTO_OPTIONS,
  TIPO_TRABAJADOR_OPTIONS,
} from '../constants/opciones';

const CREAR_HORARIO = '__crear_horario__';

/**
 * A qué pestaña del Paso 1 pertenece cada campo — se usa para saltar
 * automáticamente a la pestaña correcta si "Siguiente" falla por un campo
 * que no está en la pestaña actualmente visible (Tabs solo muestra una a
 * la vez, así que sin esto un error en una pestaña oculta pasaría
 * desapercibido).
 */
const CAMPOS_POR_TAB = {
  personal: [
    'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'fecha_nacimiento',
    'pais_residencia', 'ciudad_residencia', 'distrito_residencia', 'direccion',
    'email', 'celular_colaborador', 'celular_referencia',
  ],
  contrato: [
    'sede_id', 'area_id', 'cargo', 'tipo_contrato', 'regimen_laboral', 'tipo_trabajador',
    'fecha_ingreso', 'fecha_fin_contrato', 'periodicidad_pago', 'moneda_salario', 'salario',
    'contabilizar_tardanzas', 'contabilizar_horas_extra', 'es_trabajador_confianza',
  ],
  remunerativa: ['cts_cuenta', 'asignacion_familiar', 'sistema_previsional', 'banco', 'numero_cuenta', 'tipo_cuenta', 'moneda_cuenta', 'cci'],
  trabajo: ['horario_id', 'modalidad_trabajo', 'tolerancia_particular_minutos', 'dias_descanso_rotativo_por_semana'],
};

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

export default function NuevoColaboradorModal({ open, user, onSubmit, onCancel, submitting }) {
  const [form] = Form.useForm();
  const { message } = App.useApp();
  const { horarios, fetchHorarios, crearHorario } = useHorarios();
  const { afps, fetchAfps } = useAfps();
  const { fetchCalendarioDefecto } = useColaboradores();
  const [horarioModalOpen, setHorarioModalOpen] = useState(false);
  const [creandoHorario, setCreandoHorario] = useState(false);
  const [paso, setPaso] = useState(1);
  const [calendarioDias, setCalendarioDias] = useState([]);
  const [calendarioDiasOriginal, setCalendarioDiasOriginal] = useState([]);
  const [cargandoCalendario, setCargandoCalendario] = useState(false);
  const [fotoPerfil, setFotoPerfil] = useState(null);
  const [fotoPreview, setFotoPreview] = useState(null);
  const [tabPaso1, setTabPaso1] = useState('personal');

  const empresaId = user?.empresa?.id;
  const puedeCrearArea = user?.permisos?.includes('areas.crear');
  const puedeCrearSede = user?.permisos?.includes('sedes.crear');
  const puedeCrearHorario = user?.permisos?.includes('horarios.crear');
  const tipoContrato = Form.useWatch('tipo_contrato', form);
  const periodicidadOptions = tipoContrato === 'locacion_servicios'
    ? PERIODICIDAD_OPTIONS
    : PERIODICIDAD_OPTIONS.filter((opcion) => opcion.value === 'mensual');
  const horarioSeleccionado = horarios.find((h) => h.id === form.getFieldValue('horario_id'));
  const horarioIdActivo = Form.useWatch('horario_id', form);
  const esHorarioRotativo = horarios.find((h) => h.id === horarioIdActivo)?.tipo_turno === 'rotativo';

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
      fetchAfps();
      setPaso(1);
      setTabPaso1('personal');
      setCalendarioDias([]);
      setCalendarioDiasOriginal([]);
      setFotoPerfil(null);
      setFotoPreview(null);
    }
  }, [open, form, fetchHorarios, fetchAfps]);

  useEffect(() => () => { if (fotoPreview) URL.revokeObjectURL(fotoPreview); }, [fotoPreview]);

  const handleSeleccionarFoto = (archivo) => {
    setFotoPreview(URL.createObjectURL(archivo));
    setFotoPerfil(archivo);
    return false;
  };

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

  const irATabDelPrimerError = (error) => {
    const primerCampo = error.errorFields?.[0]?.name?.[0];
    const tabDelCampo = Object.entries(CAMPOS_POR_TAB).find(([, campos]) => campos.includes(primerCampo))?.[0];
    if (tabDelCampo) setTabPaso1(tabDelCampo);
  };

  const handleSiguiente = async () => {
    let valores;
    try {
      valores = await form.validateFields();
    } catch (error) {
      irATabDelPrimerError(error); // Tabs solo muestra 1 a la vez — si no, el error queda oculto.
      return;
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
    } catch (error) {
      irATabDelPrimerError(error);
      setPaso(1);
      return;
    }

    const calendario = calendarioDias
      .filter((dia) => dia.editable || dia.tipo === 'feriado')
      .map((dia) => ({ fecha: dia.fecha, tipo: dia.tipo }));

    onSubmit(
      {
        ...valores,
        fecha_nacimiento: valores.fecha_nacimiento ? valores.fecha_nacimiento.format('YYYY-MM-DD') : null,
        fecha_ingreso: valores.fecha_ingreso.format('YYYY-MM-DD'),
        fecha_fin_contrato: valores.fecha_fin_contrato ? valores.fecha_fin_contrato.format('YYYY-MM-DD') : null,
        calendario,
      },
      fotoPerfil,
    );
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
      width={paso === 1 ? { xs: '95%', sm: '90%', lg: 820 } : { xs: '92%', sm: 760 }}
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" size="small">
        <div style={{ display: paso === 1 ? 'block' : 'none' }}>
          <Tabs
            activeKey={tabPaso1}
            onChange={setTabPaso1}
            items={[
              {
                key: 'personal',
                forceRender: true,
                label: <span><IdcardOutlined /> Información personal</span>,
                children: (
                  <div className="flex flex-col gap-3">
                    <div className="flex items-center gap-3">
                      <Avatar size={56} src={fotoPreview} icon={<IdcardOutlined />} />
                      <Upload accept="image/jpeg,image/png,image/webp" showUploadList={false} beforeUpload={handleSeleccionarFoto}>
                        <Button size="small" icon={<CameraOutlined />}>
                          {fotoPreview ? 'Cambiar foto' : 'Foto de perfil (opcional)'}
                        </Button>
                      </Upload>
                    </div>
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                  </div>
                ),
              },
              {
                key: 'contrato',
                forceRender: true,
                label: <span><BankOutlined /> Información de contrato</span>,
                children: (
                  <div className="flex flex-col gap-3">
                    <p className="-mt-1 text-xs text-gray-400">{user?.empresa?.nombre_comercial}</p>
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
                      <Form.Item
                        label={campoLabel('Sede')}
                        name="sede_id"
                        rules={[{ required: true, message: 'Requerido' }]}
                      >
                        <SedeSelect key={empresaId} empresaId={empresaId} puedeCrear={puedeCrearSede} />
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                      <Form.Item
                        label={<span className="invisible">.</span>}
                        name="es_trabajador_confianza"
                        valuePropName="checked"
                        extra="No se le descuenta por faltas ni tardanzas, no se le paga horas extra — se le paga su sueldo básico completo cada período. AFP/ONP, EsSalud y renta 5ta se siguen calculando normal."
                        className="sm:col-span-2 lg:col-span-3"
                      >
                        <Checkbox>Trabajador de confianza</Checkbox>
                      </Form.Item>
                    </div>
                  </div>
                ),
              },
              {
                key: 'remunerativa',
                forceRender: true,
                label: <span><WalletOutlined /> Información remunerativa</span>,
                children: (
                  <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
                      <Form.Item label={campoLabel('CTS')} name="cts_cuenta">
                        <Input placeholder="Cuenta / entidad CTS" />
                      </Form.Item>
                      <Form.Item label={campoLabel('Asignación Familiar')} name="asignacion_familiar">
                        <InputNumber min={0} step={0.01} className="w-full" placeholder="0.00" />
                      </Form.Item>
                      <Form.Item label={campoLabel('Sistema previsional')} name="sistema_previsional">
                        <Select
                          allowClear
                          placeholder="Sin especificar"
                          options={[
                            { value: 'onp', label: 'ONP' },
                            ...afps.map((afp) => ({ value: afp.clave, label: `AFP ${afp.nombre}` })),
                          ]}
                        />
                      </Form.Item>
                    </div>
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
                      <Form.Item label={campoLabel('Moneda de la cuenta')} name="moneda_cuenta">
                        <Select allowClear placeholder="Selecciona" options={MONEDA_OPTIONS} />
                      </Form.Item>
                      <Form.Item
                        label={campoLabel('CCI (Código de Cuenta Interbancario)')}
                        name="cci"
                        className="sm:col-span-1 lg:col-span-2"
                      >
                        <Input placeholder="20 dígitos" maxLength={20} />
                      </Form.Item>
                    </div>
                  </div>
                ),
              },
              {
                key: 'trabajo',
                forceRender: true,
                label: <span><ClockCircleOutlined /> Información de trabajo</span>,
                children: (
                  <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    {esHorarioRotativo && (
                      <>
                        <Alert
                          type="warning"
                          showIcon
                          className="sm:col-span-2 lg:col-span-3 mb-1"
                          message="Horario rotativo"
                          description="El sistema nunca adivina el día de descanso — deberás declarar manualmente en su calendario cuáles fechas son su descanso cada mes."
                        />
                        <Form.Item
                          label={campoLabel('Días de descanso a la semana')}
                          name="dias_descanso_rotativo_por_semana"
                          extra="Cuántos días libres le corresponden por semana (varía por persona)."
                          rules={[{ required: true, message: 'Obligatorio para un horario rotativo' }]}
                        >
                          <InputNumber min={1} max={6} className="w-full" />
                        </Form.Item>
                      </>
                    )}
                  </div>
                ),
              },
            ]}
          />
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
