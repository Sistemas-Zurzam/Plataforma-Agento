import { CalculatorOutlined, ReloadOutlined } from '@ant-design/icons';
import { App, Badge, Button, Empty, Table, Tabs, Tag, Tooltip, Typography } from 'antd';
import { useCallback, useEffect, useState } from 'react';
import LiquidacionesCeseTab from '../../modules/remuneraciones/components/LiquidacionesCeseTab';
import LiquidarColaboradorModal from '../../modules/remuneraciones/components/LiquidarColaboradorModal';
import api from '../../services/api';

const { Title, Text } = Typography;
const fecha = (valor) => (valor ? String(valor).slice(0, 10).split('-').reverse().join('/') : '—');

/**
 * Nóminas → Liquidaciones. El cese se registra en la ficha del colaborador
 * (solo fija hasta qué día se paga); aquí se liquidan los beneficios de los
 * colaboradores ya cesados y se registra su aprobación y pago.
 */
export default function GestionLiquidaciones({ user }) {
  const { message } = App.useApp();
  const [pestana, setPestana] = useState('pendientes');
  const [pendientes, setPendientes] = useState([]);
  const [cargando, setCargando] = useState(false);
  const [liquidando, setLiquidando] = useState(null);
  const [recargarLista, setRecargarLista] = useState(0);

  const esAdmin = user?.role === 'administrador';
  const puede = (permiso) => esAdmin || user?.permisos?.includes(permiso);
  const puedeCalcular = puede('nominas.calcular');

  const cargarPendientes = useCallback(async () => {
    setCargando(true);
    try {
      const { data } = await api.get('/liquidaciones-cese/pendientes');
      setPendientes(data.data ?? []);
    } catch (error) {
      message.error(error.response?.data?.message ?? 'No se pudieron cargar los colaboradores pendientes');
    } finally { setCargando(false); }
  }, [message]);

  useEffect(() => { cargarPendientes(); }, [cargarPendientes, user?.empresa?.id]);

  const alRegistrar = () => {
    setLiquidando(null);
    cargarPendientes();
    setRecargarLista((n) => n + 1);
    setPestana('liquidaciones');
  };

  const columnas = [
    {
      title: 'Colaborador',
      render: (_, f) => <div>
        <strong>{f.nombre}</strong>
        {f.falta_sueldo && <Tooltip title="No hay sueldo registrado a la fecha de cese: lo completarás al liquidar."><Tag color="gold" className="ml-2">Falta sueldo</Tag></Tooltip>}
        <p className="text-xs text-gray-400">{f.documento}{f.legajo ? ` · ${f.legajo}` : ''}</p>
      </div>,
    },
    { title: 'Cargo', dataIndex: 'cargo', render: (v, f) => <div>{v}<p className="text-xs text-gray-400">{f.area}</p></div> },
    { title: 'Ingreso', dataIndex: 'fecha_ingreso', render: fecha },
    { title: 'Cese', dataIndex: 'fecha_cese', render: fecha },
    { title: 'Motivo', dataIndex: 'motivo_cese', ellipsis: true },
    {
      title: '', width: 120,
      render: (_, f) => puedeCalcular ? <Button size="small" type="primary" icon={<CalculatorOutlined />} onClick={() => setLiquidando(f)}>Liquidar</Button> : null,
    },
  ];

  return (
    <div className="space-y-4">
      <div>
        <Title level={3} className="!mb-1">Liquidaciones</Title>
        <Text type="secondary">Beneficios por cese de {user?.empresa?.nombre_comercial ?? 'la empresa activa'}. El cese se registra en la ficha del colaborador; el sueldo de su último mes se paga en la planilla del ciclo.</Text>
      </div>
      <Tabs
        activeKey={pestana}
        onChange={setPestana}
        items={[
          {
            key: 'pendientes',
            label: <span>Pendientes de liquidar <Badge count={pendientes.length} showZero={false} className="ml-1" /></span>,
            children: (
              <div className="space-y-3">
                <div className="flex justify-end"><Button icon={<ReloadOutlined />} onClick={cargarPendientes}>Actualizar</Button></div>
                <Table rowKey="id" loading={cargando} dataSource={pendientes} columns={columnas} scroll={{ x: 820 }} pagination={{ pageSize: 20 }}
                  locale={{ emptyText: <Empty description="No hay colaboradores cesados pendientes de liquidar" /> }} />
              </div>
            ),
          },
          {
            key: 'liquidaciones',
            label: 'Historial de liquidaciones',
            children: <LiquidacionesCeseTab empresaId={user?.empresa?.id} puedeAprobar={puede('nominas.aprobar')} puedePagar={puede('nominas.pagar')} recargarSenal={recargarLista} />,
          },
        ]}
      />
      <LiquidarColaboradorModal open={Boolean(liquidando)} colaborador={liquidando} onCancel={() => setLiquidando(null)} onRegistrada={alRegistrar} />
    </div>
  );
}
