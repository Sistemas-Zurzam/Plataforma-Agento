import { FileTextOutlined, IdcardOutlined } from '@ant-design/icons';
import { Empty, List, Modal, Tag } from 'antd';

const ICONO_POR_CATEGORIA = {
  documento: <FileTextOutlined />,
  previsional: <IdcardOutlined />,
};

/**
 * El backend (EvaluarCompletitudColaborador, vía ColaboradorResource) ya
 * calcula qué le falta a cada colaborador — este modal solo lo muestra.
 * Mismo campo `documentos_faltantes` que pinta la columna "Documentos" del
 * listado, sin volver a pedir nada al servidor.
 */
export default function DocumentosFaltantesModal({ colaborador, onClose }) {
  const faltantes = colaborador?.documentos_faltantes ?? [];

  return (
    <Modal
      title={`Datos y documentos pendientes — ${colaborador?.nombre_completo ?? ''}`}
      open={Boolean(colaborador)}
      onCancel={onClose}
      footer={null}
      width={480}
      destroyOnHidden
    >
      {faltantes.length === 0 ? (
        <Empty description="Todo completo" image={Empty.PRESENTED_IMAGE_SIMPLE} />
      ) : (
        <List
          dataSource={faltantes}
          renderItem={(item) => (
            <List.Item>
              <div className="flex w-full items-center justify-between">
                <span className="flex items-center gap-2 text-gray-700">
                  {ICONO_POR_CATEGORIA[item.categoria] ?? <FileTextOutlined />}
                  {item.etiqueta}
                </span>
                <Tag color={item.categoria === 'previsional' ? 'orange' : 'red'}>
                  {item.categoria === 'previsional' ? 'Dato previsional' : 'Documento'}
                </Tag>
              </div>
            </List.Item>
          )}
        />
      )}
    </Modal>
  );
}
