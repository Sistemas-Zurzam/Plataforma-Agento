<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Modules\Asistencia\Http\Controllers\HorarioController;
use App\Modules\Configuracion\Http\Controllers\AreaController;
use App\Modules\Configuracion\Http\Controllers\ComisionAfpController;
use App\Modules\Configuracion\Http\Controllers\EmpresaController;
use App\Modules\Configuracion\Http\Controllers\ParametroLaboralController;
use App\Modules\Configuracion\Http\Controllers\PermissionController;
use App\Modules\Configuracion\Http\Controllers\RoleController;
use App\Modules\Configuracion\Http\Controllers\SedeController;
use App\Modules\Configuracion\Http\Controllers\UsuarioController;
use App\Modules\Personas\Http\Controllers\ColaboradorController;
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
    Route::put('/empresas/{empresa}/activar', [EmpresaController::class, 'activar']);

    Route::get('/empresas/{empresa}/sedes', [SedeController::class, 'index']);
    Route::post('/empresas/{empresa}/sedes', [SedeController::class, 'store'])->middleware('permiso:sedes.crear');
    Route::put('/empresas/{empresa}/sedes/{sede}', [SedeController::class, 'update'])->middleware('permiso:sedes.editar');

    Route::get('/empresas/{empresa}/areas', [AreaController::class, 'index']);
    Route::post('/empresas/{empresa}/areas', [AreaController::class, 'store'])->middleware('permiso:areas.crear');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permiso:usuarios.ver');
    Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('permiso:usuarios.ver');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->middleware('permiso:usuarios.crear');
    Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->middleware('permiso:usuarios.editar');
    Route::patch('/usuarios/{usuario}/rol', [UsuarioController::class, 'actualizarRol'])->middleware('permiso:usuarios.cambiar_rol');
    Route::delete('/usuarios/{usuario}', [UsuarioController::class, 'destroy'])->middleware('permiso:usuarios.eliminar');

    Route::get('/parametros-laborales', [ParametroLaboralController::class, 'index'])->middleware('permiso:parametros_laborales.ver');
    Route::post('/parametros-laborales', [ParametroLaboralController::class, 'store'])->middleware('permiso:parametros_laborales.editar');
    Route::post('/parametros-laborales/inicializar', [ParametroLaboralController::class, 'inicializar'])->middleware('permiso:parametros_laborales.editar');

    Route::get('/comisiones-afp', [ComisionAfpController::class, 'index'])->middleware('permiso:comisiones_afp.ver');
    Route::post('/comisiones-afp', [ComisionAfpController::class, 'store'])->middleware('permiso:comisiones_afp.editar');
    Route::delete('/comisiones-afp/{comision}', [ComisionAfpController::class, 'destroy'])->middleware('permiso:comisiones_afp.editar');
    Route::post('/comisiones-afp/cargar-sbs', [ComisionAfpController::class, 'cargarSbs'])->middleware('permiso:comisiones_afp.editar');

    Route::get('/horarios', [HorarioController::class, 'index'])->middleware('permiso:horarios.ver');
    Route::post('/horarios', [HorarioController::class, 'store'])->middleware('permiso:horarios.crear');
    Route::put('/horarios/{horario}', [HorarioController::class, 'update'])->middleware('permiso:horarios.editar');
    Route::post('/horarios/{horario}/duplicar', [HorarioController::class, 'duplicar'])->middleware('permiso:horarios.crear');
    Route::patch('/horarios/{horario}/estado', [HorarioController::class, 'cambiarEstado'])->middleware('permiso:horarios.editar');

    Route::get('/colaboradores', [ColaboradorController::class, 'index'])->middleware('permiso:colaboradores.ver');
    Route::post('/colaboradores', [ColaboradorController::class, 'store'])->middleware('permiso:colaboradores.crear');
    Route::get('/colaboradores/calendario-defecto', [ColaboradorController::class, 'calendarioDefecto'])->middleware('permiso:colaboradores.crear');

    Route::middleware('empresa.admin')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

        Route::get('/permisos', [PermissionController::class, 'index']);
        Route::put('/permisos', [PermissionController::class, 'update']);
    });
});
