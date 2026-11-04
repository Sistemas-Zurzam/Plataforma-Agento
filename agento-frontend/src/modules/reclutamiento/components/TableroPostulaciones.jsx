import { Badge, Dropdown, Tag } from 'antd';
import { ETAPAS, ETIQUETA_ETAPA, RESULTADOS_ATS } from '../constants/seleccion';

/**
 * Tablero por etapas. Mover una tarjeta se hace con su menú (accesible por
 * teclado) en lugar de arrastrar; el backend valida cada transición.
 */
export default function TableroPostulaciones({ columnas, onAbrir, onMover }) {
  return (
    <div className="flex gap-3 overflow-x-auto pb-2" role="list" aria-label="Tablero de candidatos por etapa">
      {columnas.map((columna) => (
        <section key={columna.etapa} className="flex w-64 shrink-0 flex-col rounded-2xl bg-gray-100 p-2" aria-label={ETIQUETA_ETAPA[columna.etapa]}>
          <header className="mb-2 flex items-center justify-between px-1">
            <span className="text-sm font-semibold text-gray-700">{ETIQUETA_ETAPA[columna.etapa]}</span>
            <Badge count={columna.total} showZero color="var(--color-agento-blue)" />
          </header>
          <div className="space-y-2">
            {columna.postulaciones.length === 0 && <p className="px-1 py-4 text-center text-xs text-gray-400">Sin candidatos</p>}
            {columna.postulaciones.map((p) => (
              <div key={p.id} className="rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                <button
                  type="button"
                  className="w-full cursor-pointer text-left focus:outline-2 focus:outline-agento-blue-bright"
                  onClick={() => onAbrir(p.id)}
                >
                  <p className="truncate text-sm font-medium text-gray-900">{p.candidato?.nombre}</p>
                  <p className="text-xs text-gray-500">
                    {p.dias_en_etapa ?? 0} día(s) en {ETIQUETA_ETAPA[p.etapa]}
                  </p>
                  <p className="mt-1 text-xs text-agento-blue">Siguiente: {p.siguiente_accion}</p>
                </button>
                <div className="mt-2 flex items-center justify-between gap-1">
                  <div className="flex flex-wrap gap-1">
                    {p.estado === 'seleccionada' && <Tag color="green">Seleccionada</Tag>}
                    {p.ats && <Tag color={RESULTADOS_ATS[p.ats.resultado]?.color}>ATS {p.ats.puntaje ?? '—'}</Tag>}
                    {p.puntaje_final?.puntaje != null && (
                      <Tag>{p.puntaje_final.provisional ? 'Prov.' : 'Final'} {p.puntaje_final.puntaje}</Tag>
                    )}
                  </div>
                  {p.estado === 'activa' && (
                    <Dropdown
                      trigger={['click']}
                      menu={{
                        items: ETAPAS.filter((e) => e.value !== p.etapa).map((e) => ({ key: e.value, label: `Mover a ${e.label}` })),
                        onClick: ({ key }) => onMover(p, key),
                      }}
                    >
                      <button type="button" className="rounded px-2 text-xs text-gray-600 hover:bg-gray-100" aria-label={`Mover a ${p.candidato?.nombre}`}>
                        Mover ▾
                      </button>
                    </Dropdown>
                  )}
                </div>
              </div>
            ))}
            {columna.total > columna.postulaciones.length && (
              <p className="px-1 text-xs text-gray-500">+{columna.total - columna.postulaciones.length} más (ver en tabla)</p>
            )}
          </div>
        </section>
      ))}
    </div>
  );
}
