import {
  App,
  Button,
  Checkbox,
  DatePicker,
  Form,
  Input,
  InputNumber,
  Modal,
  Segmented,
  Select,
  TimePicker,
} from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { DIAS_SEMANA, ESTADO_DIA_OPTIONS, diaVacio } from '../constants/dias';

const COLOR_ESTADO = {
  laborable: 'bg-green-500',
  pendiente: 'bg-orange-400',
  descanso: 'bg-gray-300',
};

function horaTexto(valor) {
  return dayjs.isDayjs(valor) ? valor.format('HH:mm') : (valor ?? null);
}

function calcularHoras(entrada, salida, refrigerioInicio, refrigerioFin) {
  if (!dayjs.isDayjs(entrada) || !dayjs.isDayjs(salida)) return null;

  let minutos = Math.abs(salida.diff(entrada, 'minute'));
  if (dayjs.isDayjs(refrigerioInicio) && dayjs.isDayjs(refrigerioFin)) {
    minutos -= Math.abs(refrigerioFin.diff(refrigerioInicio, 'minute'));
  }
  return Math.max(0, minutos / 60);
}

function SelectorDias({ form, diaActivo, onCambiar }) {
  const dias = Form.useWatch('dias', form) ?? [];

  return (
    <Segmented
      block
      value={diaActivo}
      onChange={onCambiar}
      options={DIAS_SEMANA.map(({ value, abrev }) => ({
        value,
        label: (
          <div className="flex flex-col items-center gap-1 py-0.5">
            <span>{abrev}</span>
            <span
              className={`h-1.5 w-1.5 rounded-full ${COLOR_ESTADO[dias[value]?.estado] ?? COLOR_ESTADO.pendiente}`}
            />
          </div>
        ),
      }))}
    />
  );
}

function PanelDia({ form, index, label, oculto }) {
  const dia = Form.useWatch(['dias', index], form) ?? {};
  const horas = calcularHoras(dia.hora_entrada, dia.hora_salida, dia.refrigerio_inicio, dia.refrigerio_fin);

  return (
    <div className={`rounded-xl border border-gray-100 p-3 ${oculto ? 'hidden' : ''}`}>
      <div className="mb-2 flex items-center justify-between">
        <span className="font-medium text-gray-900">{label}</span>
        <span className="text-xs font-medium text-gray-500">
          {horas !== null ? `${horas.toFixed(2)} h` : '0:00 h'}
        </span>
      </div>

      <Form.Item name={['dias', index, 'dia_semana']} hidden initialValue={index}>
        <input type="hidden" />
      </Form.Item>

      <Form.Item label="Estado" name={['dias', index, 'estado']} className="mb-2">
        <Select options={ESTADO_DIA_OPTIONS} />
      </Form.Item>

      <div className="grid grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-4">
        <Form.Item label="Entrada" name={['dias', index, 'hora_entrada']} className="mb-0">
          <TimePicker format="HH:mm" className="w-full" />
        </Form.Item>
        <Form.Item label="Salida" name={['dias', index, 'hora_salida']} className="mb-0">
          <TimePicker format="HH:mm" className="w-full" />
        </Form.Item>
        <Form.Item label="Refrig. inicio" name={['dias', index, 'refrigerio_inicio']} className="mb-0">
          <TimePicker format="HH:mm" className="w-full" />
        </Form.Item>
        <Form.Item label="Refrig. fin" name={['dias', index, 'refrigerio_fin']} className="mb-0">
          <TimePicker format="HH:mm" className="w-full" />
        </Form.Item>
      </div>

      <div className="mt-2 flex gap-4">
        <Form.Item name={['dias', index, 'jornada_nocturna']} valuePropName="checked" className="mb-0">
          <Checkbox>Jornada nocturna</Checkbox>
        </Form.Item>
        <Form.Item name={['dias', index, 'permitir_horas_extra']} valuePropName="checked" className="mb-0">
          <Checkbox>Permitir horas extra</Checkbox>
        </Form.Item>
      </div>
    </div>
  );
}

