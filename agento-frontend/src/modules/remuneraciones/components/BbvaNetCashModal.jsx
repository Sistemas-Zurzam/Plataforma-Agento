import { DownloadOutlined, SearchOutlined } from '@ant-design/icons';
import { App, Alert, Button, Descriptions, Empty, Modal, Radio, Spin, Tag } from 'antd';
import { useEffect, useState } from 'react';

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
 * Vista de preparación y descarga de BBVA Net Cash — completamente
 * independiente de TelecreditoBcpModal (ni un import compartido). A
 * diferencia de Telecrédito, NUNCA pide cuenta de cargo ni fecha de
 * proceso: el backend las resuelve solo desde la empresa del ciclo — el
 * único parámetro operativo que envía el frontend es el subtipo.
 *
 * Cambiar de 5ta a 4ta (o viceversa) resetea la validación anterior: nunca
 * se muestra en pantalla el resultado del subtipo previo mientras se
 * espera el nuevo.
 */
export default function BbvaNetCashModal({ open, onCancel, ciclo, boletaIds = [], fetchValidacion, exportarBbvaNetCash }) {
  const { message } = App.useApp();
  const [subtipo, setSubtipo] = useState('5');
  const [validacion, setValidacion] = useState(null);
  const [validando, setValidando] = useState(false);
  const [exportando, setExportando] = useState(false);

  useEffect(() => {
    if (!open || !ciclo) return;
    setSubtipo('5');
    setValidacion(null);
  }, [open, ciclo]);

  const cicloPagado = ciclo?.estado === 'pagado';
  const cicloExportable = ['cerrado', 'pagado'].includes(ciclo?.estado);

  const handleSubtipoChange = (e) => {
    setSubtipo(e.target.value);
    setValidacion(null);
  };

  const handleValidar = async () => {
    setValidando(true);
    setValidacion(null);
    try {
      const data = await fetchValidacion(ciclo.id, { subtipo, ...(boletaIds.length > 0 ? { boleta_ids: boletaIds } : {}) });
      setValidacion(data);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo validar BBVA Net Cash');
    } finally {
      setValidando(false);
    }
  };

  const handleExportar = async () => {
    setExportando(true);
    try {
      const resultado = await exportarBbvaNetCash(ciclo.id, { subtipo, ...(boletaIds.length > 0 ? { boleta_ids: boletaIds } : {}) });
      if (resultado.descargado) {
        message.success('Archivo BBVA Net Cash descargado correctamente.');
      } else {
        message.error(resultado.message ?? 'No se pudo generar el archivo BBVA Net Cash.');
        if (resultado.validacion) setValidacion({ ...resultado.validacion, cuenta_cargo: validacion?.cuenta_cargo });
      }
    } catch {
      message.error('No se pudo generar el archivo BBVA Net Cash.');
    } finally {
      setExportando(false);
    }
  };

  const puedeExportar = cicloExportable && validacion?.listo && !validando && !exportando;

  return (
    <Modal
      title="BBVA Net Cash — Pago de Haberes"
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

        <Alert
          type={boletaIds.length > 0 ? 'info' : 'warning'}
          showIcon
          message={boletaIds.length > 0
            ? `Alcance: ${boletaIds.length} boleta(s) seleccionada(s)`
            : 'Alcance: todas las boletas elegibles del ciclo'}
        />

        {!cicloExportable && (
          <Alert
            type="warning"
            showIcon
            message="El ciclo debe estar cerrado (snapshot definitivo) para generar BBVA Net Cash."
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

        <div>
          <p className="mb-2 text-sm font-medium text-gray-700">Tipo de pago</p>
          <Radio.Group onChange={handleSubtipoChange} value={subtipo} className="flex flex-col gap-2">
            <Radio value="5">5ta Categoría — Planilla</Radio>
            <Radio value="4">4ta Categoría — RH / Locación de Servicios</Radio>
          </Radio.Group>
        </div>

        <Button icon={<SearchOutlined />} onClick={handleValidar} loading={validando} disabled={!cicloExportable}>
          Validar
        </Button>

        {validando && <div className="flex justify-center py-6"><Spin /></div>}

        {validacion && !validando && (
          <div className="space-y-3">
            {validacion.cuenta_cargo && (
              <Descriptions size="small" column={2} bordered>
                <Descriptions.Item label="Cuenta de cargo">
                  {validacion.cuenta_cargo.banco?.nombre} {validacion.cuenta_cargo.numero_cuenta_enmascarado}
                </Descriptions.Item>
                <Descriptions.Item label="Moneda">{validacion.cuenta_cargo.moneda}</Descriptions.Item>
              </Descriptions>
            )}

            <div className="grid grid-cols-4 gap-3">
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-xl font-semibold text-gray-900">{validacion.abonos}</p>
                <p className="text-xs text-gray-500">Colaboradores</p>
              </div>
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-xl font-semibold text-gray-900">S/ {validacion.monto_total}</p>
                <p className="text-xs text-gray-500">Total</p>
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
                Generar TXT
              </Button>
            </div>
          </div>
        )}

        {!validacion && !validando && (
          <Empty description="Elige el tipo de pago y presiona Validar." className="py-4" />
        )}
      </div>
    </Modal>
  );
}
