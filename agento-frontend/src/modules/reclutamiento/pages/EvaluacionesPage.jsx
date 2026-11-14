import { DownloadOutlined, EyeOutlined, PlusOutlined, SnippetsOutlined, UploadOutlined } from '@ant-design/icons';
import { App, Button, Dropdown, Input, Modal, Select, Space, Table, Tag, Upload } from 'antd';
import { useMemo, useState } from 'react';
import EstadoSeccion from '../components/EstadoSeccion';
import MotivoModal from '../components/MotivoModal';
import PruebaEditorModal from '../components/PruebaEditorModal';
import VerPruebaModal from '../components/VerPruebaModal';
import { mensajeDeError } from '../constants/seleccion';
import { useAreasPruebas, usePruebas } from '../hooks/useSeleccion';

const ESTADOS = {
  borrador: { label: 'Borrador', color: 'default' },
  publicada: { label: 'Publicada', color: 'green' },
  archivada: { label: 'Desactivada', color: 'red' },
};

export default function EvaluacionesPage({ user }) {
  const { message, modal } = App.useApp();
  const [estado, setEstado] = useState();
  const [busqueda, setBusqueda] = useState('');
  const [pagina, setPagina] = useState(1);
  const [tipo, setTipo] = useState();
  const [area, setArea] = useState();
  const filtros = useMemo(
    () => ({ estado, tipo, area, busqueda: busqueda || undefined, page: pagina, per_page: 20 }),
    [estado, tipo, area, busqueda, pagina],
  );
  const { datos, cargando, error, recargar: recargarPruebas, acciones } = usePruebas(filtros);
  const areas = useAreasPruebas();
  const recargar = () => {
    recargarPruebas();
    areas.recargar();
  };
  const [editor, setEditor] = useState({ abierto: false, prueba: null, inicial: null, advertencias: [] });
  const [pegado, setPegado] = useState({ abierto: false, texto: '', nombre: '' });
  const [validando, setValidando] = useState(null);
  const [importando, setImportando] = useState(false);
  // El banco de Agento solo lo administra un administrador de Agento.
  const esAdministrador = user?.role === 'administrador';
  const puedeGestionar = (prueba) => prueba.origen !== 'agento' || esAdministrador;

  // Importar es una vista previa: abre el editor con lo leído y nada se
  // guarda hasta "Guardar borrador".
  const abrirVistaPrevia = async (leer) => {
    setImportando(true);
    try {
      const { contenido, advertencias } = await leer();
      setEditor({ abierto: true, prueba: null, inicial: contenido, advertencias });
      return true;
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo leer el banco de preguntas.'));
      return false;
    } finally {
      setImportando(false);
    }
  };

  const importarArchivo = (archivo) => {
    abrirVistaPrevia(() => acciones.importarArchivo(archivo));
    return false;
  };

  const [vistaPrueba, setVistaPrueba] = useState({ abierto: false, prueba: null, cargando: false, error: null });
  const verPreguntas = async (id) => {
    setVistaPrueba({ abierto: true, prueba: null, cargando: true, error: null });
    try {
      setVistaPrueba({ abierto: true, prueba: await acciones.obtener(id), cargando: false, error: null });
    } catch (e) {
      setVistaPrueba({ abierto: true, prueba: null, cargando: false, error: e });
    }
  };

  const descargarPlantilla = async () => {
    try {
      const blob = await acciones.plantilla();
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');
      enlace.href = url;
      enlace.download = 'plantilla_banco_preguntas.xlsx';
      enlace.click();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (e) {
      message.error(mensajeDeError(e, 'No se pudo descargar la plantilla.'));
    }
  };

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
      if (key === 'editar') setEditor({ abierto: true, prueba: await acciones.obtener(prueba.id), inicial: null, advertencias: [] });
      if (key === 'publicar') await ejecutar(() => acciones.publicar(prueba.id), 'Prueba publicada.');
      if (key === 'version') {
        const nueva = await ejecutar(
          () => acciones.nuevaVersion(prueba.id),
          'Editando una nueva versión en borrador. La actual sigue en uso hasta que publiques la nueva.',
        );
        setEditor({ abierto: true, prueba: nueva, inicial: null, advertencias: [] });
      }
      if (key === 'validar') setValidando(prueba);
      if (key === 'desactivar') {
        modal.confirm({
          title: `¿Desactivar “${prueba.nombre}” v${prueba.version}?`,
          content: prueba.estado === 'publicada'
            ? 'Dejará de enviarse a quienes postulen y no se podrá asignar. Los resultados ya registrados se conservan. Puedes activarla de nuevo.'
            : 'Quedará desactivada. Puedes activarla de nuevo.',
          okText: 'Desactivar',
          okButtonProps: { danger: true },
          cancelText: 'Cancelar',
          onOk: () => ejecutar(() => acciones.archivar(prueba.id), 'Prueba desactivada.'),
        });
      }
      if (key === 'activar') {
        await ejecutar(
          () => acciones.reactivar(prueba.id),
          prueba.usos > 0 ? 'Prueba activada y publicada de nuevo.' : 'Prueba activada en borrador: revísala y publícala.',
        );
      }
      if (key === 'eliminar') {
        modal.confirm({
          title: `¿Eliminar “${prueba.nombre}” v${prueba.version}?`,
          content: 'Se borran sus preguntas, opciones y guía de corrección. Esta acción no se puede deshacer.',
          okText: 'Eliminar',
          okButtonProps: { danger: true },
          cancelText: 'Cancelar',
          onOk: () => ejecutar(() => acciones.eliminar(prueba.id), 'Prueba eliminada.'),
        });
      }
    } catch (e) {
      if (!e?.response) message.error(mensajeDeError(e));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Banco de pruebas</h2>
          <p className="text-sm text-gray-500">Las del <strong>banco de Agento</strong> sirven para los procesos de todas las empresas. Se envían al postular según la configuración de cada proceso, o se asignan desde la ficha del candidato.</p>
        </div>
        <Space wrap>
          <Button icon={<DownloadOutlined />} onClick={descargarPlantilla}>Plantilla Excel</Button>
          <Upload accept=".xlsx,.xls,.csv,.json" showUploadList={false} beforeUpload={importarArchivo}>
            <Button icon={<UploadOutlined />} loading={importando}>Importar Excel / CSV / JSON</Button>
          </Upload>
          <Button icon={<SnippetsOutlined />} onClick={() => setPegado({ abierto: true, texto: '', nombre: '' })}>Pegar desde PDF o Word</Button>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditor({ abierto: true, prueba: null, inicial: null, advertencias: [] })}>Nueva prueba</Button>
        </Space>
      </div>
      <div className="flex flex-wrap gap-2">
        <Input.Search placeholder="Buscar prueba" allowClear className="max-w-xs" onSearch={(v) => { setPagina(1); setBusqueda(v); }} />
        <Select allowClear placeholder="Tipo" className="min-w-40" value={tipo} onChange={(v) => { setPagina(1); setTipo(v); setArea(undefined); }}
          options={[{ value: 'psicometrica', label: 'Psicométrica' }, { value: 'conocimientos', label: 'Conocimientos' }]} />
        {tipo !== 'psicometrica' && (areas.datos ?? []).length > 0 && (
          <Select allowClear showSearch placeholder="Profesión o área" className="min-w-52" value={area} onChange={(v) => { setPagina(1); setArea(v); }}
            options={(areas.datos ?? []).map((a) => ({ value: a, label: a }))} />
        )}
        <Select allowClear placeholder="Estado" className="min-w-40" value={estado} onChange={(v) => { setPagina(1); setEstado(v); }}
          options={Object.entries(ESTADOS).map(([value, { label }]) => ({ value, label }))} />
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
            { title: 'Banco', dataIndex: 'origen', render: (o) => (o === 'agento' ? <Tag color="blue">Agento</Tag> : <Tag>Empresa</Tag>) },
            {
              title: 'Tipo',
              render: (_, p) => (p.tipo === 'conocimientos'
                ? <span>Conocimientos{p.area_conocimiento ? <Tag color="purple" className="ml-2">{p.area_conocimiento}</Tag> : <span className="ml-2 text-xs text-gray-400">sin área</span>}</span>
                : 'Psicométrica'),
            },
            { title: 'Preguntas', dataIndex: 'preguntas_count', align: 'center' },
            { title: 'Tiempo', dataIndex: 'tiempo_limite_minutos', render: (m) => `${m} min` },
            { title: 'Estado', dataIndex: 'estado', render: (e) => <Tag color={ESTADOS[e]?.color}>{ESTADOS[e]?.label ?? e}</Tag> },
            { title: 'Validación', render: (_, p) => (p.validada ? <Tag color="green">Validada</Tag> : <Tag color="orange">No validada</Tag>) },
            {
              title: '',
              width: 220,
              render: (_, p) => {
                const ver = <Button size="small" icon={<EyeOutlined />} onClick={() => verPreguntas(p.id)}>Ver preguntas</Button>;
                if (!puedeGestionar(p)) {
                  return ver;
                }
                const enUso = (p.usos ?? 0) > 0;
                const items = [
                  ...(p.estado === 'borrador' ? [{ key: 'editar', label: 'Editar' }, { key: 'publicar', label: 'Publicar' }] : []),
                  ...(p.estado === 'publicada' ? [{ key: 'version', label: 'Editar (crea una nueva versión)' }] : []),
                  ...(p.estado === 'publicada' && !p.validada ? [{ key: 'validar', label: 'Registrar validación' }] : []),
                  ...(p.estado !== 'archivada' ? [{ key: 'desactivar', label: 'Desactivar' }] : [{ key: 'activar', label: 'Activar' }]),
                  { type: 'divider' },
                  {
                    key: 'eliminar',
                    danger: true,
                    disabled: enUso,
                    label: enUso ? `Eliminar (no disponible: la rindieron ${p.usos} candidato(s))` : 'Eliminar',
                  },
                ];
                return (
                  <Space size={4}>
                    {ver}
                    {items.length > 0 && (
                      <Dropdown trigger={['click']} menu={{ items, onClick: ({ key }) => alAccion(p, key) }}>
                        <Button size="small">Acciones</Button>
                      </Dropdown>
                    )}
                  </Space>
                );
              },
            },
          ]}
        />
      </EstadoSeccion>

      <VerPruebaModal
        abierto={vistaPrueba.abierto}
        prueba={vistaPrueba.prueba}
        cargando={vistaPrueba.cargando}
        error={vistaPrueba.error}
        onCerrar={() => setVistaPrueba({ abierto: false, prueba: null, cargando: false, error: null })}
        onEditar={vistaPrueba.prueba && vistaPrueba.prueba.estado !== 'archivada' && puedeGestionar(vistaPrueba.prueba)
          ? (prueba) => {
            setVistaPrueba({ abierto: false, prueba: null, cargando: false, error: null });
            alAccion(prueba, prueba.estado === 'borrador' ? 'editar' : 'version');
          }
          : undefined}
      />
      <PruebaEditorModal
        abierto={editor.abierto}
        prueba={editor.prueba}
        inicial={editor.inicial}
        advertencias={editor.advertencias}
        areas={areas.datos ?? []}
        puedeElegirBanco={esAdministrador}
        onCerrar={() => setEditor({ abierto: false, prueba: null, inicial: null, advertencias: [] })}
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
      <Modal
        open={pegado.abierto}
        title="Pegar afirmaciones desde PDF o Word"
        okText="Revisar"
        cancelText="Cancelar"
        confirmLoading={importando}
        onCancel={() => setPegado({ abierto: false, texto: '', nombre: '' })}
        onOk={async () => {
          if (await abrirVistaPrevia(() => acciones.importarTexto(pegado.texto, pegado.nombre || undefined))) {
            setPegado({ abierto: false, texto: '', nombre: '' });
          }
        }}
        destroyOnHidden
      >
        <p className="mb-2 text-sm text-gray-600">
          Copia las afirmaciones del documento y pégalas una por línea. Se quitan la numeración y las viñetas; “(R)” al final la marca como invertida.
          Luego, en la revisión, asignas la dimensión de cada una con la guía de corrección a la mano.
        </p>
        <Input className="mb-2" placeholder="Nombre de la prueba" maxLength={150} value={pegado.nombre} onChange={(e) => setPegado((s) => ({ ...s, nombre: e.target.value }))} />
        <Input.TextArea rows={12} value={pegado.texto} onChange={(e) => setPegado((s) => ({ ...s, texto: e.target.value }))} placeholder={'1. Prefiero trabajar sola(o) antes que depender de un equipo.\n2. Resuelvo los desacuerdos con mis compañeros mediante el diálogo.'} />
      </Modal>
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
