import { DownloadOutlined, FileExcelOutlined, UploadOutlined } from '@ant-design/icons';
import { App, Alert, Button, Modal, Table, Tag, Typography, Upload } from 'antd';
import { useState } from 'react';
import { useColaboradores } from '../hooks/useColaboradores';

const { Text } = Typography;

const ETIQUETAS_ACCION = {
  crear: ['Se creará', 'green'],
  error: ['Con error', 'red'],
};

export default function ImportarColaboradoresModal({ open, onCancel, onImportado }) {
  const { descargarPlantilla, previsualizarImportacion, confirmarImportacion } = useColaboradores();
  const { message } = App.useApp();
  const [archivo, setArchivo] = useState(null);
  const [previsualizacion, setPrevisualizacion] = useState(null);
  const [cargando, setCargando] = useState(false);

  const reiniciar = () => {
    setArchivo(null);
    setPrevisualizacion(null);
  };

  const handleCancel = () => {
    reiniciar();
    onCancel();
  };

  const handleDescargarPlantilla = async () => {
    try {
      await descargarPlantilla();
    } catch {
      message.error('No se pudo descargar la plantilla');
    }
  };

  const prepararArchivo = async (nuevoArchivo) => {
    setCargando(true);
    try {
      const data = await previsualizarImportacion(nuevoArchivo);
      setArchivo(nuevoArchivo);
      setPrevisualizacion(data);
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo analizar el archivo de colaboradores');
    } finally {
      setCargando(false);
    }
    return Upload.LIST_IGNORE;
  };

  const handleConfirmar = async () => {
    if (!archivo) return;
    setCargando(true);
    try {
      const resultado = await confirmarImportacion(archivo);
      message.success(`${resultado.creados} colaboradores creados.`);
      if (resultado.errores?.length) {
        message.warning(`${resultado.errores.length} filas no se pudieron importar. Revisa el detalle.`);
      }
      reiniciar();
      onImportado?.();
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudo confirmar la importación');
    } finally {
      setCargando(false);
    }
  };

  const columnas = [
    { title: 'Colaborador', dataIndex: 'nombre' },
    { title: 'Documento', dataIndex: 'numero_documento', width: 130 },
    {
      title: 'Acción',
      dataIndex: 'accion',
      width: 120,
      render: (valor) => <Tag color={ETIQUETAS_ACCION[valor]?.[1]}>{ETIQUETAS_ACCION[valor]?.[0] ?? valor}</Tag>,
    },
    {
      title: 'Detalle',
      dataIndex: 'errores',
      render: (errores) => (errores?.length ? <Text type="secondary" className="text-xs">{errores.join(' ')}</Text> : '—'),
    },
  ];

  return (
    <Modal
      open={open}
      onCancel={handleCancel}
      footer={null}
      title="Importar colaboradores desde Excel"
      width={780}
      destroyOnHidden
    >
      <div className="space-y-4">
        <Alert
          type="info"
          showIcon
          message="Este importador solo crea colaboradores nuevos"
          description="Si el documento ya existe en el sistema, esa fila queda con error y no se modifica nada del colaborador existente."
        />

        <Button icon={<DownloadOutlined />} onClick={handleDescargarPlantilla}>
          Descargar plantilla
        </Button>

        {!previsualizacion && (
          <Upload.Dragger
            accept=".xlsx"
            multiple={false}
            beforeUpload={prepararArchivo}
            showUploadList={false}
            disabled={cargando}
          >
            <p className="ant-upload-drag-icon"><UploadOutlined /></p>
            <p className="ant-upload-text">
              {cargando ? 'Analizando archivo...' : 'Arrastra el Excel de colaboradores aquí o haz clic para seleccionarlo'}
            </p>
            <p className="ant-upload-hint">Formato XLSX, basado en la plantilla — máximo 10 MB</p>
          </Upload.Dragger>
        )}

        {previsualizacion && (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <FileExcelOutlined className="text-xl text-emerald-600" />
              <span className="font-semibold">{previsualizacion.archivo_nombre}</span>
              <Button type="link" size="small" className="ml-auto" onClick={reiniciar}>
                Cambiar archivo
              </Button>
            </div>

            <div className="grid grid-cols-2 gap-x-8 gap-y-3 md:grid-cols-3">
              {[
                ['Colaboradores detectados', previsualizacion.colaboradores_detectados],
                ['Se crearán', previsualizacion.resumen.crear],
                ['Con error', previsualizacion.resumen.con_error],
              ].map(([label, value]) => (
                <div key={label}>
                  <Text type="secondary" className="block text-xs">{label}</Text>
                  <span className="font-semibold">{value}</span>
                </div>
              ))}
            </div>

            {previsualizacion.filas_invalidas > 0 && (
              <Alert
                type="warning"
                showIcon
                message={`${previsualizacion.filas_invalidas} filas del Excel no se reconocieron (faltan nombres o documento) y se ignoraron.`}
              />
            )}

            {previsualizacion.resumen.con_error > 0 && (
              <Alert
                type="warning"
                showIcon
                message="Algunas filas no se importarán"
                description="Corrige el Excel (documento duplicado, sede/área/horario con nombre distinto al registrado, datos faltantes) y vuelve a subirlo."
              />
            )}

            <Table
              size="small"
              rowKey="numero_documento"
              columns={columnas}
              dataSource={previsualizacion.colaboradores}
              pagination={{ pageSize: 10 }}
            />

            <Button
              type="primary"
              block
              size="large"
              loading={cargando}
              disabled={previsualizacion.resumen.crear === 0}
              onClick={handleConfirmar}
            >
              Confirmar importación ({previsualizacion.resumen.crear} colaboradores)
            </Button>
          </div>
        )}
      </div>
    </Modal>
  );
}
