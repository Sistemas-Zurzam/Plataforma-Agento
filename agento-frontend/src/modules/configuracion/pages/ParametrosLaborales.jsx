import { EditOutlined, EyeOutlined, ReloadOutlined } from '@ant-design/icons';
import { App, Button, Spin, Tag } from 'antd';
import { useEffect, useState } from 'react';
import { formatParametroValor } from '../../../utils/formatNumber';
import EditarParametrosModal from '../components/EditarParametrosModal';
import VerParametrosModal from '../components/VerParametrosModal';
import { useParametrosLaborales } from '../hooks/useParametrosLaborales';

const CLAVES_RESUMEN = ['rmv', 'uit', 'vacaciones_dias', 'essalud_porcentaje'];

export default function ParametrosLaborales({ user }) {
  const { regimenes, loading, fetchParametros, guardarValores, inicializarPorDefecto } =
    useParametrosLaborales();
  const { message, modal } = App.useApp();
  const [viendo, setViendo] = useState(null);
  const [editando, setEditando] = useState(null);
  const [guardando, setGuardando] = useState(false);
  const [inicializando, setInicializando] = useState(false);

  const puedeEditar = user?.permisos?.includes('parametros_laborales.editar');

  useEffect(() => {
    fetchParametros();
  }, [fetchParametros]);

  const handleGuardar = async (regimen, vigenciaDesde, valoresPorDefinicion) => {
    setGuardando(true);
    try {
      await guardarValores(regimen, vigenciaDesde, valoresPorDefinicion);
      setEditando(null);
      message.success('Parámetros actualizados correctamente');
    } catch {
      message.error('No se pudieron guardar los cambios');
    } finally {
      setGuardando(false);
    }
  };

  const handleInicializar = () => {
    modal.confirm({
      title: 'Inicializar valores por defecto',
      content:
        'Se completarán únicamente los parámetros que todavía no tengan ningún valor registrado. Los valores ya definidos no se modifican.',
      okText: 'Inicializar',
      cancelText: 'Cancelar',
      onOk: async () => {
        setInicializando(true);
        try {
          await inicializarPorDefecto();
          message.success('Valores por defecto aplicados');
        } catch {
          message.error('No se pudo inicializar los valores');
        } finally {
          setInicializando(false);
        }
      },
    });
  };

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Parámetros Laborales</h2>
          <p className="text-sm text-gray-500">
            Valores de referencia por régimen laboral, usados en los cálculos de nómina.
          </p>
        </div>
        {puedeEditar && (
          <Button icon={<ReloadOutlined />} loading={inicializando} onClick={handleInicializar}>
            Inicializar valores por defecto
          </Button>
        )}
      </div>

      <Spin spinning={loading}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {regimenes.map((regimen) => {
            const resumen = regimen.parametros.filter((p) => CLAVES_RESUMEN.includes(p.clave));

            return (
              <div
                key={regimen.regimen}
                className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"
              >
                <div className="mb-3 flex items-start justify-between gap-2">
                  <h3 className="font-medium text-gray-900">{regimen.regimen}</h3>
                  <div className="flex shrink-0 items-center gap-1">
                    <Button
                      type="text"
                      size="small"
                      icon={<EyeOutlined />}
                      aria-label={`Ver parámetros de ${regimen.regimen}`}
                      onClick={() => setViendo(regimen)}
                    />
                    {puedeEditar && (
                      <Button
                        type="text"
                        size="small"
                        icon={<EditOutlined />}
                        aria-label={`Editar parámetros de ${regimen.regimen}`}
                        onClick={() => setEditando(regimen)}
                      />
                    )}
                    <Tag color={regimen.completo ? 'green' : 'default'}>
                      {regimen.completo ? 'Completo' : 'Incompleto'}
                    </Tag>
                  </div>
                </div>

                <div className="flex flex-col gap-3">
                  {resumen.map((parametro) => (
                    <div
                      key={parametro.definicion_id}
                      className="flex items-start justify-between gap-2 border-t border-gray-50 pt-3 first:border-t-0 first:pt-0"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-xs text-gray-500">{parametro.nombre}</p>
                        <p className="text-base font-semibold text-gray-900">
                          {formatParametroValor(parametro.valor, parametro.unidad)}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </Spin>

      <VerParametrosModal open={!!viendo} regimen={viendo} onCancel={() => setViendo(null)} />

      <EditarParametrosModal
        open={!!editando}
        regimen={editando}
        onSubmit={handleGuardar}
        onCancel={() => setEditando(null)}
        submitting={guardando}
      />
    </div>
  );
}
