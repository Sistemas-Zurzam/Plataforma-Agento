import { BrowserMultiFormatReader } from '@zxing/browser';
import { LogoutOutlined, SearchOutlined, ScanOutlined } from '@ant-design/icons';
import { Alert, Button, Modal, Spin } from 'antd';
import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../../../services/api';
import { colorForName, initialsForName } from '../../../utils/avatarColor';

const DURACION_RESULTADO_MS = 3000;
const RETRASO_BUSQUEDA_MS = 350;

/** Claves de `errors` que puede devolver el backend (ver
 * RegistrarMarcacionCarnetService) — el frontend distingue el caso por la
 * clave del mensaje de validación, no parseando el texto. */
const MENSAJE_POR_CLAVE_ERROR = {
  credencial: 'Carnet no reconocido. Solicite al colaborador comunicarse con RR.HH.',
  colaborador: 'Colaborador inactivo.',
  fecha_desde: 'El período de esta fecha ya está cerrado.',
};

const ESTILO_RESULTADO = {
  entrada_registrada: { color: '#16a34a', icono: '✓', titulo: 'ENTRADA REGISTRADA' },
  salida_registrada: { color: '#16a34a', icono: '✓', titulo: 'SALIDA REGISTRADA' },
  marcacion_registrada: { color: '#16a34a', icono: '✓', titulo: 'MARCACIÓN REGISTRADA' },
  marcacion_duplicada: { color: '#ca8a04', icono: '⚠', titulo: 'MARCACIÓN YA REGISTRADA' },
  error: { color: '#dc2626', icono: '✕', titulo: 'CARNET NO VÁLIDO' },
};

/**
 * Kiosco de Control de Acceso — pantalla completa, sin sidebar (montada
 * directo en App.jsx cuando la ruta es /control-acceso, antes de AppLayout).
 * Flujo principal: ESCANEAR → VER RESULTADO → SIGUIENTE PERSONA, sin ningún
 * botón Entrada/Salida — el backend decide todo (ver Decisión 2 del diseño).
 *
 * Flujo alterno "olvidó su carnet": BUSCAR POR NOMBRE → CONFIRMAR VIENDO LA
 * FOTO → REGISTRAR. Deliberadamente NO acepta el DNI como código en el
 * input de escaneo (ver EscanearCarnetRequest) porque eso permitiría que
 * cualquiera que supiera el DNI de un colaborador marque por él sin el
 * carnet físico; este flujo alterno exige en cambio que una persona
 * (el vigilante) reconozca visualmente al colaborador antes de registrar —
 * el origen queda guardado como 'manual_vigilancia', distinto de un escaneo
 * real, para que quede trazable en auditoría y reportes.
 */
