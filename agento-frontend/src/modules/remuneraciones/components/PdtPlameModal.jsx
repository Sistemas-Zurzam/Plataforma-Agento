import { CheckCircleOutlined, CloseCircleOutlined, DownloadOutlined, ExclamationCircleOutlined, MinusCircleOutlined } from '@ant-design/icons';
import { App, Alert, Button, Descriptions, Empty, Modal, Segmented, Spin, Tag } from 'antd';
import { useEffect, useState } from 'react';

const ESTADO_ARCHIVO = {
  listo: { color: 'green', icon: <CheckCircleOutlined />, label: 'Listo' },
  observado: { color: 'gold', icon: <ExclamationCircleOutlined />, label: 'Con observaciones' },
  bloqueado: { color: 'red', icon: <CloseCircleOutlined />, label: 'Bloqueado' },
  no_aplica: { color: 'default', icon: <MinusCircleOutlined />, label: 'No aplica' },
};

const NOMBRES_ARCHIVO = {
  jor: '.jor — Jornada laboral',
  snl: '.snl — Días subsidiados / no laborados',
  rem: '.rem — Ingresos, tributos y descuentos',
  ps4: '.ps4 — Prestadores de servicios (4ta)',
  cuarta: '.4ta — Comprobantes de honorarios',
};

function FilaArchivo({ clave, info }) {
  const estado = ESTADO_ARCHIVO[info?.estado] ?? ESTADO_ARCHIVO.no_aplica;
  return (
    <div className="flex items-center justify-between rounded-lg border border-gray-100 bg-white px-3 py-2">
      <div>
        <p className="text-sm font-medium text-gray-800">{NOMBRES_ARCHIVO[clave]}</p>
        <p className="text-xs text-gray-400">{info?.registros ?? 0} registro(s)</p>
      </div>
      <Tag color={estado.color} icon={estado.icon}>{estado.label}</Tag>
    </div>
  );
}

function Hallazgo({ h }) {
  const esError = h.severity === 'error';
  return (
    <div className={`rounded-lg border px-3 py-2 text-xs ${esError ? 'border-red-200 bg-red-50' : 'border-orange-200 bg-orange-50'}`}>
      <div className="flex items-center justify-between gap-2">
        <span className={`font-semibold ${esError ? 'text-red-700' : 'text-orange-700'}`}>
          {h.colaborador_nombre ? `${h.colaborador_nombre} — ` : ''}{h.message}
        </span>
        {h.files?.length > 0 && (
          <span className="shrink-0 text-[10px] uppercase text-gray-400">{h.files.join(', ')}</span>
        )}
      </div>
      {h.action && <p className="mt-1 text-gray-500">→ {h.action}</p>}
    </div>
  );
}

/**
 * Vista de preparación y descarga de PLAME (E7/E14/E15/E18/E20). Consulta
 * PlameValidator al abrir para mostrar el semáforo por archivo y los
 * hallazgos concretos, y dispara la exportación definitiva solo cuando el
 * ciclo está "pagado" — igual que exige el backend (PlameExportService),
 * este modal nunca intenta forzar una descarga que el backend igual
 * rechazaría; los botones ya llegan deshabilitados a ese estado.
 */
