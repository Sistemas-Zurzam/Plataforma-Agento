import { FileExcelOutlined, UploadOutlined } from '@ant-design/icons';
import { Alert, App, Button, Modal, Spin, Table, Upload } from 'antd';
import { useState } from 'react';

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function mensajeError(err, porDefecto) {
  const errores = err.response?.data?.errors;
  return errores ? Object.values(errores)[0][0] : (err.response?.data?.message ?? porDefecto);
}

/**
 * Carga la CTS ya calculada por RR.HH. en Excel como la CTS vigente del
 * período (histórico del primer semestre sin data previa en Agento). Primero
 * muestra una vista previa con lo que se reconoció; solo al confirmar se guarda.
 */
export default function CtsExcelHistoricoModal({ open, onCancel, tipo, anio, titulo, previsualizarCtsExcel, importarCtsExcel }) {
  const { message } = App.useApp();
  const [archivo, setArchivo] = useState(null);
  const [vista, setVista] = useState(null);
  const [error, setError] = useState(null);
  const [leyendo, setLeyendo] = useState(false);
  const [guardando, setGuardando] = useState(false);

  const cerrar = () => {
    setArchivo(null);
    setVista(null);
    setError(null);
    onCancel();
  };

  const elegir = async (file) => {
    setArchivo(file);
    setVista(null);
    setError(null);
    setLeyendo(true);
    try {
      setVista(await previsualizarCtsExcel(tipo, anio, file));
    } catch (err) {
      setError(mensajeError(err, 'No se pudo leer el Excel.'));
    } finally {
      setLeyendo(false);
    }
    return false;
  };

  const guardar = async () => {
    setGuardando(true);
    try {
      await importarCtsExcel(tipo, anio, archivo);
      message.success(`${titulo} ${anio} cargada desde el Excel.`);
      cerrar();
    } catch (err) {
      message.error(mensajeError(err, 'No se pudo guardar la CTS.'));
    } finally {
      setGuardando(false);
    }
  };

  const bloqueada = Boolean(vista?.vigente?.bloqueada);
  const completa = vista?.vigente?.modo === 'completar';
  const puedeGuardar = vista && !bloqueada && vista.validas > 0;

  return (
    <Modal
      open={open}
      onCancel={cerrar}
      title={`Cargar ${titulo} ${anio} desde Excel`}
      width={980}
      destroyOnHidden
      footer={[
        <Button key="cancelar" onClick={cerrar}>Cancelar</Button>,
        <Button key="guardar" type="primary" icon={<FileExcelOutlined />} loading={guardando} disabled={!puedeGuardar} onClick={guardar}>
          {vista ? `Guardar ${vista.validas} trabajador(es) (${soles(vista.total_validas)})` : 'Guardar CTS'}
        </Button>,
      ]}
    >
      <div className="space-y-4">
        <Alert
          type="info"
          showIcon
          message="Para el primer semestre sin data anterior en Agento"
          description="Sube el Excel de CTS de RR.HH. (una fila por trabajador con Documento, Sueldo base, Días computables, Gratificación, promedio de horas extra, promedio de comisiones y CTS a depositar). Los importes se toman tal cual del Excel y quedan como la CTS del período: luego puedes generar el TXT, registrar el depósito y emitir las boletas de CTS. Las siguientes CTS las calcula Agento."
        />

        <Upload accept=".xlsx" maxCount={1} showUploadList={false} beforeUpload={elegir} disabled={leyendo || guardando}>
          <Button icon={<UploadOutlined />} loading={leyendo}>{archivo ? `Cambiar archivo (${archivo.name})` : 'Seleccionar Excel'}</Button>
        </Upload>

        {error && <Alert type="error" showIcon message={error} />}

        <Spin spinning={leyendo}>
          {vista && (
            <div className="space-y-3">
              {bloqueada && <Alert type="error" showIcon message="La CTS vigente ya tiene depósitos registrados; no se puede reemplazar." />}
              {!bloqueada && vista.vigente && !completa && (
                <Alert type="warning" showIcon message={`Reemplazará la versión ${vista.vigente.version} vigente (queda en el historial).`} />
              )}
              {completa && (
                <Alert type="info" showIcon message="Completará la CTS ya cargada desde Excel: agrega a quienes faltan y actualiza a quienes aún no tienen depósito. Lo ya depositado no cambia." />
              )}
              {vista.con_errores > 0 && (
                <Alert
                  type="warning"
                  showIcon
                  message={`${vista.con_errores} fila(s) con errores no se guardarán.`}
                  description="Se guardan las demás. Cuando las subsanes (por ejemplo, registrando al colaborador), vuelve a cargar el mismo Excel: se agregarán sin tocar lo ya depositado."
                />
              )}
              <p className="text-sm text-gray-700">
                Hoja <strong>{vista.hoja}</strong>: {vista.filas.length} trabajador(es) · Total a depositar <strong>{soles(vista.total)}</strong>
              </p>
              <Table
                size="small"
                rowKey="fila"
                dataSource={vista.filas}
                pagination={vista.filas.length > 10 ? { pageSize: 10, size: 'small' } : false}
                scroll={{ x: 900 }}
                columns={[
                  { title: 'Fila', dataIndex: 'fila', width: 56 },
                  { title: 'Colaborador', render: (_, f) => f.colaborador ?? <span className="text-gray-400">Doc. {f.documento}</span> },
                  { title: 'Días', dataIndex: 'dias', width: 60, align: 'right' },
                  { title: 'Base computable', dataIndex: 'base', align: 'right', render: soles },
                  { title: 'Tasa', dataIndex: 'tasa', width: 70, align: 'right', render: (t) => `${Math.round(t * 100)}%` },
                  { title: 'CTS a depositar', dataIndex: 'monto', align: 'right', render: (m) => <strong>{soles(m)}</strong> },
                  {
                    title: 'Observaciones',
                    render: (_, f) => (
                      <div className="text-xs">
                        {f.errores.map((e) => <div key={e} className="text-red-600">{e}</div>)}
                        {f.advertencias.map((a) => <div key={a} className={a.startsWith('Se registrará') ? 'text-blue-600' : 'text-orange-600'}>{a}</div>)}
                      </div>
                    ),
                  },
                ]}
              />
            </div>
          )}
        </Spin>
      </div>
    </Modal>
  );
}
