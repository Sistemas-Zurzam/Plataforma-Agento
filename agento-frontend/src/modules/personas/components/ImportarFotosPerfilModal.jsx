import { UploadOutlined } from '@ant-design/icons';
import { App, Alert, Button, Modal, Typography, Upload } from 'antd';
import { useState } from 'react';
import { useColaboradores } from '../hooks/useColaboradores';

const { Text } = Typography;

export default function ImportarFotosPerfilModal({ open, todasEmpresas, onCancel, onImportado }) {
  const { importarFotosMasivo } = useColaboradores();
  const { message } = App.useApp();
  const [archivos, setArchivos] = useState([]);
  const [cargando, setCargando] = useState(false);
  const [resultado, setResultado] = useState(null);

  const reiniciar = () => {
    setArchivos([]);
    setResultado(null);
  };

  const handleCancel = () => {
    reiniciar();
    onCancel();
  };

  const handleSubir = async () => {
    if (!archivos.length) return;
    setCargando(true);
    try {
      const data = await importarFotosMasivo(archivos.map((item) => item.originFileObj), todasEmpresas);
      setResultado(data);
      setArchivos([]);
      message.success(`${data.actualizados} foto(s) de perfil actualizadas.`);
      onImportado?.();
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudieron importar las fotos.');
    } finally {
      setCargando(false);
    }
  };

  return (
    <Modal
      open={open}
      onCancel={handleCancel}
      footer={null}
      title="Cargar fotos de perfil masivamente"
      width={620}
      destroyOnHidden
    >
      <div className="space-y-4">
        <Alert
          type="info"
          showIcon
          message="Cada archivo se empareja por su nombre"
          description={'El nombre del archivo debe empezar con el DNI del colaborador — por ejemplo "70826733.jpg" o "70826733-Nombre Apellido.jpg" (se usa solo lo que está antes del guión). Formatos aceptados: JPG, JPEG, PNG o WEBP, máximo 5 MB cada uno.'}
        />

        <Upload.Dragger
          accept=".jpg,.jpeg,.png,.webp"
          multiple
          fileList={archivos}
          beforeUpload={() => false}
          onChange={({ fileList }) => setArchivos(fileList)}
          onRemove={(archivo) => setArchivos((lista) => lista.filter((item) => item.uid !== archivo.uid))}
          disabled={cargando}
        >
          <p className="ant-upload-drag-icon"><UploadOutlined /></p>
          <p className="ant-upload-text">Arrastra las fotos aquí o haz clic para seleccionarlas</p>
          <p className="ant-upload-hint">Puedes seleccionar varias a la vez — nombra cada una con el DNI del colaborador.</p>
        </Upload.Dragger>

        {resultado && (
          <div className="space-y-3">
            <div className="grid grid-cols-3 gap-x-4">
              {[
                ['Actualizadas', resultado.actualizados],
                ['Sin coincidencia', resultado.sin_coincidencia.length],
                ['Duplicadas en el lote', resultado.duplicados.length],
              ].map(([label, value]) => (
                <div key={label}>
                  <Text type="secondary" className="block text-xs">{label}</Text>
                  <span className="font-semibold">{value}</span>
                </div>
              ))}
            </div>

            {resultado.sin_coincidencia.length > 0 && (
              <Alert
                type="warning"
                showIcon
                message="Ningún colaborador tiene este DNI"
                description={resultado.sin_coincidencia.join(', ')}
              />
            )}

            {resultado.duplicados.length > 0 && (
              <Alert
                type="warning"
                showIcon
                message="DNI repetido en el mismo lote (solo se usó el primer archivo)"
                description={resultado.duplicados.join(', ')}
              />
            )}
          </div>
        )}

        <Button
          type="primary"
          block
          size="large"
          loading={cargando}
          disabled={archivos.length === 0}
          onClick={handleSubir}
        >
          Cargar {archivos.length > 0 ? `${archivos.length} foto(s)` : 'fotos'}
        </Button>
      </div>
    </Modal>
  );
}
