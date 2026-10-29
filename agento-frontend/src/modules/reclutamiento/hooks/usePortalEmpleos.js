import { useCallback, useState } from 'react';
import { portalEmpleosApi, sesionCandidato } from '../services/portalEmpleosApi';
import { useConsulta } from './useConsulta';

export function useEmpleos(filtros) {
  const cargar = useCallback(() => portalEmpleosApi.listar(filtros), [filtros]);
  return useConsulta(cargar);
}

export function useEmpleo(codigo, invitacion) {
  const cargar = useCallback(() => portalEmpleosApi.obtener(codigo, invitacion), [codigo, invitacion]);
  const consulta = useConsulta(cargar);
  return { ...consulta, postular: portalEmpleosApi.postular };
}

/**
 * Sesión del candidato (correo verificado con código temporal).
 */
export function useAccesoCandidato() {
  const [token, setToken] = useState(() => sesionCandidato.obtener());

  const solicitarCodigo = useCallback((email) => portalEmpleosApi.solicitarCodigo(email), []);

  const verificarCodigo = useCallback(async (email, codigo) => {
    const resultado = await portalEmpleosApi.verificarCodigo(email, codigo);
    sesionCandidato.guardar(resultado.token);
    setToken(resultado.token);
    return resultado;
  }, []);

  const cerrarSesion = useCallback(async () => {
    try {
      await portalEmpleosApi.cerrarSesion();
    } finally {
      sesionCandidato.limpiar();
      setToken(null);
    }
  }, []);

  const expirar = useCallback(() => {
    sesionCandidato.limpiar();
    setToken(null);
  }, []);

  return { token, autenticado: Boolean(token), solicitarCodigo, verificarCodigo, cerrarSesion, expirar };
}

export function usePerfilCandidato(habilitado) {
  const cargar = useCallback(() => portalEmpleosApi.perfil(), []);
  return useConsulta(cargar, { inmediata: habilitado });
}

export function useMisPostulaciones(habilitado) {
  const cargar = useCallback(() => portalEmpleosApi.misPostulaciones(), []);
  const consulta = useConsulta(cargar, { inmediata: habilitado });
  return { ...consulta, retirar: portalEmpleosApi.retirar, accesoPrueba: portalEmpleosApi.accesoPrueba };
}

export function usePruebaCandidato(token) {
  // recibido_ms permite descontar el tiempo transcurrido desde la respuesta
  // del servidor sin depender del reloj del dispositivo para el vencimiento.
  const marcar = (datos) => ({ ...datos, recibido_ms: Date.now() });
  const cargar = useCallback(() => portalEmpleosApi.prueba(token).then(marcar), [token]);
  const consulta = useConsulta(cargar);
  const iniciar = useCallback(() => portalEmpleosApi.iniciarPrueba(token).then(marcar), [token]);
  const responder = useCallback((preguntaId, opcionId) => portalEmpleosApi.responder(token, preguntaId, opcionId), [token]);
  const finalizar = useCallback(() => portalEmpleosApi.finalizarPrueba(token), [token]);
  return { ...consulta, iniciar, responder, finalizar };
}
