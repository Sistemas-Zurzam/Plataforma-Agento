import {
  ArrowDownOutlined,
  ArrowUpOutlined,
  CalendarOutlined,
  CreditCardOutlined,
  DollarCircleOutlined,
  PrinterOutlined,
  UserOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import { Button, Modal, Tag } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';

// Aportaciones del empleador que SÍ debe ver el trabajador en su boleta —
// ESSALUD y SIS son mutuamente excluyentes (SIS es la alternativa de Micro
// Empresa/REMYPE a ESSALUD, nunca ambas a la vez para el mismo colaborador).
// El resto del catálogo 'aportacion' (CTS_PROVISION, GRATIFICACION_LEGAL,
// BONIFICACION_EXTRAORDINARIA, VACACIONES_PROVISION) son provisiones
// contables internas que no corresponde mostrarle al colaborador.
const CODIGOS_APORTE_VISIBLE = ['ESSALUD', 'SIS_APORTACION'];

function soles(valor) {
  return `S/ ${Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function numero(valor, decimales = 0) {
  return Number(valor ?? 0).toLocaleString('es-PE', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}

function texto(valor) {
  return valor === null || valor === undefined || valor === '' ? '—' : valor;
}

function sino(valor) {
  return valor ? 'Sí' : 'No';
}

function moneda(codigo) {
  if (codigo === 'USD') return 'Dólares (US$)';
  if (codigo === 'PEN') return 'Soles (S/)';
  return '—';
}

function sumaCantidad(conceptos, codigo) {
  return conceptos.filter((c) => c.codigo === codigo).reduce((total, c) => total + Number(c.cantidad ?? 0), 0);
}

function Dato({ label, value }) {
  return (
    <div className="flex justify-between gap-3 border-b border-gray-100 py-1 text-[11px] last:border-0">
      <span className="text-gray-500">{label}</span>
      <span className="text-right font-semibold text-gray-800">{value}</span>
    </div>
  );
}

function Tarjeta({ titulo, icono, children, pie }) {
  return (
    <div className="overflow-hidden rounded-lg border border-gray-200 print:break-inside-avoid">
      <div className="flex items-center gap-1.5 bg-agento-blue px-3 py-1.5 text-[11px] font-semibold tracking-wide text-white uppercase">
        {icono}
        {titulo}
      </div>
      <div className="bg-white px-3 py-2">{children}</div>
      {pie}
    </div>
  );
}

function TablaConceptos({ conceptos, vacio }) {
  if (!conceptos.length) return <p className="py-2 text-[11px] text-gray-400">{vacio}</p>;

  return (
    <table className="w-full text-[11px]">
      <tbody>
        {conceptos.map((c) => (
          <tr key={c.id} className="border-b border-gray-100 last:border-0">
            <td className="py-1 pr-2 align-top text-gray-700">
              {c.nombre}
              {c.formula_texto && <span className="block text-[10px] text-gray-400">{c.formula_texto}</span>}
            </td>
            <td className="py-1 text-right align-top font-semibold whitespace-nowrap text-gray-900">{soles(c.monto)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function FilaTotal({ label, valor }) {
  return (
    <div className="flex justify-between bg-agento-blue-light px-3 py-1.5 text-xs font-bold text-agento-blue-dark">
      <span className="uppercase tracking-wide">{label}</span>
      <span>{soles(valor)}</span>
    </div>
  );
}

/**
 * Vista imprimible de una boleta YA calculada — renderiza el snapshot tal
 * cual quedó guardado, nunca recalcula nada. "Imprimir" usa window.print()
 * con CSS que oculta el resto de la página, para que el usuario elija
 * "Guardar como PDF" desde el diálogo del navegador — evita agregar una
 * librería de generación de PDF en el backend solo para esto.
 */
export default function BoletaImprimibleModal({ open, onCancel, boletaId, verBoleta }) {
  const [detalle, setDetalle] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!open || !boletaId) return;
    let activo = true;
    setLoading(true);
    verBoleta(boletaId).then((data) => { if (activo) setDetalle(data); }).finally(() => activo && setLoading(false));
    return () => { activo = false; };
  }, [open, boletaId, verBoleta]);

  const conceptos = detalle?.conceptos ?? [];
  const esOficial = detalle?.estado === 'pagada';
  const ingresos = conceptos.filter((c) => c.tipo === 'ingreso');
  const egresos = conceptos.filter((c) => c.tipo === 'egreso');
  // V3 R1 — la boleta entregada al trabajador solo muestra ESSALUD/SIS en
  // "Aportes del empleador", aunque el catálogo interno (ConceptoRemuneracion.tipo)
  // siga clasificando CTS/gratificación/bonificación extraordinaria/
  // vacaciones/SIS como 'aportacion' — eso NO se toca (sigue siendo
  // correcto para PLAME, contabilidad y CalcularBoletaColaborador). Se
  // filtra acá, en la presentación, por el código estable del catálogo
  // (nunca por el nombre visible) para no depender de que el nombre visible
  // se siga llamando igual en el futuro.
  const aportacionesVisibles = conceptos.filter((c) => c.tipo === 'aportacion' && CODIGOS_APORTE_VISIBLE.includes(c.codigo));
  const totalAportacionesVisibles = aportacionesVisibles.reduce((suma, c) => suma + Number(c.monto ?? 0), 0);

  const empresa = detalle?.empresa;
  const empresaTitulo = [empresa?.razon_social, empresa?.nombre_comercial]
    .filter((valor, indice, lista) => valor && lista.indexOf(valor) === indice)
    .join(' - ') || detalle?.colaborador?.empresa || '—';

  const periodoLabel = detalle?.ciclo?.nombre
    || (detalle?.ciclo?.fecha_fin ? dayjs(detalle.ciclo.fecha_fin).format('MMMM YYYY').toUpperCase() : '');

  const datosPago = detalle?.datos_pago;
  const ausencias = detalle?.ausencias_periodo;
  const sistemaPrevisional = detalle?.colaborador?.sistema_previsional === 'onp' ? 'ONP' : 'AFP';

  return (
    <Modal
      title={esOficial ? 'Boleta oficial' : 'Previsualización de boleta'}
      open={open}
      onCancel={onCancel}
      footer={[
        <Button key="cerrar" onClick={onCancel}>Cerrar</Button>,
        <Button key="imprimir" type="primary" icon={<PrinterOutlined />} onClick={() => window.print()}>
          {esOficial ? 'Descargar boleta oficial' : 'Imprimir vista previa'}
        </Button>,
      ]}
      width={{ xs: '95%', sm: '92%', md: 820 }}
      destroyOnHidden
    >
      {loading || !detalle ? (
        <p className="p-6 text-center text-sm text-gray-400">Cargando boleta...</p>
      ) : (
        <div id="boleta-imprimible" className="overflow-hidden rounded-xl border border-gray-200">
          {!esOficial && (
            <div className="border-b border-orange-200 bg-orange-50 px-3 py-2 text-center text-xs font-semibold tracking-wide text-orange-700 uppercase print:hidden">
              Previsualización — documento no oficial, sujeto a cambios hasta que la boleta se marque como pagada
            </div>
          )}

          {/* Header de marca Agento */}
          <div className="flex items-center justify-between gap-4 bg-gradient-to-br from-agento-blue to-agento-blue-dark px-5 py-4 text-white">
            <div>
              <p className="text-xs font-semibold tracking-widest uppercase opacity-80">Agento</p>
              <p className="text-lg leading-tight font-bold">Boleta de Pago</p>
              {periodoLabel && <p className="text-xs tracking-wide uppercase opacity-90">{periodoLabel}</p>}
            </div>
            <div className="shrink-0 rounded-lg border border-white/30 px-3 py-1.5 text-right">
              <p className="text-[9px] tracking-widest uppercase opacity-80">N° Boleta</p>
              <p className="text-sm font-bold">{texto(detalle.numero_boleta)}</p>
            </div>
          </div>

          {/* Barra con los datos de la empresa */}
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-5 py-2.5">
            <div>
              <p className="text-sm font-bold text-gray-900">{empresaTitulo}</p>
              <p className="text-[11px] text-gray-500">RUC: {texto(empresa?.ruc)} · {texto(empresa?.direccion)}</p>
            </div>
            <Tag className="print:!hidden" color={esOficial ? 'green' : 'orange'}>{esOficial ? 'Oficial' : `Versión ${detalle.version} — no oficial`}</Tag>
          </div>

          <div className="space-y-3 p-4 print:space-y-1.5 print:p-2">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 print:gap-1.5">
              <Tarjeta titulo="Datos del trabajador" icono={<UserOutlined />}>
                <Dato label="Apellidos y Nombres" value={detalle.colaborador?.nombre_completo} />
                <Dato label="DNI" value={texto(detalle.colaborador?.numero_documento)} />
                <Dato label="Código" value={texto(detalle.colaborador?.legajo)} />
                <Dato label="Cargo" value={texto(detalle.colaborador?.cargo)} />
                <Dato label="Área" value={texto(detalle.colaborador?.area)} />
                <Dato label="Fecha de Ingreso" value={detalle.colaborador?.fecha_ingreso ? dayjs(detalle.colaborador.fecha_ingreso).format('DD/MM/YYYY') : '—'} />
                <Dato label="Régimen Laboral" value={texto(detalle.regimen_laboral)} />
                <Dato label="AFP / ONP" value={sistemaPrevisional} />
                <Dato label="CUSPP" value={detalle.colaborador?.cuspp || 'No registrado'} />
              </Tarjeta>

              <Tarjeta titulo="Información del período" icono={<CalendarOutlined />}>
                <Dato label="Días Laborados" value={numero(detalle.dias_pagados, 0)} />
                <Dato label="Horas Extras 25%" value={numero(sumaCantidad(conceptos, 'HE_25'), 1)} />
                <Dato label="Horas Extras 35%" value={numero(sumaCantidad(conceptos, 'HE_35'), 1)} />
                <Dato label="Vacaciones" value={sino(ausencias?.vacaciones)} />
                <Dato label="Descanso Médico" value={sino(ausencias?.descanso_medico)} />
                <Dato label="Licencias" value={ausencias?.licencia || 'Ninguna'} />
              </Tarjeta>
            </div>

            <Tarjeta
              titulo="Ingresos"
              icono={<DollarCircleOutlined />}
              pie={<FilaTotal label="Total ingresos" valor={detalle.total_ingresos} />}
            >
              <TablaConceptos conceptos={ingresos} vacio="Sin ingresos registrados" />
            </Tarjeta>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 print:gap-1.5">
              <Tarjeta
                titulo="Descuentos"
                icono={<ArrowDownOutlined />}
                pie={<FilaTotal label="Total descuentos" valor={detalle.total_egresos} />}
              >
                <TablaConceptos conceptos={egresos} vacio="Sin descuentos" />
              </Tarjeta>

              <Tarjeta
                titulo="Aportes del empleador (informativo)"
                icono={<ArrowUpOutlined />}
                pie={aportacionesVisibles.length > 0 && <FilaTotal label="Total aportes" valor={totalAportacionesVisibles} />}
              >
                <TablaConceptos conceptos={aportacionesVisibles} vacio="Sin aportes" />
              </Tarjeta>

              <Tarjeta titulo="Datos de pago" icono={<CreditCardOutlined />}>
                <Dato label="Banco" value={texto(datosPago?.banco)} />
                <Dato label="Cuenta" value={texto(datosPago?.numero_cuenta)} />
                <Dato label="CCI" value={texto(datosPago?.cci)} />
                <Dato label="Moneda" value={moneda(datosPago?.moneda)} />
              </Tarjeta>
            </div>

            <div className="flex overflow-hidden rounded-lg">
              <div className="flex flex-1 flex-col justify-center bg-agento-blue-dark px-4 py-3 text-white">
                <span className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide"><WalletOutlined /> Neto a pagar</span>
                <span className="text-[10px] uppercase opacity-80">Abonado en cuenta</span>
              </div>
              <div className="flex items-center bg-agento-blue-light px-6 text-xl font-extrabold text-agento-blue-dark">
                {soles(detalle.neto_a_pagar)}
              </div>
            </div>

            <div className="rounded-md bg-gray-50 px-3 py-2 text-[10px] text-gray-400">
              <p>Documento emitido electrónicamente conforme al D.S. N° 001-98-TR y normas complementarias. Esta boleta ha sido firmada digitalmente y puede verificarse mediante el código QR.</p>
              <p className="mt-1">Calculado el {detalle.calculado_at} · Parámetros: {detalle.snapshot_parametros_version} · Reglas: {detalle.snapshot_reglas_version}</p>
            </div>
          </div>
        </div>
      )}

      <style>{`
        /* Margen por defecto del navegador (~2.5cm) sobra espacio en una
           boleta de una sola página — con esto suele alcanzar para que
           entre completa sin partirse en 2. */
        @page { margin: 6mm; }
        @media print {
          /* #root es el resto de la app detrás del modal (la tabla de
             planilla, tarjetas, etc.) — con visibility:hidden solo se
             invisibiliza, pero sigue ocupando su alto real en el layout, lo
             que infla la página impresa y genera una 2da página. display:none
             lo saca del flujo por completo. */
          #root { display: none !important; }
          body * { visibility: hidden; }
          #boleta-imprimible, #boleta-imprimible * { visibility: visible; }
          /* El wrapper de Ant Design (.ant-modal-wrap) es position:fixed +
             overflow:auto acotado al viewport, y .ant-modal es
             position:relative — eso convierte a .ant-modal en el ancestro
             posicionado de #boleta-imprimible, así que un position:absolute
             ahí quedaba recortado por el overflow:auto del wrapper (se
             perdía el encabezado de arriba y el texto del pie se
             superponía). Se neutraliza toda la cadena a static/visible para
             que el contenido fluya en la página impresa como un documento
             normal, sin las restricciones de "ventana modal en pantalla". */
          .ant-modal-root, .ant-modal-mask, .ant-modal-wrap, .ant-modal,
          .ant-modal-content, .ant-modal-body {
            position: static !important;
            inset: auto !important;
            top: auto !important;
            overflow: visible !important;
            height: auto !important;
            max-height: none !important;
            box-shadow: none !important;
          }
          /* El Modal tiene width:820 fijo para pantalla (prop "width" del
             componente) — sin resetearlo también, #boleta-imprimible al
             100% seguía significando "100% de 820px", no de la hoja
             completa, y quedaba una columna angosta centrada rodeada de
             margen en blanco. */
          .ant-modal, .ant-modal-content {
            width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
          }
          #boleta-imprimible { position: static; width: 100%; }
          /* Los navegadores no imprimen fondos de color por defecto (para
             ahorrar tinta) — sin esto el header/tarjetas de marca salen en
             blanco y negro al "Guardar como PDF". */
          #boleta-imprimible * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
      `}</style>
    </Modal>
  );
}
