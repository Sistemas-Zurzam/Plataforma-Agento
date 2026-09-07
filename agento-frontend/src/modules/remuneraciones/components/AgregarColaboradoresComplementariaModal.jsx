import { App, Input, Modal, Table, Tag } from 'antd';
import { useEffect, useMemo, useState } from 'react';

export default function AgregarColaboradoresComplementariaModal({ open, item, api, onCancel, onAdded }) {
  const { message } = App.useApp();
  const [filas, setFilas] = useState([]);
  const [seleccion, setSeleccion] = useState([]);
  const [busqueda, setBusqueda] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || !item) return;
    setSeleccion([]);
    setBusqueda('');
    setLoading(true);
    api.fetchColaboradoresDisponiblesComplementaria(item.id)
      .then(setFilas)
      .catch((e) => message.error(e.response?.data?.message ?? 'No se pudieron cargar los colaboradores disponibles.'))
      .finally(() => setLoading(false));
  }, [open, item, api, message]);

  const filtradas = useMemo(() => {
    const termino = busqueda.trim().toLocaleLowerCase();
    if (!termino) return filas;
    return filas.filter((fila) => `${fila.colaborador} ${fila.documento ?? ''}`.toLocaleLowerCase().includes(termino));
  }, [filas, busqueda]);

  const agregar = async () => {
    if (!seleccion.length) return message.warning('Selecciona al menos un colaborador.');
    setSaving(true);
    try {
      await api.agregarColaboradoresComplementaria(item.id, seleccion);
      message.success(`${seleccion.length} colaborador(es) agregado(s). Usa el botón + para registrar sus bonificaciones.`);
      onAdded();
    } catch (e) {
      message.error(Object.values(e.response?.data?.errors ?? {})?.[0]?.[0] ?? e.response?.data?.message ?? 'No se pudieron agregar los colaboradores.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      title={`Agregar colaboradores — ${item?.nombre ?? ''}`}
      open={open}
      onCancel={onCancel}
      onOk={agregar}
      okText={`Agregar${seleccion.length ? ` (${seleccion.length})` : ''}`}
      confirmLoading={saving}
      okButtonProps={{ disabled: !seleccion.length }}
      width={760}
      destroyOnHidden
    >
      <p className="mb-3 text-sm text-gray-600">
        Selecciona colaboradores del ciclo pagado que todavía no estén en otra complementaria pendiente. Se agregarán con diferencia cero para que registres la comisión o bonificación con el botón +.
      </p>
      <Input.Search className="mb-3" allowClear placeholder="Buscar por nombre o documento" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} />
      <Table
        rowKey="boleta_id"
        size="small"
        loading={loading}
        dataSource={filtradas}
        rowSelection={{ selectedRowKeys: seleccion, onChange: setSeleccion, preserveSelectedRowKeys: true }}
        pagination={{ defaultPageSize: 10, showSizeChanger: true, pageSizeOptions: [10, 20, 50, 100] }}
        locale={{ emptyText: 'No hay colaboradores disponibles para agregar.' }}
        columns={[
          { title: 'Colaborador', dataIndex: 'colaborador' },
          { title: 'Documento', dataIndex: 'documento', width: 130 },
          { title: 'Régimen', dataIndex: 'regimen_laboral', width: 180, render: (valor) => <Tag>{valor}</Tag> },
        ]}
      />
    </Modal>
  );
}
