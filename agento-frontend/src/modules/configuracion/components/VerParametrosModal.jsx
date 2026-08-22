import { HistoryOutlined } from '@ant-design/icons';
import { Button, Modal, Table } from 'antd';
import { useState } from 'react';
import { formatParametroValor } from '../../../utils/formatNumber';
import { useParametrosLaborales } from '../hooks/useParametrosLaborales';
import { agruparParametros } from '../utils/agruparParametros';

const HISTORIAL_COLUMNS = [
  { title: 'Vigente desde', dataIndex: 'vigencia_desde' },
  { title: 'Valor', dataIndex: 'valor' },
  { title: 'Motivo', dataIndex: 'motivo', render: (motivo) => motivo ?? '—' },
  { title: 'Registrado por', dataIndex: 'creado_por', render: (nombre) => nombre ?? '—' },
  { title: 'Fecha de registro', dataIndex: 'creado_en' },
];

export default function VerParametrosModal({ open, regimen, onCancel }) {
  const { fetchHistorial } = useParametrosLaborales();
  const grupos = regimen ? agruparParametros(regimen.parametros) : [];
  const [parametroHistorial, setParametroHistorial] = useState(null);
  const [historial, setHistorial] = useState([]);
  const [cargandoHistorial, setCargandoHistorial] = useState(false);

  const handleVerHistorial = async (parametro) => {
    setParametroHistorial(parametro);
    setCargandoHistorial(true);
    try {
      const data = await fetchHistorial(parametro.definicion_id, regimen.regimen);
      setHistorial(data);
    } finally {
      setCargandoHistorial(false);
    }
  };

  return (
    <Modal
      title={regimen ? `Parámetros — ${regimen.regimen}` : ''}
      open={open}
      onCancel={onCancel}
      footer={null}
      centered
      destroyOnHidden
    >
      <div className="flex flex-col gap-5">
        {grupos.map((grupo) => (
          <div key={grupo.nombre}>
            <p className="mb-2 text-xs font-semibold tracking-wide text-gray-400 uppercase">
              {grupo.nombre}
            </p>
            <div className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
              {grupo.parametros.map((parametro) => (
                <div key={parametro.definicion_id} className="flex items-start justify-between gap-1">
                  <div className="min-w-0">
                    <p className="truncate text-xs text-gray-500">{parametro.nombre}</p>
                    <p className="text-sm font-semibold text-gray-900">
                      {formatParametroValor(parametro.valor, parametro.unidad)}
                    </p>
                  </div>
                  <Button
                    type="text"
                    size="small"
                    icon={<HistoryOutlined />}
                    aria-label={`Ver historial de ${parametro.nombre}`}
                    onClick={() => handleVerHistorial(parametro)}
                  />
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>

      <Modal
        title={parametroHistorial ? `Historial — ${parametroHistorial.nombre}` : 'Historial'}
        open={Boolean(parametroHistorial)}
        onCancel={() => setParametroHistorial(null)}
        footer={null}
        width={700}
        destroyOnHidden
      >
        <Table
          rowKey="id"
          size="small"
          loading={cargandoHistorial}
          dataSource={historial}
          columns={HISTORIAL_COLUMNS}
          pagination={false}
          locale={{ emptyText: 'Sin vigencias registradas para este parámetro' }}
        />
      </Modal>
    </Modal>
  );
}
