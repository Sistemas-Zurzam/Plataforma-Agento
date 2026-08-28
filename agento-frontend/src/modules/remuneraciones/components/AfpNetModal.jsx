import { DownloadOutlined } from '@ant-design/icons';
import { App, Alert, Button, Descriptions, Empty, Modal, Spin, Tag } from 'antd';
import { useEffect, useState } from 'react';

function Hallazgo({ h }) {
  return (
    <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs">
      <div className="flex items-center justify-between gap-2">
        <span className="font-semibold text-red-700">
          {h.colaborador_nombre ? `${h.colaborador_nombre} — ` : ''}{h.message}
        </span>
      </div>
      {h.action && <p className="mt-1 text-gray-500">→ {h.action}</p>}
    </div>
  );
}

/**
 * Vista de preparación y descarga de AFPnet — completamente independiente
 * de PdtPlameModal (Sección 3/37 del encargo AFPnet: ni un import
 * compartido). Consulta AfpNetValidator al abrir y dispara la exportación
 * definitiva solo cuando el ciclo está "pagado", igual que exige el
 * backend (AfpNetExportService).
 */
export default function AfpNetModal({ open, onCancel, ciclo, fetchValidacion, exportarAfpNet }) {
  const { message } = App.useApp();
  const [validacion, setValidacion] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [exportando, setExportando] = useState(null);

  useEffect(() => {
    if (!open || !ciclo) return;
    let activo = true;
    setCargando(true);
    fetchValidacion(ciclo.id).then((data) => {
      if (activo) setValidacion(data);
    }).finally(() => activo && setCargando(false));
    return () => { activo = false; };
  }, [open, ciclo, fetchValidacion]);

  const handleExportar = async (formato) => {
    setExportando(formato);
    try {
      const resultado = await exportarAfpNet(ciclo.id, formato);
      if (resultado.descargado) {
        message.success('Archivo AFPnet descargado correctamente.');
      } else {
        message.error(resultado.message ?? 'No se pudo generar la exportación AFPnet.');
        if (resultado.validacion) setValidacion(resultado.validacion);
      }
    } catch {
      message.error('No se pudo generar la exportación AFPnet.');
    } finally {
      setExportando(null);
    }
  };

  const cicloPagado = ciclo?.estado === 'pagado';
  const resumen = validacion?.resumen;
  const sinTrabajadores = (resumen?.trabajadores ?? 0) === 0;
  const hallazgos = validacion?.hallazgos ?? [];
  const exportacionDeshabilitada = !cicloPagado || sinTrabajadores || !validacion?.listo || !!exportando;

  return (
    <Modal
      title="AFPnet"
      open={open}
      onCancel={onCancel}
      footer={null}
      width={640}
      destroyOnHidden
    >
      {cargando ? (
        <div className="flex justify-center py-10"><Spin /></div>
      ) : !validacion ? (
        <Empty description="No se pudo cargar la validación AFPnet" />
      ) : (
        <div className="space-y-4">
          <Descriptions size="small" column={2} bordered>
            <Descriptions.Item label="Empresa">{ciclo?.empresa?.nombre_comercial}</Descriptions.Item>
            <Descriptions.Item label="Ciclo">{ciclo?.nombre}</Descriptions.Item>
            <Descriptions.Item label="Período">{validacion.periodo?.fecha_inicio} — {validacion.periodo?.fecha_fin}</Descriptions.Item>
            <Descriptions.Item label="Estado del ciclo"><Tag color={cicloPagado ? 'green' : 'orange'}>{ciclo?.estado}</Tag></Descriptions.Item>
          </Descriptions>

          {!cicloPagado && (
            <Alert
              type="warning"
              showIcon
              message="El ciclo debe estar pagado para generar el archivo definitivo AFPnet."
              description="Puedes revisar hallazgos y corregir datos mientras tanto; la descarga definitiva quedará deshabilitada."
            />
          )}

          {sinTrabajadores ? (
            <Empty description="No existen trabajadores afiliados al SPP en este período." />
          ) : (
            <div className="grid grid-cols-3 gap-3">
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-2xl font-semibold text-gray-900">{resumen?.trabajadores ?? 0}</p>
                <p className="text-xs text-gray-500">Trabajadores AFP</p>
              </div>
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-2xl font-semibold text-green-600">{(resumen?.trabajadores ?? 0) - (resumen?.bloqueantes ?? 0)}</p>
                <p className="text-xs text-gray-500">Listos</p>
              </div>
              <div className="rounded-lg border border-gray-100 bg-white p-3 text-center">
                <p className="text-2xl font-semibold text-red-600">{resumen?.bloqueantes ?? 0}</p>
                <p className="text-xs text-gray-500">Bloqueantes</p>
              </div>
            </div>
          )}

          {hallazgos.length > 0 && (
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Hallazgos</p>
              <div className="max-h-56 space-y-1.5 overflow-y-auto pr-1">
                {hallazgos.map((h, i) => <Hallazgo key={i} h={h} />)}
              </div>
            </div>
          )}

          <div className="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-3">
            <Button
              icon={<DownloadOutlined />}
              loading={exportando === 'excel'}
              disabled={exportacionDeshabilitada}
              onClick={() => handleExportar('excel')}
            >
              Exportar Excel
            </Button>
            <Button
              type="primary"
              icon={<DownloadOutlined />}
              loading={exportando === 'txt'}
              disabled={exportacionDeshabilitada}
              onClick={() => handleExportar('txt')}
            >
              Exportar TXT
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
