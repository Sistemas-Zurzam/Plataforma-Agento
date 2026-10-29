import { PlusOutlined } from '@ant-design/icons';
import { App, Button, Dropdown, Input, Select, Table, Tag } from 'antd';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import MotivoModal from '../components/MotivoModal';
import PruebaEditorModal from '../components/PruebaEditorModal';
import { mensajeDeError } from '../constants/seleccion';
import { usePruebas } from '../hooks/useSeleccion';

const ESTADOS = { borrador: 'default', publicada: 'green', archivada: 'default' };

export default function EvaluacionesPage() {
  const { message } = App.useApp();
  const [estado, setEstado] = useState();
  const [busqueda, setBusqueda] = useState('');
  const [pagina, setPagina] = useState(1);
  const filtros = useMemo(() => ({ estado, busqueda: busqueda || undefined, page: pagina, per_page: 20 }), [estado, busqueda, pagina]);
  const { datos, cargando, error, recargar, acciones } = usePruebas(filtros);
  const [editor, setEditor] = useState({ abierto: false, prueba: null });
  const [validando, setValidando] = useState(null);

  const ejecutar = async (accion, exito) => {
    try {
      const r = await accion();
      message.success(exito);
      recargar();
      return r;
    } catch (e) {
      message.error(mensajeDeError(e));
      throw e;
    }
  };

  const alAccion = async (prueba, key) => {
    try {
      if (key === 'editar') setEditor({ abierto: true, prueba: await acciones.obtener(prueba.id) });
      if (key === 'publicar') await ejecutar(() => acciones.publicar(prueba.id), 'Prueba publicada.');
      if (key === 'version') {
        const nueva = await ejecutar(() => acciones.nuevaVersion(prueba.id), 'Nueva versión en borrador.');
        setEditor({ abierto: true, prueba: nueva });
      }
      if (key === 'validar') setValidando(prueba);
      if (key === 'archivar') await ejecutar(() => acciones.archivar(prueba.id), 'Prueba archivada.');
    } catch (e) {
      if (!e?.response) message.error(mensajeDeError(e));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Banco de pruebas</h2>
          <p className="text-sm text-gray-500">Pruebas versionadas con tiempo controlado por el servidor. Se asignan desde la ficha del candidato.</p>
        </div>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditor({ abierto: true, prueba: null })}>Nueva prueba</Button>
      </div>
      <div className="flex flex-wrap gap-2">
        <Input.Search placeholder="Buscar prueba" allowClear className="max-w-xs" onSearch={(v) => { setPagina(1); setBusqueda(v); }} />
        <Select allowClear placeholder="Estado" className="min-w-40" value={estado} onChange={(v) => { setPagina(1); setEstado(v); }}
          options={Object.keys(ESTADOS).map((e) => ({ value: e, label: e }))} />
      </div>
      <EstadoSeccion cargando={cargando && !datos} error={error} onReintentar={recargar}>
        <Table
          rowKey="id"
          loading={cargando}
          dataSource={datos?.data ?? []}
          scroll={{ x: 800 }}
          locale={{ emptyText: 'Aún no hay pruebas. Crea una con contenido aprobado por tu equipo.' }}
          pagination={{ current: datos?.meta?.current_page ?? 1, pageSize: 20, total: datos?.meta?.total ?? 0, onChange: setPagina, showSizeChanger: false }}
          columns={[
            { title: 'Prueba', render: (_, p) => <span className="font-medium">{p.nombre} <span className="text-xs text-gray-400">v{p.version}</span></span> },
            { title: 'Tipo', dataIndex: 'tipo' },
            { title: 'Preguntas', dataIndex: 'preguntas_count', align: 'center' },
            { title: 'Tiempo', dataIndex: 'tiempo_limite_minutos', render: (m) => `${m} min` },
            { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS[e]}>{e}</Tag> },
            { title: 'Validación', render: (_, p) => (p.validada ? <Tag color="green">Validada</Tag> : <Tag color="orange">No validada</Tag>) },
            {
              title: '',
              render: (_, p) => {
                const items = [
                  ...(p.estado === 'borrador' ? [{ key: 'editar', label: 'Editar' }, { key: 'publicar', label: 'Publicar' }] : []),
                  ...(p.estado === 'publicada' ? [{ key: 'version', label: 'Crear nueva versión' }] : []),
                  ...(p.estado === 'publicada' && !p.validada ? [{ key: 'validar', label: 'Registrar validación' }] : []),
                  ...(p.estado !== 'archivada' ? [{ key: 'archivar', label: 'Archivar', danger: true }] : []),
                ];
                return items.length ? (
                  <Dropdown trigger={['click']} menu={{ items, onClick: ({ key }) => alAccion(p, key) }}>
                    <Button size="small">Acciones</Button>
                  </Dropdown>
                ) : null;
              },
            },
          ]}
        />
      </EstadoSeccion>

      <PruebaEditorModal
        abierto={editor.abierto}
        prueba={editor.prueba}
        onCerrar={() => setEditor({ abierto: false, prueba: null })}
        onGuardar={async (valores) => {
          if (editor.prueba) {
            await acciones.actualizar(editor.prueba.id, valores);
          } else {
            await acciones.crear(valores);
          }
          message.success('Prueba guardada en borrador.');
          recargar();
        }}
      />
      <MotivoModal
        abierto={Boolean(validando)}
        titulo="Registrar validación del instrumento"
        efecto="Solo marca como validada una prueba con contenido y reglas aprobados. Queda registrado quién la valida y con qué sustento."
        etiquetaMotivo="Fuente o criterio de validación (mín. 20 caracteres)"
        onCerrar={() => setValidando(null)}
        onConfirmar={async ({ motivo }) => {
          await ejecutar(() => acciones.validar(validando.id, motivo), 'Validación registrada.');
          setValidando(null);
        }}
      />
    </div>
  );
}
