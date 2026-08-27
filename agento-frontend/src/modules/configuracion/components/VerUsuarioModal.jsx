import { Descriptions, Modal, Tag } from 'antd';

export default function VerUsuarioModal({ open, usuario, onCancel }) {
  return (
    <Modal
      title="Detalle de usuario"
      open={open}
      onCancel={onCancel}
      footer={null}
      destroyOnHidden
      centered
    >
      {usuario && (
        <Descriptions column={1} bordered size="small">
          <Descriptions.Item label="Nombre completo">{usuario.name}</Descriptions.Item>
          <Descriptions.Item label="Usuario">{usuario.username}</Descriptions.Item>
          <Descriptions.Item label="Email">{usuario.email}</Descriptions.Item>
          <Descriptions.Item label="Empresa">{usuario.empresa?.nombre_comercial}</Descriptions.Item>
          <Descriptions.Item label="Área">{usuario.area?.nombre ?? 'Sin asignar'}</Descriptions.Item>
          <Descriptions.Item label="Rol">{usuario.role?.nombre}</Descriptions.Item>
          <Descriptions.Item label="Estado">
            <Tag color={usuario.activo ? 'green' : 'default'}>
              {usuario.activo ? 'Activo' : 'Inactivo'}
            </Tag>
          </Descriptions.Item>
        </Descriptions>
      )}
    </Modal>
  );
}
