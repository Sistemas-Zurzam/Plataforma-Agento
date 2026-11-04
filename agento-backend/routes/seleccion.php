<?php

use App\Modules\Reclutamiento\Http\Controllers\BancoTalentosController;
use App\Modules\Reclutamiento\Http\Controllers\ContratacionController;
use App\Modules\Reclutamiento\Http\Controllers\DashboardController;
use App\Modules\Reclutamiento\Http\Controllers\EntrevistaController;
use App\Modules\Reclutamiento\Http\Controllers\PostulacionController;
use App\Modules\Reclutamiento\Http\Controllers\ProcesoController;
use App\Modules\Reclutamiento\Http\Controllers\PruebaController;
use App\Modules\Reclutamiento\Http\Controllers\Publico\CandidatoController;
use App\Modules\Reclutamiento\Http\Controllers\Publico\EmpleosController;
use App\Modules\Reclutamiento\Http\Controllers\Publico\PruebaPublicaController;
use App\Modules\Reclutamiento\Http\Controllers\SolicitudController;
use App\Modules\Reclutamiento\Http\Middleware\AutenticarCandidato;
use App\Modules\Reclutamiento\Http\Middleware\ExigirAlgunPermiso;
use Illuminate\Support\Facades\Route;

/*
| Selección y Reclutamiento. Incluido desde routes/api.php.
|
| /api/seleccion: JWT obligatorio; la empresa sale del usuario autenticado y
| los {modelos} se resuelven con el scope de empresa (un id de otra empresa
| responde 404). Los permisos usan el RBAC existente.
|
| /api/publico: portal de empleos sin JWT, con rate limiting. La empresa se
| resuelve desde la publicación.
*/

$alguno = fn (string ...$permisos) => ExigirAlgunPermiso::class.':'.implode(',', $permisos);