export default function PdtPlameModal({ open, onCancel, ciclo, boletaIds = [], fetchValidacion, exportarPlame }) {
  const { message, modal } = App.useApp();
  const [validacion, setValidacion] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [exportando, setExportando] = useState(null);
  const [tipoExportacion, setTipoExportacion] = useState('planilla');

  useEffect(() => {
    if (!open || !ciclo) return;
    let activo = true;
    setTipoExportacion('planilla');
    setCargando(true);
    fetchValidacion(ciclo.id, boletaIds).then((data) => {
      if (activo) setValidacion(data);
    }).finally(() => activo && setCargando(false));
    return () => { activo = false; };
  }, [open, ciclo, boletaIds, fetchValidacion]);

  const ejecutarExportar = async (tipo) => {
    setExportando(tipo);
    try {
      const resultado = await exportarPlame(ciclo.id, tipo, boletaIds);
      if (resultado.descargado) {
        message.success('Archivo(s) PLAME descargado(s) correctamente.');
      } else {
        message.error(resultado.message ?? 'No se pudo generar la exportación PLAME.');
        if (resultado.validacion) setValidacion(resultado.validacion);
      }
    } catch {
      message.error('No se pudo generar la exportación PLAME.');
    } finally {
      setExportando(null);
    }
  };

  const complementarias = validacion?.complementarias_incluidas ?? [];

  const handleExportar = (tipo) => {
    if (tipo === 'rh' || complementarias.length === 0) return ejecutarExportar(tipo);

    modal.confirm({
      title: 'Este PLAME incluye planillas complementarias',
      icon: <ExclamationCircleOutlined />,
      width: 520,
      content: (
        <div className="space-y-2">
          <p className="text-sm text-gray-600">
            Además de las boletas originales del ciclo, se declarará el monto ya corregido de:
          </p>
          <div className="max-h-48 space-y-1.5 overflow-y-auto pr-1">
            {complementarias.map((c, i) => (
              <div key={i} className="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-xs">
                <p className="font-medium text-gray-800">{c.colaborador_nombre}</p>
                <p className="text-gray-500">{c.complementaria_nombre} — {c.motivo}</p>
                <p className={Number(c.diferencia_neta) >= 0 ? 'text-green-600' : 'text-red-500'}>
                  Diferencia: S/ {Number(c.diferencia_neta).toFixed(2)}
                </p>
              </div>
            ))}
          </div>
        </div>
      ),
      okText: 'Sí, incluir y generar',
      cancelText: 'Cancelar',
      onOk: () => ejecutarExportar(tipo),
    });
  };

  const cicloPagado = ciclo?.estado === 'pagado';
  const archivos = validacion?.archivos ?? {};
  const estadoDe = (clave) => archivos[clave]?.estado ?? 'no_aplica';
  const grupoBloqueado = (claves) => claves.some((c) => estadoDe(c) === 'bloqueado');
  const grupoSinRegistros = (claves) => claves.every((c) => estadoDe(c) === 'no_aplica');

  const planillaBloqueada = grupoBloqueado(['jor', 'snl', 'rem']);
  const planillaSinRegistros = grupoSinRegistros(['jor', 'snl', 'rem']);
  const rhBloqueado = grupoBloqueado(['ps4', 'cuarta']);
  const rhSinRegistros = grupoSinRegistros(['ps4', 'cuarta']);

  const hallazgos = validacion?.hallazgos ?? [];
  const archivosPorTipo = {
    planilla: ['jor', 'snl', 'rem'],
    rh: ['ps4', 'cuarta'],
    completo: ['jor', 'snl', 'rem', 'ps4', 'cuarta'],
  };
  const hallazgosFiltrados = hallazgos.filter((hallazgo) => (
    !hallazgo.files?.length
    || hallazgo.files.some((archivo) => archivosPorTipo[tipoExportacion].includes(archivo))
  ));
  const exportacionBloqueada = {
    planilla: planillaBloqueada || planillaSinRegistros,
    rh: rhBloqueado || rhSinRegistros,
    completo: planillaBloqueada || rhBloqueado || (planillaSinRegistros && rhSinRegistros),
  }[tipoExportacion];
  const etiquetaExportacion = {
    planilla: 'Generar PDT Planilla',
    rh: 'Generar PDT Recibos por honorarios',
    completo: 'Generar PDT completo',
  }[tipoExportacion];

  return (
    <Modal
      title="PDT PLAME"
      open={open}
      onCancel={onCancel}
      footer={null}
      width={720}
      destroyOnHidden
    >
      {cargando ? (
        <div className="flex justify-center py-10"><Spin /></div>
      ) : !validacion ? (
        <Empty description="No se pudo cargar la validación PLAME" />
      ) : (
        <div className="space-y-4">
          <Descriptions size="small" column={2} bordered>
            <Descriptions.Item label="Empresa">{ciclo?.empresa?.nombre_comercial}</Descriptions.Item>
            <Descriptions.Item label="Ciclo">{ciclo?.nombre}</Descriptions.Item>
            <Descriptions.Item label="Período">{validacion.periodo?.fecha_inicio} — {validacion.periodo?.fecha_fin}</Descriptions.Item>
            <Descriptions.Item label="Estado del ciclo"><Tag color={cicloPagado ? 'green' : 'orange'}>{ciclo?.estado}</Tag></Descriptions.Item>
            <Descriptions.Item label="Trabajadores">{validacion.resumen?.trabajadores}</Descriptions.Item>
            <Descriptions.Item label="RH">{validacion.resumen?.rh}</Descriptions.Item>
          </Descriptions>

          {boletaIds.length > 0 && (
            <Alert
              type="info"
              showIcon
              message={`Exportación limitada a ${boletaIds.length} colaborador(es) seleccionado(s)`}
              description="La validación y los archivos PDT PLAME incluirán únicamente las boletas seleccionadas en la tabla."
            />
          )}

          {!cicloPagado && (
            <Alert
              type="warning"
              showIcon
              message="El ciclo debe estar pagado para generar los archivos definitivos PLAME."
              description="Esta validación es preliminar mientras tanto — puedes revisar hallazgos y corregir datos, pero la descarga definitiva quedará deshabilitada."
            />
          )}

          {complementarias.length > 0 && tipoExportacion !== 'rh' && (
            <Alert
              type="info"
              showIcon
              message={`Este PLAME incluirá ${complementarias.length} planilla(s) complementaria(s) aprobada(s)/pagada(s)`}
              description="Se declarará el monto ya corregido de esos colaboradores, no solo lo de la boleta original. Se pedirá confirmación al exportar."
            />
          )}

          <div className="rounded-lg border border-gray-100 bg-gray-50 p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo de exportación</p>
            <Segmented
              block
              value={tipoExportacion}
              onChange={setTipoExportacion}
              options={[
                { label: 'Planilla', value: 'planilla', disabled: planillaSinRegistros },
                { label: 'Recibos por honorarios', value: 'rh', disabled: rhSinRegistros },
                { label: 'Todo', value: 'completo' },
              ]}
            />
          </div>

          {(tipoExportacion === 'planilla' || tipoExportacion === 'completo') && (
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Planilla</p>
              <div className="space-y-1.5">
                <FilaArchivo clave="jor" info={archivos.jor} />
                <FilaArchivo clave="snl" info={archivos.snl} />
                <FilaArchivo clave="rem" info={archivos.rem} />
              </div>
            </div>
          )}

          {(tipoExportacion === 'rh' || tipoExportacion === 'completo') && (
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Recibos por honorarios</p>
              {rhSinRegistros ? (
                <p className="text-xs text-gray-400">No existen prestadores RH para este período.</p>
              ) : (
                <div className="space-y-1.5">
                  <FilaArchivo clave="ps4" info={archivos.ps4} />
                  <FilaArchivo clave="cuarta" info={archivos.cuarta} />
                </div>
              )}
            </div>
          )}

          {hallazgosFiltrados.length > 0 && (
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Hallazgos</p>
              <div className="max-h-56 space-y-1.5 overflow-y-auto pr-1">
                {hallazgosFiltrados.map((h, i) => <Hallazgo key={i} h={h} />)}
              </div>
            </div>
          )}

          <div className="flex justify-end border-t border-gray-100 pt-3">
            <Button
              type="primary"
              icon={<DownloadOutlined />}
              loading={exportando === tipoExportacion}
              disabled={!cicloPagado || exportacionBloqueada || exportando}
              onClick={() => handleExportar(tipoExportacion)}
            >
              {etiquetaExportacion}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
