import { Drawer, Empty, List, Tag } from 'antd';
import { useEffect, useState } from 'react';

export default function HistorialCodigoPlameDrawer({ open, concepto, fetchHistorial, onClose }) {
  const [historial, setHistorial] = useState([]);
  const [cargando, setCargando] = useState(false);

  useEffect(() => {
    if (!open || !concepto) return;
    setCargando(true);
    fetchHistorial(concepto.id)
      .then(setHistorial)
      .finally(() => setCargando(false));
  }, [open, concepto, fetchHistorial]);

  return (
    <Drawer title={`Historial de código PLAME — ${concepto?.nombre ?? ''}`} open={open} onClose={onClose} width={420}>
      <List
        loading={cargando}
        dataSource={historial}
        locale={{ emptyText: <Empty description="Sin historial" /> }}
        renderItem={(item, index) => (
          <List.Item>
            <div className="w-full">
              <div className="flex items-center justify-between">
                <span className="font-medium text-gray-900">
                  {item.codigo_plame ? <code>{item.codigo_plame}</code> : <span className="text-gray-400">Sin código</span>}
                </span>
                {index === 0 && <Tag color="green">Vigente</Tag>}
              </div>
              {item.descripcion_sunat && <p className="text-xs text-gray-500">{item.descripcion_sunat}</p>}
              <p className="text-xs text-gray-400">
                Vigente desde {item.vigencia_desde} · {item.actualizado_por ?? 'Sistema'} · {item.creado_en}
              </p>
            </div>
          </List.Item>
        )}
      />
    </Drawer>
  );
}