export default function ControlAcceso({ onLogout }) {
  const [codigo, setCodigo] = useState('');
  const [procesando, setProcesando] = useState(false);
  const [resultado, setResultado] = useState(null);
  const [reloj, setReloj] = useState(() => new Date());
  const [ultimas, setUltimas] = useState([]);
  const [vista, setVista] = useState('escanear'); // 'escanear' | 'buscar' | 'confirmar'
  const [scannerAbierto, setScannerAbierto] = useState(false);
  const [errorScanner, setErrorScanner] = useState('');
  const [terminoBusqueda, setTerminoBusqueda] = useState('');
  const [buscando, setBuscando] = useState(false);
  const [resultadosBusqueda, setResultadosBusqueda] = useState([]);
  const [seleccionado, setSeleccionado] = useState(null);
  const [fotoSeleccionado, setFotoSeleccionado] = useState(null);
  const [fotoResultado, setFotoResultado] = useState(null);
  const inputRef = useRef(null);
  const bloqueoRef = useRef(false);
  const videoRef = useRef(null);
  const lectorRef = useRef(null);
  const controlesScannerRef = useRef(null);
  const procesarCodigoEscaneadoRef = useRef(null);

  const enfocar = useCallback(() => inputRef.current?.focus(), []);

  useEffect(() => {
    const intervalo = setInterval(() => setReloj(new Date()), 1000);
    return () => clearInterval(intervalo);
  }, []);

  useEffect(() => {
    if (vista === 'escanear') enfocar();
  }, [vista, enfocar]);

  const detenerScanner = useCallback(() => {
    controlesScannerRef.current?.stop();
    controlesScannerRef.current = null;
    lectorRef.current?.reset();
    if (videoRef.current?.srcObject) {
      videoRef.current.srcObject.getTracks().forEach((pista) => pista.stop());
      videoRef.current.srcObject = null;
    }
  }, []);

  useEffect(() => {
    if (!scannerAbierto || !videoRef.current) return undefined;
    let cancelado = false;
    const lector = new BrowserMultiFormatReader();
    lectorRef.current = lector;
    setErrorScanner('');

    lector.decodeFromVideoDevice(undefined, videoRef.current, (resultado, error, controles) => {
      if (cancelado) {
        controles.stop();
        return;
      }
      controlesScannerRef.current = controles;
      if (resultado) {
        const valor = resultado.getText().trim();
        if (!valor) return;
        cancelado = true;
        controles.stop();
        setScannerAbierto(false);
        setCodigo(valor);
        procesarCodigoEscaneadoRef.current?.(valor);
      } else if (error && error.name !== 'NotFoundException' && error.name !== 'ChecksumException' && error.name !== 'FormatException') {
        setErrorScanner('No se pudo iniciar la cámara. Verifique los permisos del navegador y vuelva a intentarlo.');
      }
    }).catch(() => {
      if (!cancelado) setErrorScanner('No se pudo acceder a la cámara. Use el sitio con HTTPS y permita el acceso a la cámara.');
    });

    return () => {
      cancelado = true;
      detenerScanner();
    };
  }, [scannerAbierto, detenerScanner]);

  useEffect(() => {
    if (!resultado) return undefined;
    const temporizador = setTimeout(() => {
      setResultado(null);
      enfocar();
    }, DURACION_RESULTADO_MS);
    return () => clearTimeout(temporizador);
  }, [resultado, enfocar]);

  useEffect(() => () => { if (fotoResultado) URL.revokeObjectURL(fotoResultado); }, [fotoResultado]);

  // Búsqueda con debounce — dispara a los RETRASO_BUSQUEDA_MS del último
  // tecleo, no en cada tecla, para no saturar el backend en cada letra. Con
  // menos de 2 caracteres simplemente no dispara nada — el JSX oculta la
  // lista mirando `terminoBusqueda` directamente, no hace falta limpiar
  // `resultadosBusqueda` acá (evita un setState síncrono al inicio del
  // efecto sin ganar nada, ya que igual queda oculta en el render).
  useEffect(() => {
    if (vista !== 'buscar' || terminoBusqueda.trim().length < 2) return undefined;
    const termino = terminoBusqueda.trim();

    let cancelado = false;
    const temporizador = setTimeout(() => {
      setBuscando(true);
      api.get('/control-acceso/colaboradores', { params: { buscar: termino } })
        .then(({ data }) => { if (!cancelado) setResultadosBusqueda(data.data); })
        .catch(() => { if (!cancelado) setResultadosBusqueda([]); })
        .finally(() => { if (!cancelado) setBuscando(false); });
    }, RETRASO_BUSQUEDA_MS);

    return () => {
      cancelado = true;
      clearTimeout(temporizador);
    };
  }, [terminoBusqueda, vista]);

  const volverAlInicio = () => {
    setVista(window.matchMedia('(max-width: 640px)').matches ? 'buscar' : 'escanear');
    setTerminoBusqueda('');
    setResultadosBusqueda([]);
    setSeleccionado(null);
    if (fotoSeleccionado) URL.revokeObjectURL(fotoSeleccionado);
    setFotoSeleccionado(null);
  };

  const seleccionarParaConfirmar = (persona) => {
    setSeleccionado(persona);
    setFotoSeleccionado(null);
    setVista('confirmar');
    api.get(`/control-acceso/colaboradores/${persona.id}/foto`, { responseType: 'blob' })
      .then((respuesta) => setFotoSeleccionado(URL.createObjectURL(respuesta.data)))
      .catch(() => {});
  };

  const cancelarConfirmacion = () => {
    setVista('buscar');
    setSeleccionado(null);
    if (fotoSeleccionado) URL.revokeObjectURL(fotoSeleccionado);
    setFotoSeleccionado(null);
  };

  /**
   * Igual que seleccionarParaConfirmar() del flujo manual: mismo endpoint
   * de foto, con vigilancia (permiso control_acceso.marcar, sin
   * colaboradores.ver). Confirmación visual real DESPUÉS de un escaneo
   * exitoso, no solo el nombre — la razón de seguridad es la misma que ya
   * se discutió para el código de barras: nadie debería poder marcar por
   * otra persona sin que quien vigila lo note.
   */
  const cargarFotoResultado = (colaboradorId) => {
    if (!colaboradorId) return;
    api.get(`/control-acceso/colaboradores/${colaboradorId}/foto`, { responseType: 'blob' })
      .then((respuesta) => setFotoResultado(URL.createObjectURL(respuesta.data)))
      .catch(() => {});
  };

  const escanear = async (codigoLeido) => {
    if (bloqueoRef.current) return;
    bloqueoRef.current = true;
    setProcesando(true);
    setResultado(null);
    setFotoResultado(null);

    try {
      const { data } = await api.post('/control-acceso/escanear', { codigo: codigoLeido });
      const info = data.data;
      setResultado(info);
      cargarFotoResultado(info.colaborador?.id);
      if (info.resultado !== 'marcacion_duplicada') {
        setUltimas((anteriores) => [{ ...info, id: `${Date.now()}` }, ...anteriores].slice(0, 5));
      }
    } catch (error) {
      const errores = error.response?.data?.errors ?? {};
      const clave = Object.keys(errores)[0];
      setResultado({
        resultado: 'error',
        mensaje: MENSAJE_POR_CLAVE_ERROR[clave] ?? errores[clave]?.[0] ?? 'No se pudo registrar la marcación.',
      });
    } finally {
      setProcesando(false);
      bloqueoRef.current = false;
      setCodigo('');
      enfocar();
    }
  };
  useEffect(() => {
    procesarCodigoEscaneadoRef.current = escanear;
  }, [escanear]);

  const confirmarYRegistrarManual = async () => {
    if (bloqueoRef.current || !seleccionado) return;
    bloqueoRef.current = true;
    setProcesando(true);

    try {
      const { data } = await api.post(`/control-acceso/colaboradores/${seleccionado.id}/marcar-manual`);
      const info = data.data;
      setVista(window.matchMedia('(max-width: 640px)').matches ? 'buscar' : 'escanear');
      setSeleccionado(null);
      if (fotoSeleccionado) URL.revokeObjectURL(fotoSeleccionado);
      setFotoSeleccionado(null);
      setTerminoBusqueda('');
      setResultadosBusqueda([]);
      setResultado(info);
      cargarFotoResultado(info.colaborador?.id);
      if (info.resultado !== 'marcacion_duplicada') {
        setUltimas((anteriores) => [{ ...info, id: `${Date.now()}` }, ...anteriores].slice(0, 5));
      }
    } catch (error) {
      const errores = error.response?.data?.errors ?? {};
      const clave = Object.keys(errores)[0];
      setVista(window.matchMedia('(max-width: 640px)').matches ? 'buscar' : 'escanear');
      setResultado({
        resultado: 'error',
        mensaje: MENSAJE_POR_CLAVE_ERROR[clave] ?? errores[clave]?.[0] ?? 'No se pudo registrar la marcación.',
      });
    } finally {
      setProcesando(false);
      bloqueoRef.current = false;
    }
  };

  const manejarSubmit = (evento) => {
    evento.preventDefault();
    const valor = codigo.trim();
    if (!valor || procesando) return;
    escanear(valor);
  };

  const estilo = ESTILO_RESULTADO[resultado?.resultado] ?? ESTILO_RESULTADO.error;

  return (
    <div className="flex min-h-svh min-w-0 flex-col bg-slate-900 text-white">
      <header className="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-8 sm:py-5">
        <h1 className="text-lg font-bold tracking-wide sm:text-xl">CONTROL DE ACCESO</h1>
        <div className="flex items-center gap-3 sm:gap-6">
          <span className="font-mono text-base tabular-nums sm:text-2xl">{reloj.toLocaleTimeString('es-PE')}</span>
          <Button ghost icon={<LogoutOutlined />} onClick={onLogout}>Cerrar sesión</Button>
        </div>
      </header>

      <main className="flex w-full flex-1 flex-col items-center justify-start gap-6 px-4 py-8 sm:justify-center sm:px-6">
        {!resultado && vista === 'escanear' && (
          <>
            <p className="text-center text-xl text-slate-300 sm:text-2xl">Escanee el carnet del colaborador</p>
            <Button type="primary" size="large" icon={<ScanOutlined />} disabled={procesando} onClick={() => setScannerAbierto(true)}>
              Escanear con cámara
            </Button>
            <details className="w-full max-w-[420px] text-center">
              <summary className="cursor-pointer text-sm text-slate-400 underline decoration-dotted underline-offset-4">Usar lector conectado o ingresar código</summary>
              <form onSubmit={manejarSubmit} className="mt-3">
                <input
                  ref={inputRef}
                  value={codigo}
                  onChange={(evento) => setCodigo(evento.target.value)}
                  disabled={procesando}
                  autoComplete="off"
                  className="w-full rounded-xl border-2 border-slate-600 bg-slate-800 px-6 py-4 text-center text-2xl tracking-widest text-white outline-none focus:border-blue-400"
                  placeholder={procesando ? 'Procesando…' : 'Código del carnet'}
                />
                <Button htmlType="submit" className="mt-3" disabled={!codigo.trim() || procesando}>Registrar código</Button>
              </form>
            </details>
            <button
              type="button"
              onClick={() => setVista('buscar')}
              className="text-sm text-slate-400 underline decoration-dotted underline-offset-4 hover:text-slate-200"
            >
              ¿Olvidó su carnet? Buscar colaborador
            </button>
          </>
        )}

        {!resultado && vista === 'buscar' && (
          <div className="flex w-full max-w-[480px] min-w-0 flex-col gap-4">
            <p className="text-center text-xl text-slate-300">Busque al colaborador por nombre o documento</p>
            <div className="relative">
              <SearchOutlined className="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-slate-400" />
              <input
                value={terminoBusqueda}
                onChange={(evento) => setTerminoBusqueda(evento.target.value)}
                autoFocus={window.matchMedia('(min-width: 641px)').matches}
                autoComplete="off"
                placeholder="Nombre o documento…"
                className="w-full rounded-xl border-2 border-slate-600 bg-slate-800 py-3 pr-4 pl-11 text-lg text-white outline-none focus:border-blue-400"
              />
            </div>

            <div className="flex max-h-[50svh] flex-col gap-2 overflow-y-auto sm:max-h-90">
              {buscando && (
                <div className="flex justify-center py-4"><Spin /></div>
              )}
              {!buscando && terminoBusqueda.trim().length >= 2 && resultadosBusqueda.length === 0 && (
                <p className="py-2 text-center text-slate-400">Sin resultados.</p>
              )}
              {!buscando && terminoBusqueda.trim().length >= 2 && resultadosBusqueda.map((persona) => (
                <button
                  key={persona.id}
                  type="button"
                  onClick={() => seleccionarParaConfirmar(persona)}
                  className="flex min-h-16 items-center gap-3 rounded-lg bg-slate-800 p-3 text-left hover:bg-slate-700"
                >
                  <span
                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                    style={{ backgroundColor: colorForName(persona.nombre_mostrable) }}
                  >
                    {initialsForName(persona.nombre_mostrable)}
                  </span>
                  <span>
                    <span className="block font-semibold">{persona.nombre_mostrable}</span>
                    <span className="block text-sm text-slate-400">{persona.cargo ?? 'Colaborador'}</span>
                  </span>
                </button>
              ))}
            </div>

            <Button ghost onClick={volverAlInicio}>Volver al escáner</Button>
          </div>
        )}

        {!resultado && vista === 'confirmar' && seleccionado && (
          <div className="flex w-full max-w-[480px] flex-col items-center gap-4 text-center">
            <p className="text-xl text-slate-300">¿Es esta persona?</p>
            {fotoSeleccionado ? (
              <img
                src={fotoSeleccionado}
                alt={seleccionado.nombre_mostrable}
                className="h-48 w-48 rounded-full border-4 border-slate-600 object-cover"
              />
            ) : (
              <span
                className="flex h-48 w-48 items-center justify-center rounded-full border-4 border-slate-600 text-5xl font-bold text-white"
                style={{ backgroundColor: colorForName(seleccionado.nombre_mostrable) }}
              >
                {initialsForName(seleccionado.nombre_mostrable)}
              </span>
            )}
            <p className="break-words text-2xl font-bold sm:text-3xl">{seleccionado.nombre_mostrable}</p>
            <p className="text-lg text-slate-400">{seleccionado.cargo ?? 'Colaborador'}</p>
            <div className="mt-2 flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:gap-4">
              <Button size="large" ghost disabled={procesando} onClick={cancelarConfirmacion}>No es esta persona</Button>
              <Button size="large" type="primary" loading={procesando} onClick={confirmarYRegistrarManual}>
                Sí, registrar
              </Button>
            </div>
          </div>
        )}

        {resultado && (
          <div className="flex w-full max-w-[480px] flex-col items-center gap-4 text-center">
            {resultado.colaborador?.id && (
              fotoResultado ? (
                <img
                  src={fotoResultado}
                  alt={resultado.colaborador.nombre_mostrable}
                  className="h-40 w-40 rounded-lg border-4 object-cover"
                  style={{ borderColor: estilo.color }}
                />
              ) : (
                <span
                  className="flex h-40 w-40 items-center justify-center rounded-lg border-4 text-3xl font-bold text-white"
                  style={{ borderColor: estilo.color, backgroundColor: colorForName(resultado.colaborador.nombre_mostrable) }}
                >
                  {initialsForName(resultado.colaborador.nombre_mostrable)}
                </span>
              )
            )}
            <span className="text-6xl" style={{ color: estilo.color }}>{estilo.icono}</span>
            <p className="text-2xl font-extrabold sm:text-3xl" style={{ color: estilo.color }}>{estilo.titulo}</p>
            {resultado.colaborador?.nombre_mostrable && (
              <p className="break-words text-2xl font-bold sm:text-4xl">{resultado.colaborador.nombre_mostrable}</p>
            )}
            {resultado.hora && <p className="text-2xl text-slate-300">{resultado.hora}</p>}
            <p className="text-xl text-slate-400">{resultado.mensaje}</p>
          </div>
        )}
      </main>

      <Modal
        title="Escanear código de barras"
        open={scannerAbierto}
        onCancel={() => setScannerAbierto(false)}
        footer={<Button onClick={() => setScannerAbierto(false)}>Cerrar cámara</Button>}
        destroyOnHidden
        centered
        width={520}
      >
        <p className="mb-3 text-slate-600">Apunte la cámara al código del carnet.</p>
        {errorScanner && <Alert className="mb-3" type="error" showIcon message={errorScanner} />}
        <div className="overflow-hidden rounded-xl bg-black">
          <video ref={videoRef} className="aspect-video w-full object-cover" muted playsInline />
        </div>
      </Modal>

      {ultimas.length > 0 && (
        <footer className="border-t border-slate-700 px-4 py-4 sm:px-8">
          <p className="mb-2 text-sm text-slate-400">Últimas marcaciones</p>
          <div className="flex flex-wrap gap-4 text-sm text-slate-300">
            {ultimas.map((item) => (
              <span key={item.id}>{item.hora} · {item.colaborador?.nombre_mostrable} — registrada</span>
            ))}
          </div>
        </footer>
      )}
    </div>
  );
}
