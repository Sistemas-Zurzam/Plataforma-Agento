import { ClockCircleOutlined, InfoCircleOutlined } from '@ant-design/icons';
import { Button } from 'antd';
import dayjs from 'dayjs';

const TIPOS = {
  laborable_presencial: {
    label: 'Laborable presencial',
    bg: 'bg-green-100',
    text: 'text-green-700',
    border: 'border-green-300',
  },
  home_office: {
    label: 'Home office',
    bg: 'bg-agento-blue-light',
    text: 'text-agento-blue-dark',
    border: 'border-agento-blue/30',
  },
  descanso: {
    label: 'Descanso',
    bg: 'bg-orange-50',
    text: 'text-orange-700',
    border: 'border-orange-300',
  },
  feriado: {
    label: 'Feriado',
    bg: 'bg-red-50',
    text: 'text-red-600',
    border: 'border-red-300',
  },
};

const DIAS_SEMANA_LABELS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/**
 * Ciclo de clic: laborable presencial -> home office -> descanso -> vuelve
 * a laborable presencial. Los feriados no entran en el ciclo (se aplican
 * automáticamente y quedan fijos).
 */
export function siguienteTipoCiclo(tipoActual) {
  if (tipoActual === 'laborable_presencial') return 'home_office';
  if (tipoActual === 'home_office') return 'descanso';
  return 'laborable_presencial';
}

export default function CalendarioInicialColaborador({ dias, horario, onCambiarDia, onBulkSet, onRestablecer }) {
  if (!dias.length) return null;

  const mesLabel = dayjs(dias[0].fecha).format('MMMM YYYY');
  const offsetPrimerDia = (dayjs(dias[0].fecha).day() + 6) % 7;

  return (
    <div>
      <div className="mb-3 flex items-start gap-2 rounded-lg bg-agento-blue-light p-3 text-sm text-agento-blue-dark">
        <InfoCircleOutlined className="mt-0.5" />
        <p>
          Configure el calendario inicial de {mesLabel}. Los días anteriores al ingreso están
          deshabilitados. Haga clic en un día para cambiar su tipo. Los feriados se aplican
          automáticamente.
        </p>
      </div>

      {horario && (
        <p className="mb-3 flex items-center gap-1.5 text-sm text-gray-600">
          <ClockCircleOutlined />
          Base según horario: {horario.jornada ?? '—'}
          {horario.refrigerio ? ` | refrigerio ${horario.refrigerio}` : ''}
          {horario.horas_dia ? ` | ${horario.horas_dia} h` : ''}
        </p>
      )}

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Button size="small" onClick={() => onBulkSet('laborable_presencial')}>
          Todos: Laborable presencial
        </Button>
        <Button size="small" onClick={() => onBulkSet('home_office')}>
          Todos: Home office
        </Button>
        <Button size="small" onClick={() => onBulkSet('descanso')}>
          Todos: Descanso
        </Button>
        <Button size="small" type="link" onClick={onRestablecer}>
          Restablecer
        </Button>
      </div>

      <div className="rounded-2xl border border-gray-100 p-4">
        <div className="mb-2 grid grid-cols-7 gap-2">
          {DIAS_SEMANA_LABELS.map((d) => (
            <div key={d} className="text-center text-xs font-medium text-gray-500">
              {d}
            </div>
          ))}
        </div>
        <div className="grid grid-cols-7 gap-2">
          {Array.from({ length: offsetPrimerDia }).map((_, i) => (
            // eslint-disable-next-line react/no-array-index-key
            <div key={`vacio-${i}`} />
          ))}
          {dias.map((dia) => {
            const estilo = TIPOS[dia.tipo];
            const clicable = dia.editable && dia.tipo !== 'feriado';
            const sinDeclarar = dia.declarado === false;

            return (
              <button
                type="button"
                key={dia.fecha}
                disabled={!clicable}
                onClick={() => onCambiarDia(dia.fecha)}
                title={sinDeclarar ? 'Sin declarar todavía — haz clic para asignarle un tipo' : estilo.label}
                className={`flex flex-col items-center justify-center gap-0.5 rounded-lg border py-2 text-xs font-medium transition-colors ${
                  dia.editable
                    ? `${estilo.bg} ${estilo.text} ${estilo.border}`
                    : 'border-gray-100 bg-gray-50 text-gray-300'
                } ${sinDeclarar ? 'border-dashed' : ''} ${clicable ? 'cursor-pointer hover:opacity-80' : 'cursor-not-allowed'}`}
              >
                <span className="text-sm font-semibold">{dayjs(dia.fecha).date()}</span>
                {dia.editable && dia.tipo === 'feriado' && <span className="text-[10px]">Feriado</span>}
                {sinDeclarar && <span className="text-[9px] italic">sin declarar</span>}
              </button>
            );
          })}
        </div>
      </div>

      <div className="mt-3 flex flex-wrap gap-4 text-xs text-gray-600">
        {Object.entries(TIPOS).map(([key, val]) => (
          <span key={key} className="flex items-center gap-1.5">
            <span className={`h-3 w-3 rounded border ${val.bg} ${val.border}`} />
            {val.label}
          </span>
        ))}
      </div>
    </div>
  );
}
