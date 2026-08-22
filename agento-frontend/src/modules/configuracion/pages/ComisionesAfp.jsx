import { DeleteOutlined, EditOutlined, HistoryOutlined, PlusOutlined, SafetyOutlined } from '@ant-design/icons';
import { App, Button, Modal, Select, Spin, Table, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { formatParametroValor } from '../../../utils/formatNumber';
import NuevaComisionModal from '../components/NuevaComisionModal';
import { useComisionesAfp } from '../hooks/useComisionesAfp';

const HISTORIAL_COLUMNS = [
  { title: 'Vigente desde', dataIndex: 'vigencia_desde' },
  { title: 'Aporte (%)', dataIndex: 'aporte_obligatorio_porcentaje' },
  { title: 'Prima (%)', dataIndex: 'prima_seguro_porcentaje' },
  { title: 'Com. flujo (%)', dataIndex: 'comision_flujo_porcentaje' },
  { title: 'Com. mixta (%)', dataIndex: 'comision_mixta_porcentaje' },
  { title: 'Motivo', dataIndex: 'motivo', render: (motivo) => motivo ?? '—' },
  { title: 'Registrado por', dataIndex: 'creado_por', render: (nombre) => nombre ?? '—' },
  { title: 'Fecha de registro', dataIndex: 'creado_en' },
];

export default function ComisionesAfp({ user }) {
  const {
    afps,
    loading,
    fetchComisiones,
    crearComision,
    actualizarComision,
    eliminarComision,
    cargarSbs2024,
    fetchHistorial,
  } = useComisionesAfp();
  const { message, modal } = App.useApp();
  const [filtroAfp, setFiltroAfp] = useState('todas');
  const [modalOpen, setModalOpen] = useState(false);
  const [afpEnEdicion, setAfpEnEdicion] = useState(null);
  const [creando, setCreando] = useState(false);
  const [cargandoSbs, setCargandoSbs] = useState(false);
  const [historialAfp, setHistorialAfp] = useState(null);
  const [historial, setHistorial] = useState([]);
  const [cargandoHistorial, setCargandoHistorial] = useState(false);

  const puedeEditar = user?.permisos?.includes('comisiones_afp.editar');

  useEffect(() => {
    fetchComisiones();
  }, [fetchComisiones]);

  const afpsFiltradas = filtroAfp === 'todas' ? afps : afps.filter((a) => a.afp_id === filtroAfp);

  const handleCrear = async (values) => {
    setCreando(true);
    try {
      if (afpEnEdicion) {
        await actualizarComision(afpEnEdicion.comision_id, values);
        message.success('Comisión corregida correctamente');
      } else {
        await crearComision(values);
        message.success('Comisión registrada correctamente');
      }
      setModalOpen(false);
      setAfpEnEdicion(null);
    } catch (err) {
      message.error(
        err.response?.data?.message ?? 'No se pudo guardar la comisión',
      );
    } finally {
      setCreando(false);
    }
  };

  const handleAbrirCrear = () => {
    setAfpEnEdicion(null);
    setModalOpen(true);
  };

  const handleAbrirEditar = (afp) => {
    setAfpEnEdicion(afp);
    setModalOpen(true);
  };

  const handleVerHistorial = async (afp) => {
    setHistorialAfp(afp);
    setCargandoHistorial(true);
    try {
      const data = await fetchHistorial(afp.afp_id);
      setHistorial(data);
    } catch {
      message.error('No se pudo cargar el historial');
      setHistorialAfp(null);
    } finally {
      setCargandoHistorial(false);
    }
  };

  const handleEliminar = (afp) => {
    modal.confirm({
      title: `¿Eliminar la comisión vigente de ${afp.nombre}?`,
      content: 'Solo se puede eliminar si es el registro más reciente. La tarjeta volverá a mostrar la comisión anterior, si existe.',
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        try {
          await eliminarComision(afp.comision_id);
          message.success('Comisión eliminada');
        } catch (err) {
          message.error(
            err.response?.data?.message ?? 'No se pudo eliminar la comisión',
          );
        }
      },
    });
  };

  const handleCargarSbs = () => {
    modal.confirm({
      title: 'Cargar SBS 2024',
      content:
        'Se completarán únicamente las AFP que todavía no tengan ninguna comisión registrada. Las que ya tienen datos no se modifican.',
      okText: 'Cargar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setCargandoSbs(true);
        try {
          await cargarSbs2024();
          message.success('Tasas SBS 2024 cargadas');
        } catch {
          message.error('No se pudo cargar las tasas SBS 2024');
        } finally {
          setCargandoSbs(false);
        }
      },
    });
  };

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Comisiones AFP</h2>
          <p className="text-sm text-gray-500">
            Tasas por AFP, tipo de comisión y periodo de vigencia.
          </p>
        </div>
        {puedeEditar && (
          <div className="flex gap-2">
            <Button icon={<SafetyOutlined />} loading={cargandoSbs} onClick={handleCargarSbs}>
              Cargar SBS 2024
            </Button>
            <Button
              type="primary"
              icon={<PlusOutlined />}
              onClick={handleAbrirCrear}
              style={{
                background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
                border: 'none',
              }}
            >
              Nueva Comisión
            </Button>
          </div>
        )}
      </div>

      <div className="mb-4 flex items-center gap-3">
        <Select
          value={filtroAfp}
          onChange={setFiltroAfp}
          className="w-48"
          options={[
            { value: 'todas', label: 'Todas las AFP' },
            ...afps.map((afp) => ({ value: afp.afp_id, label: afp.nombre })),
          ]}
        />
        <span className="text-sm text-gray-500">{afpsFiltradas.length} registro(s)</span>
      </div>

      <Spin spinning={loading}>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {afpsFiltradas.map((afp) => (
            <div
              key={afp.afp_id}
              className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"
            >
              <div className="mb-3 flex items-start justify-between">
                <div>
                  <h3 className="font-medium text-gray-900">{afp.nombre}</h3>
                  <p className="text-xs text-gray-400">
                    {afp.vigencia_desde
                      ? `Desde ${afp.vigencia_desde} (vigente)`
                      : 'Sin comisión registrada'}
                  </p>
                </div>
                <div className="flex items-center gap-1">
                  {afp.comision_id && <Tag color="blue">Flujo</Tag>}
                  <Button
                    type="text"
                    size="small"
                    icon={<HistoryOutlined />}
                    aria-label={`Ver historial de ${afp.nombre}`}
                    onClick={() => handleVerHistorial(afp)}
                  />
                  {puedeEditar && afp.comision_id && (
                    <>
                      <Button
                        type="text"
                        size="small"
                        icon={<EditOutlined />}
                        aria-label={`Editar comisión de ${afp.nombre}`}
                        onClick={() => handleAbrirEditar(afp)}
                      />
                      <Button
                        type="text"
                        danger
                        size="small"
                        icon={<DeleteOutlined />}
                        aria-label={`Eliminar comisión de ${afp.nombre}`}
                        onClick={() => handleEliminar(afp)}
                      />
                    </>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                <div>
                  <p className="text-xs text-gray-500">Aporte obligatorio</p>
                  <p className="text-sm font-semibold text-gray-900">
                    {formatParametroValor(afp.aporte_obligatorio_porcentaje, '%')}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Prima seguro</p>
                  <p className="text-sm font-semibold text-gray-900">
                    {formatParametroValor(afp.prima_seguro_porcentaje, '%')}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Comisión flujo</p>
                  <p className="text-sm font-semibold text-gray-900">
                    {formatParametroValor(afp.comision_flujo_porcentaje, '%')}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Comisión mixta</p>
                  <p className="text-sm font-semibold text-gray-900">
                    {formatParametroValor(afp.comision_mixta_porcentaje, '%')}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Sobre saldo (anual)</p>
                  <p className="text-sm font-semibold text-gray-900">
                    {formatParametroValor(afp.sobre_saldo_anual_porcentaje, '%')}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Total flujo</p>
                  <p className="text-sm font-semibold text-green-600">
                    {formatParametroValor(afp.total_flujo_porcentaje, '%')}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Total mixta</p>
                  <p className="text-sm font-semibold text-green-600">
                    {formatParametroValor(afp.total_mixta_porcentaje, '%')}
                  </p>
                </div>
              </div>
            </div>
          ))}
        </div>
      </Spin>

      <NuevaComisionModal
        open={modalOpen}
        afps={afps}
        editing={Boolean(afpEnEdicion)}
        initialValues={afpEnEdicion ?? undefined}
        onSubmit={handleCrear}
        onCancel={() => {
          setModalOpen(false);
          setAfpEnEdicion(null);
        }}
        submitting={creando}
      />

      <Modal
        title={historialAfp ? `Historial — ${historialAfp.nombre}` : 'Historial'}
        open={Boolean(historialAfp)}
        onCancel={() => setHistorialAfp(null)}
        footer={null}
        width={800}
        destroyOnHidden
      >
        <Table
          rowKey="id"
          size="small"
          loading={cargandoHistorial}
          dataSource={historial}
          columns={HISTORIAL_COLUMNS}
          pagination={false}
          locale={{ emptyText: 'Sin comisiones registradas para esta AFP' }}
        />
      </Modal>
    </div>
  );
}