Route::middleware('jwt')->prefix('seleccion')->group(function () use ($alguno) {
    $verSolicitudes = $alguno('seleccion.solicitudes.crear', 'seleccion.solicitudes.decidir', 'seleccion.seguimiento', 'seleccion.vacantes.gestionar');
    $verProcesos = $alguno('seleccion.seguimiento', 'seleccion.vacantes.gestionar', 'seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar');
    $gestionarCandidatos = $alguno('seleccion.candidatos.gestionar');
    $verCandidatos = $alguno('seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar');

    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware($alguno('seleccion.seguimiento', 'seleccion.vacantes.gestionar'));
    Route::get('/tareas', [DashboardController::class, 'tareas'])->middleware($alguno('seleccion.vacantes.gestionar', 'seleccion.contratar', 'colaboradores.editar'));
    Route::patch('/tareas/{tarea}/completar', [DashboardController::class, 'completarTarea'])->middleware($alguno('seleccion.vacantes.gestionar', 'seleccion.contratar', 'colaboradores.editar'));
    Route::get('/usuarios', [ProcesoController::class, 'usuarios'])->middleware($alguno('seleccion.vacantes.gestionar', 'seleccion.solicitudes.decidir', 'seleccion.evaluaciones.gestionar'));

    // Solicitudes de personal
    Route::get('/solicitudes', [SolicitudController::class, 'index'])->middleware($verSolicitudes);
    Route::get('/solicitudes/referencias', [SolicitudController::class, 'referencias'])->middleware($verSolicitudes);
    Route::post('/solicitudes', [SolicitudController::class, 'store'])->middleware('permiso:seleccion.solicitudes.crear');
    Route::get('/solicitudes/{solicitud}', [SolicitudController::class, 'show'])->middleware($verSolicitudes);
    Route::put('/solicitudes/{solicitud}', [SolicitudController::class, 'update'])->middleware('permiso:seleccion.solicitudes.crear');
    Route::post('/solicitudes/{solicitud}/enviar', [SolicitudController::class, 'enviar'])->middleware('permiso:seleccion.solicitudes.crear');
    Route::post('/solicitudes/{solicitud}/decision', [SolicitudController::class, 'decidir'])->middleware('permiso:seleccion.solicitudes.decidir');
    Route::get('/solicitudes/{solicitud}/impacto-cancelacion', [SolicitudController::class, 'impactoCancelacion'])->middleware($verSolicitudes);
    Route::post('/solicitudes/{solicitud}/cancelar', [SolicitudController::class, 'cancelar'])->middleware($alguno('seleccion.solicitudes.crear', 'seleccion.solicitudes.decidir', 'seleccion.vacantes.gestionar'));

    // Procesos, plazas y publicación
    Route::get('/procesos', [ProcesoController::class, 'index'])->middleware($verProcesos);
    Route::get('/procesos/{proceso}', [ProcesoController::class, 'show'])->middleware($verProcesos);
    Route::get('/procesos/{proceso}/actividad', [ProcesoController::class, 'actividad'])->middleware($verProcesos);
    Route::put('/procesos/{proceso}', [ProcesoController::class, 'update'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::put('/procesos/{proceso}/perfil', [ProcesoController::class, 'guardarPerfil'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::post('/procesos/{proceso}/publicar', [ProcesoController::class, 'publicar'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::post('/procesos/{proceso}/retirar-publicacion', [ProcesoController::class, 'retirarPublicacion'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::post('/procesos/{proceso}/estado', [ProcesoController::class, 'cambiarEstado'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::get('/procesos/{proceso}/tablero', [PostulacionController::class, 'tablero'])->middleware($verCandidatos);
    Route::get('/procesos/{proceso}/coincidencias', [BancoTalentosController::class, 'coincidencias'])->middleware($gestionarCandidatos);
    Route::post('/procesos/{proceso}/invitaciones', [BancoTalentosController::class, 'invitar'])->middleware($alguno('seleccion.candidatos.gestionar', 'seleccion.vacantes.gestionar'));

    // Postulaciones y transiciones
    Route::get('/postulaciones', [PostulacionController::class, 'index'])->middleware($verCandidatos);
    Route::post('/postulaciones/acciones-masivas', [PostulacionController::class, 'accionMasiva'])->middleware($gestionarCandidatos);
    Route::get('/postulaciones/{postulacion}', [PostulacionController::class, 'show'])->middleware($verCandidatos);
    Route::get('/postulaciones/{postulacion}/cv', [PostulacionController::class, 'cv'])->middleware($verCandidatos);
    Route::post('/postulaciones/{postulacion}/mover', [PostulacionController::class, 'mover'])->middleware($gestionarCandidatos);
    Route::post('/postulaciones/{postulacion}/seleccionar', [PostulacionController::class, 'seleccionar'])->middleware($gestionarCandidatos);
    Route::post('/postulaciones/{postulacion}/descartar', [PostulacionController::class, 'descartar'])->middleware($gestionarCandidatos);
    Route::post('/postulaciones/{postulacion}/reconsiderar', [PostulacionController::class, 'reconsiderar'])->middleware($gestionarCandidatos);
    Route::post('/postulaciones/{postulacion}/retiro', [PostulacionController::class, 'retirar'])->middleware($gestionarCandidatos);
    Route::post('/postulaciones/{postulacion}/no-aplica', [PostulacionController::class, 'noAplica'])->middleware($verCandidatos);
    Route::post('/postulaciones/{postulacion}/evaluacion-ats', [PostulacionController::class, 'evaluarAts'])->middleware($verCandidatos);

    // Entrevistas (el evaluador asignado puede evaluar sin permisos de gestión)
    Route::get('/entrevistas/mias', [EntrevistaController::class, 'mias']);
    Route::post('/postulaciones/{postulacion}/entrevistas', [EntrevistaController::class, 'store'])->middleware($verCandidatos);
    Route::post('/entrevistas/{entrevista}/cancelar', [EntrevistaController::class, 'cancelar'])->middleware($verCandidatos);
    Route::post('/entrevistas/{entrevista}/evaluaciones', [EntrevistaController::class, 'evaluar']);

    // Banco de pruebas y asignaciones
    Route::get('/pruebas', [PruebaController::class, 'index'])->middleware($verCandidatos);
    Route::post('/pruebas', [PruebaController::class, 'store'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::get('/pruebas/{prueba}', [PruebaController::class, 'show'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::put('/pruebas/{prueba}', [PruebaController::class, 'update'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::post('/pruebas/{prueba}/publicar', [PruebaController::class, 'publicar'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::post('/pruebas/{prueba}/validar', [PruebaController::class, 'validar'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::post('/pruebas/{prueba}/nueva-version', [PruebaController::class, 'nuevaVersion'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::post('/pruebas/{prueba}/archivar', [PruebaController::class, 'archivar'])->middleware('permiso:seleccion.evaluaciones.gestionar');
    Route::post('/postulaciones/{postulacion}/pruebas/{prueba}', [PruebaController::class, 'asignar'])->middleware($verCandidatos);

    // Contratación
    Route::get('/contratacion/opciones', [ContratacionController::class, 'opciones'])->middleware('permiso:seleccion.contratar');
    Route::post('/postulaciones/{postulacion}/contratar', [ContratacionController::class, 'contratar'])->middleware('permiso:seleccion.contratar');
    Route::post('/postulaciones/{postulacion}/periodo-prueba', [ContratacionController::class, 'resultadoPeriodoPrueba'])->middleware($alguno('seleccion.contratar', 'colaboradores.editar'));

    // Banco de talentos
    Route::get('/banco-talentos', [BancoTalentosController::class, 'index'])->middleware($gestionarCandidatos);
    Route::get('/banco-talentos/{candidato}', [BancoTalentosController::class, 'show'])->middleware($gestionarCandidatos);
    Route::post('/banco-talentos/{candidato}/etiquetas', [BancoTalentosController::class, 'etiquetar'])->middleware($gestionarCandidatos);
    Route::delete('/banco-talentos/{candidato}/etiquetas/{etiqueta}', [BancoTalentosController::class, 'quitarEtiqueta'])->middleware($gestionarCandidatos);
});

Route::prefix('publico')->middleware('throttle:seleccion-publico')->group(function () {
    Route::get('/empleos', [EmpleosController::class, 'index']);
    Route::get('/empleos/{codigo}', [EmpleosController::class, 'show']);

    Route::post('/candidatos/codigo', [CandidatoController::class, 'solicitarCodigo'])->middleware('throttle:seleccion-codigos');
    Route::post('/candidatos/verificar', [CandidatoController::class, 'verificarCodigo'])->middleware('throttle:seleccion-verificacion');

    Route::middleware(AutenticarCandidato::class)->group(function () {
        Route::post('/empleos/{codigo}/postulaciones', [EmpleosController::class, 'postular']);
        Route::get('/candidatos/perfil', [CandidatoController::class, 'perfil']);
        Route::get('/candidatos/postulaciones', [CandidatoController::class, 'misPostulaciones']);
        Route::post('/candidatos/postulaciones/{postulacion}/retiro', [CandidatoController::class, 'retirar'])->whereNumber('postulacion');
        Route::post('/candidatos/pruebas/{asignacion}/acceso', [CandidatoController::class, 'accesoPrueba'])->whereNumber('asignacion');
        Route::post('/candidatos/sesion/cerrar', [CandidatoController::class, 'cerrarSesion']);
    });

    Route::get('/pruebas/{token}', [PruebaPublicaController::class, 'show']);
    Route::post('/pruebas/{token}/iniciar', [PruebaPublicaController::class, 'iniciar']);
    Route::post('/pruebas/{token}/respuestas', [PruebaPublicaController::class, 'responder']);
    Route::post('/pruebas/{token}/finalizar', [PruebaPublicaController::class, 'finalizar']);
});
