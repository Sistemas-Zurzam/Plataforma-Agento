import { UploadOutlined } from '@ant-design/icons';
import { App, Avatar, Button, Form, Input, DatePicker, Modal, Select, Switch, Tabs, Upload } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { REGIMEN_OPTIONS } from '../constants/regimenLaboral';
import ColorSwatchPicker from './ColorSwatchPicker';
import EmpresaReglasTardanza from './EmpresaReglasTardanza';
import EmpresaResponsablesArea from './EmpresaResponsablesArea';
import EmpresaSedes from './EmpresaSedes';

export default function EmpresaFormModal({
  open,
  title,
  initialValues,
  onSubmit,
  onCancel,
  onEstadoChange,
  onSubirLogo,
  submitting,
  user,
}) {
  const [form] = Form.useForm();
  const { message } = App.useApp();
  const [tabActiva, setTabActiva] = useState('general');
  const [subiendoLogo, setSubiendoLogo] = useState(false);
  const regimenLaboral = Form.useWatch('regimen_laboral', form);
  const inscritaRemype = Form.useWatch('inscrita_remype', form);

  useEffect(() => {
    if (open) {
      setTabActiva('general');
      form.setFieldsValue({
        nombre: initialValues?.nombre ?? '',
        abreviatura: initialValues?.abreviatura ?? '',
        grupo: initialValues?.grupo ?? '',
        ruc: initialValues?.ruc ?? '',
        direccion: initialValues?.direccion ?? '',
        color: initialValues?.color ?? null,
        regimen_laboral: initialValues?.regimen_laboral ?? null,
        inscrita_remype: initialValues?.inscrita_remype ?? false,
        fecha_inscripcion_remype: initialValues?.fecha_inscripcion_remype
          ? dayjs(initialValues.fecha_inscripcion_remype)
          : null,
        numero_registro_remype: initialValues?.numero_registro_remype ?? '',
        seguro_salud: initialValues?.seguro_salud ?? 'essalud',
      });
    }
  }, [open, initialValues, form]);

  const esLocador = regimenLaboral === 'Locacion de Servicios';
  const puedeOptarPorSis = regimenLaboral === 'Micro Empresa' && !!inscritaRemype;

  useEffect(() => {
    if (!puedeOptarPorSis && form.getFieldValue('seguro_salud') === 'sis') {
      form.setFieldValue('seguro_salud', 'essalud');
    }
  }, [puedeOptarPorSis, form]);

  const handleFinish = (values) => {
    onSubmit(
      {
        ...values,
        fecha_inscripcion_remype: values.fecha_inscripcion_remype?.format('YYYY-MM-DD') ?? null,
        seguro_salud: esLocador ? 'essalud' : values.seguro_salud,
      },
      form,
    );
  };

  const handleEstadoChange = () => {
    if (!initialValues?.id) return;
    onEstadoChange?.(initialValues);
  };

  const handleSubirLogo = async (archivo) => {
    if (!initialValues?.id) return;
    setSubiendoLogo(true);
    try {
      await onSubirLogo?.(initialValues.id, archivo);
      message.success('Logo actualizado correctamente');
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo subir el logo');
    } finally {
      setSubiendoLogo(false);
    }
    return false;
  };

  const tabDatosGenerales = (
    <Form form={form} layout="vertical" onFinish={handleFinish}>
      <div className="grid grid-cols-1 gap-x-5 sm:grid-cols-2">
        <Form.Item
          label="Nombre de la empresa"
          name="nombre"
          className="sm:col-span-2"
          rules={[{ required: true, message: 'Ingresa el nombre de la empresa' }]}
        >
          <Input placeholder="Ej: Mi Empresa SAC" />
        </Form.Item>

        <Form.Item label="Abreviatura" name="abreviatura">
          <Input maxLength={10} placeholder="Ej: ME" />
        </Form.Item>

        <Form.Item label="Grupo" name="grupo">
          <Input placeholder="Ej: Agento" />
        </Form.Item>

        <Form.Item
          label="RUC (opcional)"
          name="ruc"
          rules={[
            { len: 11, message: 'El RUC debe tener 11 dígitos' },
            { pattern: /^\d*$/, message: 'El RUC solo debe contener números' },
          ]}
        >
          <Input maxLength={11} placeholder="20XXXXXXXXX" />
        </Form.Item>

        <Form.Item label="Dirección (opcional)" name="direccion">
          <Input placeholder="Av. Principal 123, Lima" />
        </Form.Item>

        <Form.Item label="Color" name="color" className="sm:col-span-2">
          <ColorSwatchPicker />
        </Form.Item>

        {initialValues?.id ? (
          <Form.Item label="Logo" className="sm:col-span-2">
            <div className="flex items-center gap-3">
              <Avatar
                shape="square"
                size={48}
                src={initialValues.logo_url}
                style={{ backgroundColor: initialValues.color ?? '#014693' }}
              >
                {initialValues.nombre?.charAt(0)}
              </Avatar>
              <Upload
                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                showUploadList={false}
                beforeUpload={handleSubirLogo}
              >
                <Button icon={<UploadOutlined />} loading={subiendoLogo}>
                  {initialValues.logo_url ? 'Cambiar logo' : 'Subir logo'}
                </Button>
              </Upload>
            </div>
          </Form.Item>
        ) : (
          <p className="text-xs text-gray-400 sm:col-span-2">
            Podrás subir el logo después de crear la empresa.
          </p>
        )}
      </div>

      <div className="grid grid-cols-1 gap-x-5 sm:grid-cols-2">
        <div>
          <Form.Item
            label="Régimen Laboral"
            name="regimen_laboral"
            extra="Define cómo se calculará la planilla de todos los colaboradores de esta empresa."
          >
            <Select allowClear placeholder="Sin especificar" options={REGIMEN_OPTIONS} />
          </Form.Item>

          <Form.Item label="Inscrita en REMYPE" name="inscrita_remype" valuePropName="checked">
            <Switch />
          </Form.Item>

          {inscritaRemype && (
            <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
              <Form.Item
                label="Fecha de inscripción REMYPE"
                name="fecha_inscripcion_remype"
                rules={[{ required: true, message: 'Requerida' }]}
              >
                <DatePicker className="w-full" format="DD/MM/YYYY" />
              </Form.Item>
              <Form.Item
                label="Número de registro REMYPE"
                name="numero_registro_remype"
                rules={[{ required: true, message: 'Requerido' }]}
              >
                <Input placeholder="0002361141-2026" />
              </Form.Item>
            </div>
          )}
        </div>

        <div className="rounded-xl border border-gray-100 p-3">
          {esLocador ? (
            <Form.Item label="Seguro de salud" className="!mb-0">
              <Select value="no_aplica" disabled options={[{ value: 'no_aplica', label: 'No aplica' }]} />
              <p className="mt-1.5 text-xs text-gray-400">
                Los locadores de servicios no tienen aporte de salud a cargo del empleador.
              </p>
            </Form.Item>
          ) : (
            <Form.Item
              label="Seguro de salud"
              name="seguro_salud"
              className="!mb-0"
              extra="Micro empresas inscritas en REMYPE pueden optar por SIS en lugar de EsSalud."
            >
              <Select
                options={[
                  { value: 'essalud', label: 'EsSalud (9%, con piso legal)' },
                  { value: 'sis', label: 'SIS (monto fijo)', disabled: !puedeOptarPorSis },
                ]}
              />
            </Form.Item>
          )}

          {initialValues?.id && (
            <div className="mt-4 flex items-center justify-between border-t border-gray-100 pt-3">
              <p className="text-sm font-medium text-gray-900">Estado</p>
              <Select
                size="small"
                className="w-32"
                value={initialValues.activa ? 'activa' : 'inactiva'}
                onChange={() => handleEstadoChange()}
                options={[
                  { value: 'activa', label: 'Activa' },
                  { value: 'inactiva', label: 'Inactiva' },
                ]}
              />
            </div>
          )}
        </div>
      </div>
    </Form>
  );

  const items = [
    { key: 'general', label: 'Datos generales', children: tabDatosGenerales },
  ];

  if (initialValues?.id) {
    items.push(
      { key: 'sedes', label: 'Sedes', children: <EmpresaSedes empresaId={initialValues.id} user={user} /> },
      {
        key: 'tardanza',
        label: 'Asistencia',
        children: <EmpresaReglasTardanza empresaId={initialValues.id} empresaNombre={initialValues.nombre} user={user} />,
      },
      {
        key: 'responsables',
        label: 'Responsables',
        children: <EmpresaResponsablesArea empresaId={initialValues.id} empresaNombre={initialValues.nombre} user={user} />,
      },
    );
  }

  return (
    <Modal
      title={title}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      confirmLoading={submitting}
      okText={initialValues ? 'Guardar cambios' : 'Crear'}
      cancelText="Cancelar"
      destroyOnHidden
      centered
      width={{ xs: '95%', sm: '92%', md: 760 }}
    >
      <Tabs activeKey={tabActiva} onChange={setTabActiva} items={items} />
    </Modal>
  );
}
