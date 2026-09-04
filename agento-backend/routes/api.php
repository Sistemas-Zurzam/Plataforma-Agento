<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Modules\Asistencia\Http\Controllers\HorarioController;
use App\Modules\Asistencia\Http\Controllers\AsistenciaController;
use App\Modules\Asistencia\Http\Controllers\TipoAusenciaController;
use App\Modules\Configuracion\Http\Controllers\AfpController;
use App\Modules\Configuracion\Http\Controllers\AreaController;
use App\Modules\Configuracion\Http\Controllers\BancoController;
use App\Modules\Configuracion\Http\Controllers\ReglaDescuentoTardanzaController;
use App\Modules\Configuracion\Http\Controllers\ComisionAfpController;
use App\Modules\Configuracion\Http\Controllers\EmpresaController;
use App\Modules\Configuracion\Http\Controllers\EmpresaCuentaBancariaController;
use App\Modules\Configuracion\Http\Controllers\ParametroLaboralController;
use App\Modules\Configuracion\Http\Controllers\PermissionController;
use App\Modules\Configuracion\Http\Controllers\RoleController;
use App\Modules\Configuracion\Http\Controllers\SedeController;
use App\Modules\Configuracion\Http\Controllers\UsuarioController;
use App\Modules\Personas\Http\Controllers\ColaboradorController;
use App\Modules\Nominas\Http\Controllers\CicloRemunerativoController;
use App\Modules\Nominas\Http\Controllers\PlanillaComplementariaController;
use App\Modules\Nominas\Http\Controllers\BoletaController;
use App\Modules\Nominas\Http\Controllers\BeneficioSocialController;
use App\Modules\Nominas\Http\Controllers\LiquidacionCeseController;
use App\Modules\Nominas\Http\Controllers\VacacionMovimientoController;
use App\Modules\Nominas\Http\Controllers\ConceptoDefinicionPlameController;
use App\Modules\Nominas\Http\Controllers\ConceptoRemuneracionController;
use App\Modules\Nominas\Http\Controllers\SunatCatalogoController;
use App\Modules\Nominas\Http\Controllers\TramoRentaController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('jwt')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [ProfileController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/empresas', [EmpresaController::class, 'index']);
    Route::post('/empresas', [EmpresaController::class, 'store'])->middleware('permiso:empresas.crear');
    Route::put('/empresas/{empresa}', [EmpresaController::class, 'update'])->middleware('permiso:empresas.editar');
    Route::patch('/empresas/{empresa}/estado', [EmpresaController::class, 'actualizarEstado'])->middleware('permiso:empresas.editar');
    Route::put('/empresas/{empresa}/activar', [EmpresaController::class, 'activar']);
    Route::post('/empresas/{empresa}/logo', [EmpresaController::class, 'guardarLogo'])->middleware('permiso:empresas.editar');

    Route::get('/empresas/{empresa}/sedes', [SedeController::class, 'index']);
    Route::post('/empresas/{empresa}/sedes', [SedeController::class, 'store'])->middleware('permiso:sedes.crear');
    Route::put('/empresas/{empresa}/sedes/{sede}', [SedeController::class, 'update'])->middleware('permiso:sedes.editar');

    Route::get('/empresas/{empresa}/areas', [AreaController::class, 'index']);
    Route::post('/empresas/{empresa}/areas', [AreaController::class, 'store'])->middleware('permiso:areas.crear');
    Route::patch('/empresas/{empresa}/areas/{area}/responsable', [AreaController::class, 'asignarResponsable'])->middleware('permiso:areas.crear');

    Route::get('/empresas/{empresa}/reglas-tardanza', [ReglaDescuentoTardanzaController::class, 'index'])->middleware('permiso:empresas.editar');
    Route::post('/empresas/{empresa}/reglas-tardanza', [ReglaDescuentoTardanzaController::class, 'store'])->middleware('permiso:empresas.editar');
    Route::delete('/empresas/{empresa}/reglas-tardanza/{regla}', [ReglaDescuentoTardanzaController::class, 'destroy'])->middleware('permiso:empresas.editar');

    Route::get('/bancos', [BancoController::class, 'index']);
    Route::get('/empresas/{empresa}/cuentas-bancarias', [EmpresaCuentaBancariaController::class, 'index'])->middleware('permiso:empresas.editar');
    Route::post('/empresas/{empresa}/cuentas-bancarias', [EmpresaCuentaBancariaController::class, 'store'])->middleware('permiso:empresas.editar');
    Route::put('/empresas/{empresa}/cuentas-bancarias/{cuenta}', [EmpresaCuentaBancariaController::class, 'update'])->middleware('permiso:empresas.editar');
    Route::patch('/empresas/{empresa}/cuentas-bancarias/{cuenta}/estado', [EmpresaCuentaBancariaController::class, 'actualizarEstado'])->middleware('permiso:empresas.editar');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permiso:usuarios.ver');
    Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('permiso:usuarios.ver');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->middleware('permiso:usuarios.crear');
    Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->middleware('permiso:usuarios.editar');
    Route::patch('/usuarios/{usuario}/rol', [UsuarioController::class, 'actualizarRol'])->middleware('permiso:usuarios.cambiar_rol');
    Route::patch('/usuarios/{usuario}/estado', [UsuarioController::class, 'actualizarEstado'])->middleware('permiso:usuarios.inactivar');
    Route::delete('/usuarios/{usuario}', [UsuarioController::class, 'destroy'])->middleware('permiso:usuarios.eliminar');

    Route::get('/parametros-laborales', [ParametroLaboralController::class, 'index'])->middleware('permiso:parametros_laborales.ver');
    Route::post('/parametros-laborales', [ParametroLaboralController::class, 'store'])->middleware('permiso:parametros_laborales.editar');
    Route::post('/parametros-laborales/inicializar', [ParametroLaboralController::class, 'inicializar'])->middleware('permiso:parametros_laborales.editar');
    Route::get('/parametros-laborales/{definicion}/historial', [ParametroLaboralController::class, 'historial'])->middleware('permiso:parametros_laborales.ver');

    Route::get('/afps', [AfpController::class, 'index']);
    Route::get('/conceptos-remuneracion', [ConceptoRemuneracionController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::patch('/conceptos-remuneracion/{concepto}/codigo-plame', [ConceptoRemuneracionController::class, 'actualizarCodigoPlame'])->middleware('permiso:parametros_laborales.editar');
    Route::get('/conceptos-remuneracion/{concepto}/codigo-plame/historial', [ConceptoRemuneracionController::class, 'historialCodigoPlame'])->middleware('permiso:parametros_laborales.ver');
    Route::get('/conceptos-remuneracion/{concepto}/definiciones-plame', [ConceptoDefinicionPlameController::class, 'index'])->middleware('permiso:parametros_laborales.ver');
    Route::post('/conceptos-remuneracion/{concepto}/definiciones-plame', [ConceptoDefinicionPlameController::class, 'store'])->middleware('permiso:parametros_laborales.editar');
    Route::put('/concepto-definiciones-plame/{definicion}', [ConceptoDefinicionPlameController::class, 'update'])->middleware('permiso:parametros_laborales.editar');
    Route::get('/tramos-renta', [TramoRentaController::class, 'index'])->middleware('permiso:parametros_laborales.ver');

    // Catálogos SUNAT (Configuraciones → Nóminas) — capa de mapeo Agento →
    // SUNAT, reutiliza los mismos permisos que Parámetros Remunerativos.
    Route::get('/sunat/resumen', [SunatCatalogoController::class, 'resumen'])->middleware('permiso:parametros_laborales.ver');
    Route::get('/sunat/mapeos', [SunatCatalogoController::class, 'mapeos'])->middleware('permiso:parametros_laborales.ver');
    Route::put('/sunat/mapeos/{mapeo}', [SunatCatalogoController::class, 'actualizarMapeo'])->middleware('permiso:parametros_laborales.editar');
    Route::get('/tipos-ausencia', [TipoAusenciaController::class, 'index'])->middleware('permiso:parametros_laborales.ver');
    Route::put('/tipos-ausencia/{tipoAusencia}/codigo-sunat', [TipoAusenciaController::class, 'actualizarCodigoSunat'])->middleware('permiso:parametros_laborales.editar');
    Route::get('/comisiones-afp', [ComisionAfpController::class, 'index'])->middleware('permiso:comisiones_afp.ver');
    Route::post('/comisiones-afp', [ComisionAfpController::class, 'store'])->middleware('permiso:comisiones_afp.editar');
    Route::put('/comisiones-afp/{comision}', [ComisionAfpController::class, 'update'])->middleware('permiso:comisiones_afp.editar');
    Route::delete('/comisiones-afp/{comision}', [ComisionAfpController::class, 'destroy'])->middleware('permiso:comisiones_afp.editar');
    Route::post('/comisiones-afp/cargar-sbs', [ComisionAfpController::class, 'cargarSbs'])->middleware('permiso:comisiones_afp.editar');
    Route::get('/comisiones-afp/{afp}/historial', [ComisionAfpController::class, 'historial'])->middleware('permiso:comisiones_afp.ver');

    Route::get('/horarios', [HorarioController::class, 'index'])->middleware('permiso:horarios.ver');
    Route::post('/horarios', [HorarioController::class, 'store'])->middleware('permiso:horarios.crear');
    Route::put('/horarios/{horario}', [HorarioController::class, 'update'])->middleware('permiso:horarios.editar');
    Route::post('/horarios/{horario}/duplicar', [HorarioController::class, 'duplicar'])->middleware('permiso:horarios.crear');
    Route::patch('/horarios/{horario}/estado', [HorarioController::class, 'cambiarEstado'])->middleware('permiso:horarios.editar');
    Route::get('/horarios/plantilla-importacion', [HorarioController::class, 'plantillaImportacion'])->middleware('permiso:horarios.crear');
    Route::post('/horarios/importar/previsualizar', [HorarioController::class, 'previsualizarImportacion'])->middleware('permiso:horarios.crear');
    Route::post('/horarios/importar', [HorarioController::class, 'importar'])->middleware('permiso:horarios.crear');

    Route::get('/asistencia/resumen', [AsistenciaController::class, 'index'])->middleware('permiso:asistencia.ver');
    Route::get('/asistencia/colaboradores', [AsistenciaController::class, 'colaboradores'])->middleware('permiso:asistencia.ver');
    Route::get('/asistencia/colaboradores/{colaborador}', [AsistenciaController::class, 'colaborador'])->middleware('permiso:asistencia.ver');
    Route::get('/asistencia/permisos', [AsistenciaController::class, 'permisos'])->middleware('permiso:asistencia.ver');
    Route::post('/asistencia/permisos', [AsistenciaController::class, 'guardarPermiso'])->middleware('permiso:asistencia.permisos');
    Route::patch('/asistencia/permisos/{permiso}', [AsistenciaController::class, 'resolverPermiso'])->middleware('permiso:asistencia.permisos');
    Route::get('/asistencia/gestiones-area', [AsistenciaController::class, 'gestionesArea'])->middleware('permiso:asistencia.ver');
    Route::post('/asistencia/gestiones-area', [AsistenciaController::class, 'guardarGestionArea'])->middleware('permiso:asistencia.gestiones_area');
    Route::post('/asistencia/importar', [AsistenciaController::class, 'importarMarcaciones'])->middleware('permiso:asistencia.importar');
    Route::post('/asistencia/importar/previsualizar', [AsistenciaController::class, 'previsualizarImportacion'])->middleware('permiso:asistencia.importar');
    Route::get('/asistencia/marcaciones-no-asociadas', [AsistenciaController::class, 'marcacionesNoAsociadas'])->middleware('permiso:asistencia.ver');
    Route::post('/asistencia/marcaciones-no-asociadas/{colaborador}/asociar', [AsistenciaController::class, 'asociarPersonId'])->middleware('permiso:asistencia.importar');
    Route::post('/asistencia/reprocesar', [AsistenciaController::class, 'reprocesar'])->middleware('permiso:asistencia.procesar');
    Route::get('/asistencia/planificacion', [AsistenciaController::class, 'planificacion'])->middleware('permiso:asistencia.ver');
    Route::put('/asistencia/planificacion', [AsistenciaController::class, 'guardarPlanificacion'])->middleware('permiso:asistencia.incidencias');
    Route::put('/asistencia/planificacion/masivo', [AsistenciaController::class, 'guardarPlanificacionMasivo'])->middleware('permiso:asistencia.incidencias');
    Route::get('/asistencia/incidencias', [AsistenciaController::class, 'incidencias'])->middleware('permiso:asistencia.ver');
    Route::patch('/asistencia/incidencias/{incidencia}', [AsistenciaController::class, 'resolverIncidencia'])->middleware('permiso:asistencia.incidencias');
    Route::patch('/asistencia/incidencias/{incidencia}/permiso', [AsistenciaController::class, 'resolverIncidenciaConPermiso'])->middleware('permiso:asistencia.incidencias');
    Route::patch('/asistencia/incidencias/{incidencia}/clasificar-dia', [AsistenciaController::class, 'clasificarDiaSinRol'])->middleware('permiso:asistencia.incidencias');
    Route::patch('/asistencia/incidencias/{incidencia}/resolver-trabajo-descanso', [AsistenciaController::class, 'resolverTrabajoEnDescanso'])->middleware('permiso:asistencia.incidencias');
    Route::patch('/asistencia/incidencias', [AsistenciaController::class, 'resolverIncidenciasMasivo'])->middleware('permiso:asistencia.incidencias');
    Route::patch('/asistencia/resultados/{resultado}', [AsistenciaController::class, 'editarDia'])->middleware('permiso:asistencia.incidencias');
    Route::get('/asistencia/horas-extra', [AsistenciaController::class, 'horasExtra'])->middleware('permiso:asistencia.ver');
    Route::patch('/asistencia/horas-extra/{horaExtra}', [AsistenciaController::class, 'resolverHoraExtra'])->middleware('permiso:asistencia.horas_extra');
    Route::patch('/asistencia/horas-extra', [AsistenciaController::class, 'resolverHorasExtraMasivo'])->middleware('permiso:asistencia.horas_extra');
    Route::patch('/asistencia/gestiones-area/{solicitud}', [AsistenciaController::class, 'resolverGestionArea'])->middleware('permiso:asistencia.gestiones_area');
    Route::get('/asistencia/importaciones', [AsistenciaController::class, 'importaciones'])->middleware('permiso:asistencia.ver');
    Route::get('/asistencia/periodos', [AsistenciaController::class, 'periodos'])->middleware('permiso:asistencia.ver');
    Route::post('/asistencia/periodos', [AsistenciaController::class, 'guardarPeriodo'])->middleware('permiso:asistencia.periodos');
    Route::patch('/asistencia/periodos/{periodo}', [AsistenciaController::class, 'transicionarPeriodo'])->middleware('permiso:asistencia.periodos');
    Route::get('/asistencia/periodos/{periodo}/estado-cobertura', [AsistenciaController::class, 'estadoCoberturaPeriodo'])->middleware('permiso:asistencia.ver');
    Route::get('/asistencia/auditoria', [AsistenciaController::class, 'auditoria'])->middleware('permiso:asistencia.ver');

    Route::get('/colaboradores', [ColaboradorController::class, 'index'])->middleware('permiso:colaboradores.ver');
    Route::post('/colaboradores', [ColaboradorController::class, 'store'])->middleware('permiso:colaboradores.crear');
    Route::get('/colaboradores/calendario-defecto', [ColaboradorController::class, 'calendarioDefecto'])->middleware('permiso:colaboradores.crear');
    Route::get('/colaboradores/rotativos-sin-rol', [ColaboradorController::class, 'rotativosSinRol'])->middleware('permiso:colaboradores.ver');
    Route::get('/colaboradores/plantilla-importacion', [ColaboradorController::class, 'plantillaImportacion'])->middleware('permiso:colaboradores.crear');
    Route::post('/colaboradores/importar/previsualizar', [ColaboradorController::class, 'previsualizarImportacion'])->middleware('permiso:colaboradores.crear');
    Route::post('/colaboradores/importar', [ColaboradorController::class, 'importarColaboradores'])->middleware('permiso:colaboradores.crear');
    Route::get('/colaboradores/{colaborador}', [ColaboradorController::class, 'show'])->middleware('permiso:colaboradores.ver');
    Route::get('/colaboradores/{colaborador}/calendario', [ColaboradorController::class, 'calendarioDelMes'])->middleware('permiso:colaboradores.editar');
    Route::put('/colaboradores/{colaborador}/calendario', [ColaboradorController::class, 'actualizarCalendario'])->middleware('permiso:colaboradores.editar');
    Route::put('/colaboradores/{colaborador}/horario', [ColaboradorController::class, 'actualizarHorario'])->middleware('permiso:colaboradores.editar');
    Route::put('/colaboradores/{colaborador}/remuneracion', [ColaboradorController::class, 'actualizarRemuneracion'])->middleware('permiso:colaboradores.editar');
    Route::put('/colaboradores/{colaborador}/configuracion-nomina', [ColaboradorController::class, 'actualizarConfiguracionNomina'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::put('/colaboradores/{colaborador}', [ColaboradorController::class, 'update'])->middleware('permiso:colaboradores.editar');
    Route::get('/colaboradores/{colaborador}/liquidacion-cese/previsualizar', [ColaboradorController::class, 'previsualizarLiquidacionCese'])->middleware('permiso:colaboradores.cesar');
    Route::patch('/colaboradores/{colaborador}/cesar', [ColaboradorController::class, 'cesar'])->middleware('permiso:colaboradores.cesar');
    Route::get('/colaboradores/{colaborador}/vacacion-movimientos', [VacacionMovimientoController::class, 'index'])->middleware('permiso:colaboradores.ver');
    Route::post('/colaboradores/{colaborador}/vacacion-movimientos', [VacacionMovimientoController::class, 'store'])->middleware('permiso:colaboradores.editar');
    Route::delete('/colaboradores/{colaborador}/vacacion-movimientos/{movimiento}', [VacacionMovimientoController::class, 'destroy'])->middleware('permiso:colaboradores.editar');
    Route::delete('/colaboradores/{colaborador}', [ColaboradorController::class, 'destroy'])->middleware('permiso:colaboradores.eliminar');
    Route::patch('/colaboradores/{colaborador}/restaurar', [ColaboradorController::class, 'restaurar'])->middleware('empresa.admin');
    Route::post('/colaboradores/{colaborador}/documentos', [ColaboradorController::class, 'guardarDocumento'])->middleware('permiso:colaboradores.editar');
    Route::get('/colaboradores/{colaborador}/documentos/{documento}', [ColaboradorController::class, 'verDocumento'])->middleware('permiso:colaboradores.ver');
    Route::post('/colaboradores/{colaborador}/foto-perfil', [ColaboradorController::class, 'guardarFotoPerfil'])->middleware('permiso:colaboradores.editar');
    Route::get('/colaboradores/{colaborador}/foto-perfil', [ColaboradorController::class, 'verFotoPerfil'])->middleware('permiso:colaboradores.ver');

    Route::get('/ciclos-remunerativos', [CicloRemunerativoController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos-resumen-contable', [CicloRemunerativoController::class, 'resumenContable'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos', [CicloRemunerativoController::class, 'store'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::put('/ciclos-remunerativos/{ciclo}', [CicloRemunerativoController::class, 'actualizar'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::delete('/ciclos-remunerativos/{ciclo}', [CicloRemunerativoController::class, 'eliminar'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::post('/ciclos-remunerativos/{ciclo}/calcular', [CicloRemunerativoController::class, 'calcular'])->middleware('permiso:nominas.calcular');
    Route::get('/ciclos-remunerativos/{ciclo}/estado-calculo', [CicloRemunerativoController::class, 'estadoCalculo'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/incidencias-pendientes-cierre', [CicloRemunerativoController::class, 'incidenciasPendientesCierre'])->middleware('permiso:nominas.cerrar_periodo');
    Route::patch('/ciclos-remunerativos/{ciclo}/incidencias-pendientes-cierre/{incidencia}', [CicloRemunerativoController::class, 'resolverIncidenciaPendienteCierre'])->middleware('permiso:asistencia.incidencias');
    Route::patch('/ciclos-remunerativos/{ciclo}/cerrar', [CicloRemunerativoController::class, 'cerrar'])->middleware('permiso:nominas.cerrar_periodo');
    Route::patch('/ciclos-remunerativos/{ciclo}/reabrir', [CicloRemunerativoController::class, 'reabrir'])->middleware('permiso:nominas.cerrar_periodo');
    Route::patch('/ciclos-remunerativos/{ciclo}/marcar-pagado', [CicloRemunerativoController::class, 'marcarPagado'])->middleware('permiso:nominas.pagar');
    Route::get('/ciclos-remunerativos/{ciclo}/planilla-pagada/excel', [CicloRemunerativoController::class, 'exportarPlanillaPagadaExcel'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/plame-validacion', [CicloRemunerativoController::class, 'validarPlame'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/plame/exportar/planilla', [CicloRemunerativoController::class, 'exportarPlamePlanilla'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/plame/exportar/rh', [CicloRemunerativoController::class, 'exportarPlameRh'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/plame/exportar/completo', [CicloRemunerativoController::class, 'exportarPlameCompleto'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/afpnet-validacion', [CicloRemunerativoController::class, 'validarAfpNet'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/afpnet/exportar/excel', [CicloRemunerativoController::class, 'exportarAfpNetExcel'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/afpnet/exportar/txt', [CicloRemunerativoController::class, 'exportarAfpNetTxt'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/telecredito-bcp/validacion', [CicloRemunerativoController::class, 'validarTelecreditoBcp'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/telecredito-bcp/exportar', [CicloRemunerativoController::class, 'exportarTelecreditoBcp'])->middleware('permiso:nominas.telecredito_exportar');
    Route::post('/ciclos-remunerativos/{ciclo}/bbva-netcash/validacion', [CicloRemunerativoController::class, 'validarBbvaNetCash'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/bbva-netcash/exportar', [CicloRemunerativoController::class, 'exportarBbvaNetCash'])->middleware('permiso:nominas.bbva_netcash_exportar');
    Route::get('/planilla/previsualizar', [BoletaController::class, 'previsualizar'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/boletas', [BoletaController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/boletas-exportables/ids', [BoletaController::class, 'idsExportables'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/resumen', [BoletaController::class, 'resumen'])->middleware('permiso:nominas.ver');
    Route::get('/beneficios-sociales/resumen', [BeneficioSocialController::class, 'resumen'])->middleware('permiso:nominas.ver');
    Route::post('/beneficios-sociales/calcular', [BeneficioSocialController::class, 'calcular'])->middleware('permiso:nominas.calcular');
    Route::patch('/beneficios-sociales/{beneficio}/pagar', [BeneficioSocialController::class, 'marcarPagado'])->middleware('permiso:nominas.pagar');
    Route::get('/liquidaciones-cese', [LiquidacionCeseController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::get('/liquidaciones-cese/{liquidacion}', [LiquidacionCeseController::class, 'show'])->middleware('permiso:nominas.ver');
    Route::patch('/liquidaciones-cese/{liquidacion}/aprobar', [LiquidacionCeseController::class, 'aprobar'])->middleware('permiso:nominas.aprobar');
    Route::patch('/liquidaciones-cese/{liquidacion}/pagar', [LiquidacionCeseController::class, 'pagar'])->middleware('permiso:nominas.pagar');
    Route::patch('/liquidaciones-cese/{liquidacion}/anular-revertir', [LiquidacionCeseController::class, 'anularYRevertir'])->middleware('permiso:nominas.aprobar');
    Route::get('/boletas/{boleta}/incidencias-pendientes-aprobar', [BoletaController::class, 'incidenciasPendientesAprobar'])->middleware('permiso:nominas.aprobar');
    Route::patch('/boletas/{boleta}/aprobar', [BoletaController::class, 'aprobar'])->middleware('permiso:nominas.aprobar');
    Route::post('/ciclos-remunerativos/{ciclo}/boletas/incidencias-pendientes-aprobar-masivo', [BoletaController::class, 'incidenciasPendientesAprobarMasivo'])->middleware('permiso:nominas.aprobar');
    Route::patch('/ciclos-remunerativos/{ciclo}/boletas/aprobar-masivo', [BoletaController::class, 'aprobarMasivo'])->middleware('permiso:nominas.aprobar');
    Route::patch('/boletas/{boleta}/pagar', [BoletaController::class, 'marcarPagada'])->middleware('permiso:nominas.pagar');
    Route::get('/boletas/{boleta}', [BoletaController::class, 'show'])->middleware('permiso:nominas.ver');
    Route::patch('/boletas/{boleta}/comprobante-rh', [BoletaController::class, 'guardarComprobanteRh'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::get('/ciclos-remunerativos/{ciclo}/complementarias', [PlanillaComplementariaController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::get('/ciclos-remunerativos/{ciclo}/complementarias/feriados-historicos', [PlanillaComplementariaController::class, 'feriadosDisponibles'])->middleware('permiso:nominas.calcular');
    Route::post('/ciclos-remunerativos/{ciclo}/complementarias', [PlanillaComplementariaController::class, 'store'])->middleware('permiso:nominas.calcular');
    Route::post('/ciclos-remunerativos/{ciclo}/complementarias/regularizacion-feriado-historico', [PlanillaComplementariaController::class, 'regularizarFeriadoHistorico'])->middleware('permiso:nominas.calcular');
    Route::post('/planillas-complementarias-detalles/{detalle}/conceptos', [PlanillaComplementariaController::class, 'agregarConcepto'])->middleware('permiso:nominas.calcular');
    Route::delete('/planillas-complementarias-detalles/{detalle}/conceptos/{lineaId}', [PlanillaComplementariaController::class, 'eliminarConcepto'])->middleware('permiso:nominas.calcular');
    Route::delete('/planillas-complementarias/{complementaria}', [PlanillaComplementariaController::class, 'eliminar'])->middleware('permiso:nominas.calcular');
    Route::patch('/planillas-complementarias/{complementaria}/aprobar', [PlanillaComplementariaController::class, 'aprobar'])->middleware('permiso:nominas.aprobar');
    Route::patch('/planillas-complementarias/{complementaria}/pagar', [PlanillaComplementariaController::class, 'pagar'])->middleware('permiso:nominas.pagar');
    Route::post('/planillas-complementarias/{complementaria}/telecredito-bcp/exportar', [PlanillaComplementariaController::class, 'exportarBcp'])->middleware('permiso:nominas.telecredito_exportar');
    Route::post('/planillas-complementarias/{complementaria}/bbva-netcash/exportar', [PlanillaComplementariaController::class, 'exportarBbva'])->middleware('permiso:nominas.bbva_netcash_exportar');
    Route::get('/ciclos-remunerativos/{ciclo}/colaboradores/{colaborador}/conceptos', [CicloRemunerativoController::class, 'listarConceptos'])->middleware('permiso:nominas.ver');
    Route::post('/ciclos-remunerativos/{ciclo}/colaboradores/{colaborador}/conceptos', [CicloRemunerativoController::class, 'registrarConcepto'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::put('/ciclos-remunerativos/{ciclo}/colaboradores/{colaborador}/conceptos/{conceptoPeriodo}', [CicloRemunerativoController::class, 'actualizarConcepto'])->middleware('permiso:nominas.gestionar_ciclos');
    Route::delete('/ciclos-remunerativos/{ciclo}/colaboradores/{colaborador}/conceptos/{conceptoPeriodo}', [CicloRemunerativoController::class, 'eliminarConcepto'])->middleware('permiso:nominas.gestionar_ciclos');

    Route::middleware('empresa.admin')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

        Route::get('/permisos', [PermissionController::class, 'index']);
        Route::put('/permisos', [PermissionController::class, 'update']);
    });
});
