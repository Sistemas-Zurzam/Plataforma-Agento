import { CustomerServiceOutlined, EyeOutlined, PlusOutlined } from '@ant-design/icons';
import { Avatar, Button, Input, Select, Table, Tag } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { colorForName, initialsForName } from '../../utils/avatarColor';

const ESTADO_OPTIONS = [
  { value: 'todos', label: 'Todos los estados' },
  { value: 'abierto', label: 'Abierto' },
  { value: 'en_progreso', label: 'En progreso' },
  { value: 'resuelto', label: 'Resuelto' },
  { value: 'cerrado', label: 'Cerrado' },
];

const ESTADO_TAG = {
  abierto: { color: 'blue', label: 'Abierto' },
  en_progreso: { color: 'gold', label: 'En progreso' },
  resuelto: { color: 'green', label: 'Resuelto' },
  cerrado: { color: 'default', label: 'Cerrado' },
};

const PRIORIDAD_OPTIONS = [
  { value: 'todas', label: 'Todas las prioridades' },
  { value: 'baja', label: 'Baja' },
  { value: 'media', label: 'Media' },
  { value: 'alta', label: 'Alta' },
  { value: 'urgente', label: 'Urgente' },
];

const PRIORIDAD_TAG = {
  baja: { color: 'default', label: 'Baja' },
  media: { color: 'blue', label: 'Media' },
  alta: { color: 'orange', label: 'Alta' },
  urgente: { color: 'red', label: 'Urgente' },
};

/**
 * Maqueta de UI únicamente — sin backend, sin permisos propios todavía.
 * Datos fijos en memoria solo para dar forma visual al módulo (Sección
 * pedida por el usuario: "solo maquétame sin backend"). No conectar a
 * ningún endpoint real hasta que exista el módulo de Soporte.
 */
const TICKETS_MOCK = [
  { id: 'TCK-1001', asunto: 'No puedo ver mi boleta de pago de julio', empresa: 'Texajo', solicitante: 'Ana Chávez Paredes', categoria: 'Nóminas', prioridad: 'alta', estado: 'abierto', fecha: '2026-08-18' },
  { id: 'TCK-1002', asunto: 'Marcación de asistencia no registrada el 12/08', empresa: 'Zazu', solicitante: 'Carlos Ramírez Soto', categoria: 'Asistencia', prioridad: 'media', estado: 'en_progreso', fecha: '2026-08-17' },
  { id: 'TCK-1003', asunto: 'Solicito acceso al módulo de Reportes', empresa: 'Agento', solicitante: 'María Torres Vega', categoria: 'Accesos', prioridad: 'baja', estado: 'abierto', fecha: '2026-08-17' },
  { id: 'TCK-1004', asunto: 'Error al calcular la gratificación de un colaborador cesado', empresa: 'Overshark', solicitante: 'Diego Huamán Flores', categoria: 'Nóminas', prioridad: 'urgente', estado: 'en_progreso', fecha: '2026-08-16' },
  { id: 'TCK-1005', asunto: 'Actualizar cuenta bancaria de un colaborador', empresa: 'Bravos', solicitante: 'Valeria Cruz Espinoza', categoria: 'Datos personales', prioridad: 'media', estado: 'resuelto', fecha: '2026-08-14' },
  { id: 'TCK-1006', asunto: 'No llega el correo de bienvenida a nuevos usuarios', empresa: 'Texajo', solicitante: 'Fernando Rodríguez Loayza', categoria: 'Sistema', prioridad: 'baja', estado: 'resuelto', fecha: '2026-08-12' },
  { id: 'TCK-1007', asunto: 'Duda sobre el descuento por tardanza aplicado', empresa: 'Zazu', solicitante: 'Gabriela Ponce Delgado', categoria: 'Asistencia', prioridad: 'baja', estado: 'cerrado', fecha: '2026-08-10' },
  { id: 'TCK-1008', asunto: 'Solicitud de reporte consolidado de planilla', empresa: 'Agento', solicitante: 'Renzo Vargas Injante', categoria: 'Reportes', prioridad: 'media', estado: 'abierto', fecha: '2026-08-09' },
  { id: 'TCK-1009', asunto: 'El horario asignado no coincide con el real', empresa: 'Overshark', solicitante: 'Camila Mendoza Ríos', categoria: 'Asistencia', prioridad: 'alta', estado: 'cerrado', fecha: '2026-08-05' },
  { id: 'TCK-1010', asunto: 'No puedo actualizar mi foto de perfil', empresa: 'Bravos', solicitante: 'Alonso Guerrero Núñez', categoria: 'Sistema', prioridad: 'baja', estado: 'resuelto', fecha: '2026-08-02' },
];

