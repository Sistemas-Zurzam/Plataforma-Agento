import { EyeOutlined, FileDoneOutlined, FolderOutlined } from '@ant-design/icons';
import { Avatar, Empty, Modal, Spin, Tabs } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { colorForName, initialsForName } from '../../../utils/avatarColor';
import { TIPO_CONTRATO_OPTIONS } from '../constants/opciones';
import { useColaboradores } from '../hooks/useColaboradores';
import { Legajo } from './FichaColaborador';

function etiquetaContrato(valor) {
  return TIPO_CONTRATO_OPTIONS.find((opcion) => opcion.value === valor)?.label ?? valor ?? '—';
}

function dineroPlano(valor, moneda = 'PEN') {
  if (valor === null || valor === undefined) return '—';
  return `${moneda} ${new Intl.NumberFormat('es-PE').format(Number(valor))}`;
}

function Campo({ label, children }) {
  return (
    <div>
      <p className="text-xs text-gray-500">{label}</p>
      <p className="mt-0.5 text-sm font-semibold text-gray-900">{children ?? '—'}</p>
    </div>
  );
}

function Informacion({ colaborador }) {
  return (
    <div className="grid grid-cols-2 gap-x-6 gap-y-5">
      <Campo label="Legajo">{colaborador.legajo}</Campo>
      <Campo label="Empresa">{colaborador.empresa?.nombre_comercial}</Campo>
      <Campo label="Área">{colaborador.area?.nombre}</Campo>
      <Campo label="Tipo Contrato">{etiquetaContrato(colaborador.tipo_contrato)}</Campo>
      <Campo label="Modalidad">{colaborador.modalidad_trabajo}</Campo>
      <Campo label="Sede">{colaborador.sede?.nombre}</Campo>
      <Campo label="Ingreso">
        {colaborador.fecha_ingreso ? dayjs(colaborador.fecha_ingreso).format('DD/MM/YYYY') : '—'}
      </Campo>
      <Campo label="Celular">{colaborador.celular_colaborador}</Campo>
      <Campo label="Documento">{colaborador.numero_documento}</Campo>
      <Campo label="Salario">
        {dineroPlano(colaborador.remuneracion?.salario, colaborador.remuneracion?.moneda_salario)}
      </Campo>
    </div>
  );
}

function Onboarding({ nombre }) {
  const primerNombre = nombre?.split(' ')[0] ?? '';
  return (
    <div className="flex flex-col items-center gap-3 py-8 text-center">
      <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-green-50 text-2xl text-green-600">
        <FileDoneOutlined />
      </span>
      <p className="font-semibold text-gray-900">Sin onboarding activo</p>
      <p className="max-w-xs text-sm text-gray-500">
        Inicia el proceso de incorporación para {primerNombre.toUpperCase()} y asigna tareas al equipo.
      </p>
    </div>
  );
}

export default function VerColaboradorModal({ open, colaboradorId, onClose }) {
  const { fetchColaborador } = useColaboradores();
  const [colaborador, setColaborador] = useState(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState('informacion');

  useEffect(() => {
    if (!open || !colaboradorId) return;
    setLoading(true);
    setTab('informacion');
    fetchColaborador(colaboradorId)
      .then(setColaborador)
      .catch(() => setColaborador(null))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, colaboradorId]);

  const tabs = colaborador ? [
    {
      key: 'informacion',
      label: <span><EyeOutlined /> Información</span>,
      children: <Informacion colaborador={colaborador} />,
    },
    {
      key: 'legajo',
      label: <span><FolderOutlined /> Legajo</span>,
      children: <Legajo colaborador={colaborador} soloLectura />,
    },
    {
      key: 'onboarding',
      label: <span><FileDoneOutlined /> Onboarding</span>,
      children: <Onboarding nombre={colaborador.nombre_completo} />,
    },
  ] : [];

  return (
    <Modal open={open} onCancel={onClose} footer={null} width={{ xs: '94%', sm: 480 }} destroyOnHidden centered>
      {loading ? (
        <div className="flex min-h-64 items-center justify-center"><Spin size="large" /></div>
      ) : !colaborador ? (
        <Empty description="No se pudo cargar el colaborador" />
      ) : (
        <div>
          <div className="flex min-w-0 items-center gap-3 pr-5">
            <Avatar
              size={56}
              className="shrink-0 text-lg font-semibold"
              style={{ backgroundColor: colorForName(colaborador.nombre_completo) }}
            >
              {initialsForName(colaborador.nombre_completo)}
            </Avatar>
            <div className="min-w-0">
              <h3 className="truncate text-base font-bold text-gray-900">{colaborador.nombre_completo}</h3>
              <p className="truncate text-sm text-gray-400">{colaborador.cargo}</p>
            </div>
          </div>

          <span
            className={`mt-2 ml-[68px] inline-flex w-fit items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
              colaborador.activo ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'
            }`}
          >
            {colaborador.activo ? 'Activo' : 'Inactivo'}
          </span>

          <Tabs className="mt-4 [&_.ant-tabs-ink-bar]:bg-green-600 [&_.ant-tabs-tab-active_.ant-tabs-tab-btn]:text-green-600" activeKey={tab} onChange={setTab} items={tabs} />
        </div>
      )}
    </Modal>
  );
}
