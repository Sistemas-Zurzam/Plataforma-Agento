import { ReloadOutlined } from '@ant-design/icons';
import { Button, DatePicker, Input, Select, Table, Tag } from 'antd';
import { useCallback, useEffect, useState } from 'react';
import DetalleEventoDrawer from '../components/DetalleEventoDrawer';
import { COLORES_METODO, formatearFecha, TIPOS_EVENTO } from '../constants/auditoria';
import { useAuditoria } from '../hooks/useAuditoria';

const { RangePicker } = DatePicker;

const FILTROS_INICIALES = {
  usuario_id: undefined,
  tipo: undefined,
  modulo: undefined,
  resultado: undefined,
  rango: null,
  q: '',
};

function aParametros({ rango, ...resto }) {
  return {
    ...resto,
    desde: rango?.[0]?.format('YYYY-MM-DD'),
    hasta: rango?.[1]?.format('YYYY-MM-DD'),
  };
}

export default function Auditoria() {
  const { eventos, loading, pagination, filtrosDisponibles, fetchEventos, fetchFiltros, fetchDetalle } = useAuditoria();
  const [filtros, setFiltros] = useState(FILTROS_INICIALES);
  const [eventoSeleccionado, setEventoSeleccionado] = useState(null);

  const buscar = useCallback(
    (valores, page = 1, perPage = 25) => fetchEventos(aParametros(valores), page, perPage).catch(() => {}),
    [fetchEventos],
  );

  useEffect(() => {
    fetchFiltros().catch(() => {});
    buscar(FILTROS_INICIALES);
  }, [fetchFiltros, buscar]);

  const cambiarFiltro = (cambio) => {
    const siguiente = { ...filtros, ...cambio };
    setFiltros(siguiente);
    buscar(siguiente);
  };

  const columnas = [
    { title: 'Fecha', dataIndex: 'fecha', width: 170, render: formatearFecha },
    {
      title: 'Usuario',
      dataIndex: 'usuario',
      render: (usuario) => (
        <div className="flex flex-col">
          <span>{usuario.nombre ?? '—'}</span>
          {usuario.rol && <span className="text-xs text-gray-400">{usuario.rol}</span>}
        </div>
      ),
    },
    {
      title: 'Tipo',
      dataIndex: 'tipo',
      width: 110,
      render: (tipo) => <Tag color={TIPOS_EVENTO[tipo]?.color}>{TIPOS_EVENTO[tipo]?.texto ?? tipo}</Tag>,
    },
    {
      title: 'Acción',
      dataIndex: 'accion',
      render: (accion, evento) => (
        <span className="flex items-center gap-2">
          {evento.metodo && <Tag color={COLORES_METODO[evento.metodo]}>{evento.metodo}</Tag>}
          <span className="break-all">{evento.metodo ? accion.replace(`${evento.metodo} `, '') : accion}</span>
          {evento.tiene_cambios && <Tag color="gold">Cambios</Tag>}
        </span>
      ),
    },
    { title: 'Módulo', dataIndex: 'modulo', width: 130, render: (modulo) => modulo ?? '—' },
    {
      title: 'Resultado',
      dataIndex: 'status_code',
      width: 100,
      render: (codigo) => (codigo == null ? '—' : <Tag color={codigo >= 400 ? 'red' : 'green'}>{codigo}</Tag>),
    },
    { title: 'IP', dataIndex: 'ip', width: 130, render: (ip) => ip ?? '—' },
    {
      title: '',
      key: 'detalle',
      width: 80,
      render: (_, evento) => (
        <Button type="link" size="small" onClick={() => setEventoSeleccionado(evento.id)}>
          Ver
        </Button>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="text-lg font-semibold">Auditoría</h2>
        <p className="text-sm text-gray-500">
          Bitácora de las acciones realizadas por los usuarios de la empresa activa.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Input.Search
          allowClear
          placeholder="Buscar acción, usuario o ruta"
          className="w-64"
          onSearch={(q) => cambiarFiltro({ q })}
        />
        <Select
          allowClear
          showSearch={{ optionFilterProp: 'label' }}
          placeholder="Usuario"
          className="w-48"
          value={filtros.usuario_id}
          options={filtrosDisponibles.usuarios.map((u) => ({ value: u.id, label: u.nombre }))}
          onChange={(usuario_id) => cambiarFiltro({ usuario_id })}
        />
        <Select
          allowClear
          placeholder="Tipo"
          className="w-36"
          value={filtros.tipo}
          options={Object.entries(TIPOS_EVENTO).map(([value, { texto }]) => ({ value, label: texto }))}
          onChange={(tipo) => cambiarFiltro({ tipo })}
        />
        <Select
          allowClear
          placeholder="Módulo"
          className="w-40"
          value={filtros.modulo}
          options={filtrosDisponibles.modulos.map((m) => ({ value: m, label: m }))}
          onChange={(modulo) => cambiarFiltro({ modulo })}
        />
        <Select
          allowClear
          placeholder="Resultado"
          className="w-36"
          value={filtros.resultado}
          options={[
            { value: 'exitoso', label: 'Exitoso' },
            { value: 'error', label: 'Con error' },
          ]}
          onChange={(resultado) => cambiarFiltro({ resultado })}
        />
        <RangePicker value={filtros.rango} onChange={(rango) => cambiarFiltro({ rango })} />
        <Button icon={<ReloadOutlined />} onClick={() => buscar(filtros, pagination.current, pagination.pageSize)}>
          Actualizar
        </Button>
      </div>

      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        dataSource={eventos}
        columns={columnas}
        scroll={{ x: 'max-content' }}
        pagination={{
          ...pagination,
          showSizeChanger: true,
          pageSizeOptions: [25, 50, 100],
          showTotal: (total) => `${total} eventos`,
        }}
        onChange={(pag) => buscar(filtros, pag.current, pag.pageSize)}
      />

      <DetalleEventoDrawer
        eventoId={eventoSeleccionado}
        onClose={() => setEventoSeleccionado(null)}
        fetchDetalle={fetchDetalle}
      />
    </div>
  );
}
