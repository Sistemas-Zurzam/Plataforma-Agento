import { BankOutlined, IdcardOutlined, TeamOutlined } from '@ant-design/icons';
import { DatePicker, Form, Input, Modal, Select } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';
import AreaSelect from '../../configuracion/components/AreaSelect';
import SedeSelect from '../../configuracion/components/SedeSelect';
import { REGIMEN_OPTIONS } from '../../configuracion/constants/regimenLaboral';
import {
  BANCO_OPTIONS, MONEDA_OPTIONS, TIPO_CONTRATO_OPTIONS, TIPO_CUENTA_OPTIONS, TIPO_DOCUMENTO_OPTIONS,
} from '../constants/opciones';

function Seccion({ icono, titulo, children, className = '' }) {
  return <section className={`rounded-xl border border-gray-100 bg-gray-50/40 p-4 ${className}`}>
    <h3 className="mb-3 flex items-center gap-2 font-semibold text-gray-800"><span className="text-agento-blue">{icono}</span>{titulo}</h3>
    {children}
  </section>;
}

export default function EditarColaboradorModal({ open, colaborador, submitting, onGuardar, onCancel }) {
  const [form] = Form.useForm();
  const tipoContrato = Form.useWatch('tipo_contrato', form);

  useEffect(() => {
    if (!open || !colaborador) return;
    form.setFieldsValue({
      ...colaborador,
      sede_id: colaborador.sede?.id,
      area_id: colaborador.area?.id,
      fecha_nacimiento: colaborador.fecha_nacimiento ? dayjs(colaborador.fecha_nacimiento) : null,
      fecha_fin_contrato: colaborador.fecha_fin_contrato ? dayjs(colaborador.fecha_fin_contrato) : null,
    });
  }, [open, colaborador, form]);

  const guardar = (values) => onGuardar({
    ...values,
    fecha_nacimiento: values.fecha_nacimiento?.format('YYYY-MM-DD') ?? null,
    fecha_fin_contrato: values.fecha_fin_contrato?.format('YYYY-MM-DD') ?? null,
  });

  return <Modal title="Editar colaborador" open={open} onCancel={onCancel} onOk={() => form.submit()} okText="Guardar cambios" cancelText="Cancelar" confirmLoading={submitting} width={{ xs: '94%', sm: '90%', lg: 980 }} centered destroyOnHidden styles={{ body: { maxHeight: 'calc(100vh - 190px)', overflowY: 'auto', paddingRight: 8 } }}>
    <Form form={form} layout="vertical" size="small" onFinish={guardar} className="[&_.ant-form-item]:mb-3">
      <div className="grid items-start gap-4 lg:grid-cols-2">
        <Seccion icono={<IdcardOutlined />} titulo="Datos personales">
          <div className="grid gap-x-3 sm:grid-cols-2">
            <Form.Item label="Nombres" name="nombres" rules={[{ required: true }]}><Input /></Form.Item>
            <Form.Item label="Apellidos" name="apellidos" rules={[{ required: true }]}><Input /></Form.Item>
            <Form.Item label="Tipo de documento" name="tipo_documento" rules={[{ required: true }]}><Select options={TIPO_DOCUMENTO_OPTIONS} /></Form.Item>
            <Form.Item label="Número de documento" name="numero_documento" rules={[{ required: true }]}><Input /></Form.Item>
            <Form.Item label="Fecha de nacimiento" name="fecha_nacimiento"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
            <Form.Item label="Email" name="email" rules={[{ type: 'email' }]}><Input /></Form.Item>
            <Form.Item label="Celular" name="celular_colaborador" rules={[{ required: true }]}><Input /></Form.Item>
            <Form.Item label="Celular de referencia" name="celular_referencia" rules={[{ required: true }]}><Input /></Form.Item>
            <Form.Item label="Dirección" name="direccion" className="sm:col-span-2"><Input /></Form.Item>
          </div>
        </Seccion>
        <Seccion icono={<TeamOutlined />} titulo="Datos laborales">
          <div className="grid gap-x-3 sm:grid-cols-2">
            <Form.Item label="Empresa"><Input value={colaborador?.empresa?.nombre} disabled /></Form.Item>
            <Form.Item label="Sede" name="sede_id" rules={[{ required: true }]}><SedeSelect empresaId={colaborador?.empresa?.id} /></Form.Item>
            <Form.Item label="Área" name="area_id" rules={[{ required: true }]}><AreaSelect empresaId={colaborador?.empresa?.id} /></Form.Item>
            <Form.Item label="Cargo" name="cargo" rules={[{ required: true }]}><Input /></Form.Item>
            <Form.Item label="Tipo de contrato" name="tipo_contrato" rules={[{ required: true }]}><Select options={TIPO_CONTRATO_OPTIONS} /></Form.Item>
            <Form.Item label="Régimen laboral" name="regimen_laboral"><Select allowClear options={REGIMEN_OPTIONS} /></Form.Item>
            <Form.Item label="Fin de contrato" name="fecha_fin_contrato" rules={[{ required: tipoContrato === 'plazo_fijo', message: 'Requerido para plazo fijo' }]} className="sm:col-span-2"><DatePicker className="w-full" format="DD/MM/YYYY" /></Form.Item>
          </div>
        </Seccion>
        <Seccion icono={<BankOutlined />} titulo="Datos bancarios" className="lg:col-span-2">
          <div className="grid gap-x-3 sm:grid-cols-2 lg:grid-cols-5">
            <Form.Item label="Banco" name="banco"><Select allowClear options={BANCO_OPTIONS} /></Form.Item>
            <Form.Item label="Número de cuenta" name="numero_cuenta"><Input /></Form.Item>
            <Form.Item label="Tipo de cuenta" name="tipo_cuenta"><Select allowClear options={TIPO_CUENTA_OPTIONS} /></Form.Item>
            <Form.Item label="Moneda" name="moneda_cuenta"><Select allowClear options={MONEDA_OPTIONS} /></Form.Item>
            <Form.Item label="CCI" name="cci"><Input maxLength={20} /></Form.Item>
          </div>
        </Seccion>
      </div>
    </Form>
  </Modal>;
}
