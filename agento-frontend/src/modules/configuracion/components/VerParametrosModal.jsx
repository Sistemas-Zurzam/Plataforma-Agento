import { Modal } from 'antd';
import { formatParametroValor } from '../../../utils/formatNumber';
import { agruparParametros } from '../utils/agruparParametros';

export default function VerParametrosModal({ open, regimen, onCancel }) {
  const grupos = regimen ? agruparParametros(regimen.parametros) : [];

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
                <div key={parametro.definicion_id}>
                  <p className="truncate text-xs text-gray-500">{parametro.nombre}</p>
                  <p className="text-sm font-semibold text-gray-900">
                    {formatParametroValor(parametro.valor, parametro.unidad)}
                  </p>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </Modal>
  );
}
