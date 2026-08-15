import { Descriptions, Modal } from 'antd';

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
          <Descriptions.Item label="Empresa">{usuario.empresa?.nombre}</Descriptions.Item>
          <Descriptions.Item label="Área">{usuario.area?.nombre ?? 'Sin asignar'}</Descriptions.Item>
          <Descriptions.Item label="Rol">{usuario.role?.nombre}</Descriptions.Item>
        </Descriptions>
      )}
    </Modal>
  );
}