export default function GestionTickets() {
  const [busqueda, setBusqueda] = useState('');
  const [estado, setEstado] = useState('todos');
  const [prioridad, setPrioridad] = useState('todas');

  const filtrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    return TICKETS_MOCK.filter((ticket) => {
      const coincideTexto =
        !texto ||
        ticket.id.toLowerCase().includes(texto) ||
        ticket.asunto.toLowerCase().includes(texto) ||
        ticket.solicitante.toLowerCase().includes(texto);
      const coincideEstado = estado === 'todos' || ticket.estado === estado;
      const coincidePrioridad = prioridad === 'todas' || ticket.prioridad === prioridad;
      return coincideTexto && coincideEstado && coincidePrioridad;
    });
  }, [busqueda, estado, prioridad]);

  const stats = useMemo(
    () => ({
      total: TICKETS_MOCK.length,
      abiertos: TICKETS_MOCK.filter((t) => t.estado === 'abierto').length,
      enProgreso: TICKETS_MOCK.filter((t) => t.estado === 'en_progreso').length,
      resueltos: TICKETS_MOCK.filter((t) => t.estado === 'resuelto' || t.estado === 'cerrado').length,
    }),
    [],
  );

  const columns = [
    {
      title: 'Ticket',
      key: 'ticket',
      render: (_, ticket) => (
        <div>
          <p className="font-mono text-xs font-semibold text-agento-blue-bright">{ticket.id}</p>
          <p className="max-w-xs truncate font-medium text-gray-900">{ticket.asunto}</p>
        </div>
      ),
    },
    {
      title: 'Solicitante',
      key: 'solicitante',
      render: (_, ticket) => (
        <div className="flex items-center gap-2">
          <Avatar size="small" style={{ backgroundColor: colorForName(ticket.solicitante) }}>
            {initialsForName(ticket.solicitante)}
          </Avatar>
          <div className="min-w-0">
            <p className="truncate text-sm text-gray-900">{ticket.solicitante}</p>
            <p className="truncate text-xs text-gray-400">{ticket.empresa}</p>
          </div>
        </div>
      ),
    },
    { title: 'Categoría', dataIndex: 'categoria' },
    {
      title: 'Prioridad',
      dataIndex: 'prioridad',
      render: (valor) => <Tag color={PRIORIDAD_TAG[valor].color}>{PRIORIDAD_TAG[valor].label}</Tag>,
    },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (valor) => <Tag color={ESTADO_TAG[valor].color}>{ESTADO_TAG[valor].label}</Tag>,
    },
    {
      title: 'Fecha',
      dataIndex: 'fecha',
      render: (fecha) => dayjs(fecha).format('DD/MM/YYYY'),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      width: 64,
      render: () => (
        <Button type="text" size="small" icon={<EyeOutlined />} disabled title="Próximamente" />
      ),
    },
  ];

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Gestión de Tickets</h2>
          <p className="text-sm text-gray-500">Soporte técnico y solicitudes de las empresas del grupo</p>
        </div>
        <Button
          type="primary"
          icon={<PlusOutlined />}
          disabled
          title="Próximamente"
          style={{
            background: 'linear-gradient(135deg, #1c6fe0 0%, #014693 100%)',
            border: 'none',
          }}
        >
          Nuevo Ticket
        </Button>
      </div>

      <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-agento-blue-light text-lg text-agento-blue">
            <CustomerServiceOutlined />
          </span>
          <div>
            <p className="text-lg font-semibold text-gray-900">{stats.total}</p>
            <p className="text-xs text-gray-500">Total Tickets</p>
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <div>
            <p className="text-lg font-semibold text-gray-900">{stats.abiertos}</p>
            <p className="text-xs text-gray-500">Abiertos</p>
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <div>
            <p className="text-lg font-semibold text-gray-900">{stats.enProgreso}</p>
            <p className="text-xs text-gray-500">En progreso</p>
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
          <div>
            <p className="text-lg font-semibold text-gray-900">{stats.resueltos}</p>
            <p className="text-xs text-gray-500">Resueltos / Cerrados</p>
          </div>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search
          placeholder="Buscar por ticket, asunto o solicitante..."
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          className="max-w-xs"
          allowClear
        />
        <Select value={estado} onChange={setEstado} className="w-44" options={ESTADO_OPTIONS} />
        <Select value={prioridad} onChange={setPrioridad} className="w-48" options={PRIORIDAD_OPTIONS} />
        <span className="text-sm text-gray-500">{filtrados.length} ticket(s)</span>
      </div>

      <Table
        rowKey="id"
        columns={columns}
        dataSource={filtrados}
        pagination={{ pageSize: 10 }}
      />
    </div>
  );
}
