import { App, Form, Input, Modal, Select, Table, Tag, Tooltip } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { useSunatCatalogos } from '../../hooks/useSunatCatalogos';
import { ESTADO_COLORS, ESTADO_FILTRO_OPTIONS, ESTADO_LABELS } from '../../utils/sunatEstados';

/**
 * Mapea tipos_ausencia (ya implementado en Asistencia — vacaciones, médico,
 * personal, capacitación, comisión de servicio, otro, falta injustificada)
 * hacia la Tabla 21 de SUNAT. NO se mapea por "estado de asistencia" (eso
 * mezclaría causas distintas): cada fila es una causa real ya modelada.
 * El estado lo calcula el backend (ver SunatCatalogoService::calcularEstado).
 */
export default function SuspensionesTab({ user }) {
  const { message } = App.useApp();
  const { tiposAusencia, tiposAusenciaLoading, fetchTiposAusencia, actualizarTipoAusencia } = useSunatCatalogos();
  const [busqueda, setBusqueda] = useState('');
  const [estadoFiltro, setEstadoFiltro] = useState('todos');
  const [editando, setEditando] = useState(null);
  const [guardando, setGuardando] = useState(false);
  const [form] = Form.useForm();
  const puedeEditar = user?.permisos?.includes('parametros_laborales.editar');

  useEffect(() => {
    fetchTiposAusencia();
  }, [fetchTiposAusencia]);

  const filtrados = useMemo(() => {
    const texto = busqueda.trim().toLowerCase();
    return tiposAusencia.filter((t) => {
      const coincideEstado = estadoFiltro === 'todos' || t.estado === estadoFiltro;
      const coincideTexto = !texto ||
        t.codigo.toLowerCase().includes(texto) ||
        t.nombre.toLowerCase().includes(texto) ||
        (t.codigo_sunat_suspension ?? '').toLowerCase().includes(texto) ||
        (t.descripcion_sunat ?? '').toLowerCase().includes(texto);
      return coincideEstado && coincideTexto;
    });
  }, [tiposAusencia, busqueda, estadoFiltro]);

  const resumen = useMemo(() => {
    const contar = (estado) => tiposAusencia.filter((t) => t.estado === estado).length;
    return [
      contar('configurado') && `${contar('configurado')} configurado(s)`,
      contar('requiere_configuracion') && `${contar('requiere_configuracion')} requiere(n) configuración`,
      contar('bloqueado_por_modelo') && `${contar('bloqueado_por_modelo')} bloqueado(s) por modelo`,
      contar('no_aplica') && `${contar('no_aplica')} no aplica(n)`,
    ].filter(Boolean).join(' · ');
  }, [tiposAusencia]);

  const abrirEdicion = (tipo) => {
    setEditando(tipo);
    form.setFieldsValue({
      codigo_sunat_suspension: tipo.codigo_sunat_suspension ?? '',
      descripcion_sunat: tipo.descripcion_sunat ?? '',
    });
  };

  const guardar = async (values) => {
    setGuardando(true);
    try {
      await actualizarTipoAusencia(editando.id, {
        codigo_sunat_suspension: values.codigo_sunat_suspension?.trim() || null,
        descripcion_sunat: values.descripcion_sunat?.trim() || null,
      });
      message.success('Mapeo de suspensión actualizado');
      setEditando(null);
    } catch (err) {
      message.error(err.response?.data?.message ?? 'No se pudo actualizar el mapeo');
    } finally {
      setGuardando(false);
    }
  };

  const columns = [
    { title: 'Código interno', dataIndex: 'codigo', render: (v) => <code className="text-xs">{v}</code> },
    { title: 'Nombre', dataIndex: 'nombre' },
    {
      title: 'Código SUNAT (Tabla 21)',
      dataIndex: 'codigo_sunat_suspension',
      render: (v) => (v ? <code className="text-xs">{v}</code> : <span className="text-gray-300">—</span>),
    },
    { title: 'Descripción SUNAT', dataIndex: 'descripcion_sunat', render: (v) => v ?? <span className="text-gray-300">—</span> },
    {
      title: 'Estado',
      key: 'estado',
      render: (_, t) => <Tag color={ESTADO_COLORS[t.estado]}>{ESTADO_LABELS[t.estado]}</Tag>,
    },
    {
      title: 'Motivo',
      dataIndex: 'sunat_motivo_estado',
      render: (v) => (v ? <Tooltip title={v}><span className="line-clamp-1 max-w-xs cursor-help text-xs text-gray-500">{v}</span></Tooltip> : <span className="text-gray-300">—</span>),
    },
    {
      title: 'Acciones',
      key: 'acciones',
      render: (_, t) => (
        <a onClick={() => puedeEditar && abrirEdicion(t)} className={!puedeEditar ? 'pointer-events-none text-gray-300' : ''}>
          Editar mapeo
        </a>
      ),
    },
  ];

  return (
    <div>
      <p className="mb-1 text-sm text-gray-500">
        Alimentará, en el futuro, la estructura .snl de PLAME (Tabla 21 SUNAT) a partir de asistencia → tipo de ausencia → mapeo.
      </p>
      {resumen && <p className="mb-4 text-xs text-gray-400">{resumen}</p>}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Input.Search
          allowClear
          placeholder="Buscar por código, nombre o descripción..."
          className="w-72"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
        />
        <Select value={estadoFiltro} onChange={setEstadoFiltro} className="w-56" options={ESTADO_FILTRO_OPTIONS} />
      </div>
      <Table rowKey="id" loading={tiposAusenciaLoading} columns={columns} dataSource={filtrados} pagination={false} scroll={{ x: 900 }} />

      <Modal
        title={`Mapeo SUNAT — ${editando?.nombre ?? ''}`}
        open={!!editando}
        onCancel={() => setEditando(null)}
        onOk={() => form.submit()}
        okText="Guardar"
        cancelText="Cancelar"
        confirmLoading={guardando}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={guardar}>
          <Form.Item label="Código SUNAT (Tabla 21)" name="codigo_sunat_suspension">
            <Input placeholder="Sin configurar" maxLength={20} />
          </Form.Item>
          <Form.Item label="Descripción SUNAT" name="descripcion_sunat">
            <Input placeholder="Descripción oficial del código (opcional)" maxLength={255} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