export default function HorarioFormModal({ open, horario, onSubmit, onCancel, submitting }) {
  const [form] = Form.useForm();
  const [diaActivo, setDiaActivo] = useState(0);
  const { message } = App.useApp();

  useEffect(() => {
    if (!open) return;

    setDiaActivo(0);

    if (horario) {
      form.setFieldsValue({
        nombre: horario.nombre,
        tolerancia_minutos: horario.tolerancia_minutos,
        tipo_turno: horario.tipo_turno,
        descripcion: horario.descripcion,
        cruza_medianoche: horario.cruza_medianoche,
        vigencia_desde: horario.vigencia_desde ? dayjs(horario.vigencia_desde) : null,
        vigencia_hasta: horario.vigencia_hasta ? dayjs(horario.vigencia_hasta) : null,
        dias: DIAS_SEMANA.map(({ value }) => {
          const dia = horario.dias.find((d) => d.dia_semana === value) ?? diaVacio(value);
          return {
            ...dia,
            hora_entrada: dia.hora_entrada ? dayjs(dia.hora_entrada, 'HH:mm:ss') : null,
            hora_salida: dia.hora_salida ? dayjs(dia.hora_salida, 'HH:mm:ss') : null,
            refrigerio_inicio: dia.refrigerio_inicio ? dayjs(dia.refrigerio_inicio, 'HH:mm:ss') : null,
            refrigerio_fin: dia.refrigerio_fin ? dayjs(dia.refrigerio_fin, 'HH:mm:ss') : null,
          };
        }),
      });
    } else {
      form.resetFields();
      form.setFieldsValue({
        tolerancia_minutos: 0,
        tipo_turno: 'normal',
        cruza_medianoche: false,
        vigencia_desde: dayjs(),
        dias: DIAS_SEMANA.map(({ value }) => diaVacio(value)),
      });
    }
  }, [open, horario, form]);

  const handleAutoGenerar = () => {
    const dias = form.getFieldValue('dias') ?? [];
    const representativo = dias.find((d) => d.estado === 'laborable' && d.hora_entrada && d.hora_salida);

    if (!representativo) {
      message.warning(
        'Configura al menos un día "Laborable" con entrada y salida para generar el nombre.',
      );
      return;
    }

    const entrada = horaTexto(representativo.hora_entrada);
    const salida = horaTexto(representativo.hora_salida);
    const horas = calcularHoras(
      representativo.hora_entrada,
      representativo.hora_salida,
      representativo.refrigerio_inicio,
      representativo.refrigerio_fin,
    );

    const tieneRefrigerio = representativo.refrigerio_inicio && representativo.refrigerio_fin;
    const refrigerioTexto = tieneRefrigerio
      ? ` | refrigerio ${horaTexto(representativo.refrigerio_inicio)}-${horaTexto(representativo.refrigerio_fin)}`
      : '';

    form.setFieldValue(
      'nombre',
      `${entrada}-${salida}${refrigerioTexto} | ${Math.round((horas ?? 0) * 10) / 10} h`,
    );
  };

  const handleFinish = (values) => {
    onSubmit({
      ...values,
      vigencia_desde: values.vigencia_desde.format('YYYY-MM-DD'),
      vigencia_hasta: values.vigencia_hasta ? values.vigencia_hasta.format('YYYY-MM-DD') : null,
      dias: values.dias.map((dia) => ({
        ...dia,
        hora_entrada: horaTexto(dia.hora_entrada),
        hora_salida: horaTexto(dia.hora_salida),
        refrigerio_inicio: horaTexto(dia.refrigerio_inicio),
        refrigerio_fin: horaTexto(dia.refrigerio_fin),
      })),
    });
  };

  return (
    <Modal
      title={horario ? 'Editar horario' : 'Nuevo horario'}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText={horario ? 'Guardar cambios' : 'Crear horario'}
      cancelText="Cancelar"
      width={620}
      centered
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={handleFinish} size="small">
        <Form.Item label="Nombre" required className="mb-2">
          <div className="flex gap-2">
            <Form.Item
              name="nombre"
              noStyle
              rules={[{ required: true, message: 'Ingresa un nombre' }]}
            >
              <Input placeholder="Ej: 09:00-18:00 | 8 h" />
            </Form.Item>
            <Button type="link" className="shrink-0 px-0" onClick={handleAutoGenerar}>
              Auto-generar
            </Button>
          </div>
        </Form.Item>

        <div className="grid grid-cols-3 gap-x-3">
          <Form.Item label="Tolerancia (min)" name="tolerancia_minutos" className="mb-2">
            <InputNumber min={0} className="w-full" />
          </Form.Item>
          <Form.Item label="Tipo de turno" name="tipo_turno" className="mb-2 col-span-2">
            <Select
              options={[
                { value: 'normal', label: 'Normal (diurno)' },
                { value: 'nocturno', label: 'Nocturno' },
                { value: 'rotativo', label: 'Rotativo' },
              ]}
            />
          </Form.Item>
        </div>

        <Form.Item name="cruza_medianoche" valuePropName="checked" className="mb-2">
          <Checkbox>Cruza medianoche (la salida es al día siguiente)</Checkbox>
        </Form.Item>

        <div className="grid grid-cols-3 gap-x-3">
          <Form.Item label="Descripción" name="descripcion" className="mb-2">
            <Input placeholder="Opcional" />
          </Form.Item>
          <Form.Item
            label="Vigencia desde"
            name="vigencia_desde"
            className="mb-2"
            rules={[{ required: true, message: 'Requerido' }]}
          >
            <DatePicker className="w-full" format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item label="Vigencia hasta" name="vigencia_hasta" className="mb-2">
            <DatePicker className="w-full" format="DD/MM/YYYY" placeholder="Opcional" />
          </Form.Item>
        </div>

        <p className="mt-1 mb-2 text-xs font-semibold tracking-wide text-gray-400 uppercase">
          Configuración semanal
        </p>

        <SelectorDias form={form} diaActivo={diaActivo} onCambiar={setDiaActivo} />

        <div className="mt-3">
          {DIAS_SEMANA.map(({ value, label }) => (
            <PanelDia
              key={value}
              form={form}
              index={value}
              label={label}
              oculto={value !== diaActivo}
            />
          ))}
        </div>
      </Form>
    </Modal>
  );
}
