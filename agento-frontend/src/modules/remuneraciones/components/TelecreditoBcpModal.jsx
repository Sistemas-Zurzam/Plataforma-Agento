import { DownloadOutlined, SearchOutlined } from '@ant-design/icons';
import { App, Alert, Button, DatePicker, Descriptions, Empty, Form, Modal, Select, Spin, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { useCuentasBancariasEmpresa } from '../../configuracion/hooks/useCuentasBancariasEmpresa';

const SUBTIPO_OPTIONS = [
  { value: 'G', label: 'G — Gratificación' },
  { value: 'V', label: 'V — Vacaciones' },
  { value: 'M', label: 'M — Movilidad' },
  { value: 'P', label: 'P — Pensionista' },
  { value: 'T', label: 'T — Préstamos' },
  { value: '4', label: '4 — Cuarta Categoría' },
  { value: 'O', label: 'O — Otros afectos' },
  { value: 'X', label: 'X — Quinta Categoría' },
  { value: 'Z', label: 'Z — Otros inafectos' },
];

function Hallazgo({ h }) {
  return (
    <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs">
      <span className="font-semibold text-red-700">
        {h.colaborador_nombre ? `${h.colaborador_nombre} — ` : ''}{h.message}
      </span>
      {h.action && <p className="mt-1 text-gray-500">→ {h.action}</p>}
    </div>
  );
}

/**
 * Vista de preparación y descarga de Telecrédito BCP — completamente
 * independiente de PdtPlameModal/AfpNetModal (Sección 3/37 del encargo:
 * ni un import compartido). A diferencia de PLAME/AFPnet, necesita 3
 * parámetros (cuenta de cargo, fecha de proceso, subtipo) antes de poder
 * validar — por eso el flujo es: completar parámetros → Validar →
 * revisar hallazgos → Generar archivo.
 */
export default function TelecreditoBcpModal({ open, onCancel, ciclo, fetchValidacion, exportarTelecreditoBcp }) {
  const { message } = App.useApp();
  const { cuentas, fetchCuentas } = useCuentasBancariasEmpresa(ciclo?.empresa?.id);
  const [form] = Form.useForm();
  const [validacion, setValidacion] = useState(null);
  const [validando, setValidando] = useState(false);
  const [exportando, setExportando] = useState(false);

  useEffect(() => {
    if (!open || !ciclo) return;
    setValidacion(null);
    form.resetFields();
    fetchCuentas();
  }, [open, ciclo, fetchCuentas, form]);

  const cicloPagado = ciclo?.estado === 'pagado';
  const cicloExportable = ['cerrado', 'pagado'].includes(ciclo?.estado);

  const cuentasHaberes = cuentas.filter((c) => c.uso === 'haberes' && c.activo && c.banco?.codigo === 'bcp');

  const handleValidar = async (values) => {
    setValidando(true);
    setValidacion(null);
    try {
      const parametros = {
        cuenta_cargo_id: values.cuenta_cargo_id,
        fecha_proceso: values.fecha_proceso.format('YYYY-MM-DD'),
        subtipo: values.subtipo,
      };
      const data = await fetchValidacion(ciclo.id, parametros);
      setValidacion(data);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo validar Telecrédito BCP');
    } finally {
      setValidando(false);
    }
  };

  const handleExportar = async () => {
    const values = form.getFieldsValue();
    setExportando(true);
    try {
      const parametros = {
        cuenta_cargo_id: values.cuenta_cargo_id,
        fecha_proceso: values.fecha_proceso.format('YYYY-MM-DD'),
        subtipo: values.subtipo,
      };
      const resultado = await exportarTelecreditoBcp(ciclo.id, parametros);
      if (resultado.descargado) {
        message.success('Archivo Telecrédito BCP descargado correctamente.');
      } else {
        message.error(resultado.message ?? 'No se pudo generar el archivo Telecrédito BCP.');
        if (resultado.validacion) setValidacion(resultado.validacion);
      }
    } catch {
      message.error('No se pudo generar el archivo Telecrédito BCP.');
    } finally {
      setExportando(false);
    }
  };

  const puedeExportar = cicloExportable && validacion?.listo && !validando && !exportando;

  return (
    <Modal
      title="Telecrédito BCP — Planilla de Haberes"
      open={open}
      onCancel={onCancel}
      footer={null}
      width={680}
      destroyOnHidden
    >
      <div className="space-y-4">
        <Descriptions size="small" column={2} bordered>
          <Descriptions.Item label="Empresa">{ciclo?.empresa?.nombre_comercial}</Descriptions.Item>
          <Descriptions.Item label="Ciclo">{ciclo?.nombre}</Descriptions.Item>
          <Descriptions.Item label="Período">{ciclo?.fecha_inicio} — {ciclo?.fecha_fin}</Descriptions.Item>
          <Descriptions.Item label="Estado del ciclo"><Tag color={cicloExportable ? 'green' : 'orange'}>{ciclo?.estado}</Tag></Descriptions.Item>
        </Descriptions>

        {!cicloExportable && (
          <Alert
            type="warning"
            showIcon
            message="El ciclo debe estar cerrado (snapshot definitivo) para generar Telecrédito BCP."
          />
        )}

        {cicloPagado && (
          <Alert
            type="warning"
            showIcon
            message="Este ciclo ya figura como pagado."
            description="Esta es una regeneración de consulta — descargar este archivo NO ordena un nuevo pago ni modifica el estado de las boletas."
          />
        )}

        {cuentasHaberes.length === 0 && (
          <Alert
            type="info"
            showIcon
            message="No hay cuentas BCP habilitadas para pago de haberes."
            description="Configúralas en Configuraciones → Empresas → Cuentas Bancarias."
          />
        )}

        <Form form={form} layout="vertical" onFinish={handleValidar}>
          <div className="grid grid-cols-2 gap-x-3">
            <Form.Item label="Cuenta de cargo" name="cuenta_cargo_id" rules={[{ required: true, message: 'Selecciona una cuenta' }]}>
              <Select
                placeholder="Selecciona la cuenta de cargo"
                options={cuentasHaberes.map((c) => ({
                  value: c.id,
                  label: `BCP • ${c.tipo_cuenta === 'corriente' ? 'Corriente' : 'Maestra'} • ${c.moneda} • ${c.numero_cuenta_enmascarado}`,
                }))}
              />
            </Form.Item>
            <Form.Item
              label="Fecha de proceso"
              name="fecha_proceso"
              rules={[{ required: true, message: 'Selecciona la fecha de proceso' }]}
              extra="Debe ser mayor o igual a hoy"
            >
              <DatePicker className="w-full" format="DD/MM/YYYY" disabledDate={(d) => d && d.isBefore(dayjs().startOf('day'))} />
            </Form.Item>
          </div>
          <Form.Item
            label="Subtipo de planilla"
            name="subtipo"
            initialValue="X"
            rules={[{ required: true }]}
            extra="Preseleccionado X (Quinta Categoría) para planilla mensual — confirmar con BCP antes de convertirlo en default automático."
          >
            <Select options={SUBTIPO_OPTIONS} />
          </Form.Item>
          <Button icon={<SearchOutlined />} htmlType="submit" loading={validando} disabled={!cicloExportable}>
            Validar
          </Button>
        </Form>

        {validando && <div className="flex justify-center py-6"><Spin /></div>}

        {validacion && !validando && (
          <div className="space-y-3">
            <div className="grid grid-cols-4 gap-3">
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-xl font-semibold text-gray-900">{validacion.abonos}</p>
                <p className="text-xs text-gray-500">Abonos</p>
              </div>
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-xl font-semibold text-gray-900">S/ {validacion.monto_total}</p>
                <p className="text-xs text-gray-500">Monto total</p>
              </div>
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-xl font-semibold text-red-600">{validacion.bloqueantes}</p>
                <p className="text-xs text-gray-500">Bloqueantes</p>
              </div>
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-xl font-semibold text-amber-500">{validacion.observaciones}</p>
                <p className="text-xs text-gray-500">Observaciones</p>
              </div>
            </div>

            {validacion.hallazgos?.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Hallazgos</p>
                <div className="max-h-48 space-y-1.5 overflow-y-auto pr-1">
                  {validacion.hallazgos.map((h, i) => <Hallazgo key={i} h={h} />)}
                </div>
              </div>
            )}

            <div className="flex justify-end border-t border-gray-100 pt-3">
              <Button
                type="primary"
                icon={<DownloadOutlined />}
                loading={exportando}
                disabled={!puedeExportar}
                onClick={handleExportar}
              >
                Generar archivo Telecrédito
              </Button>
            </div>
          </div>
        )}

        {!validacion && !validando && (
          <Empty description="Completa los parámetros y presiona Validar." className="py-4" />
        )}
      </div>
    </Modal>
  );
}
