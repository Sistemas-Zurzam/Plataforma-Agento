<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SedeController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('jwt')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [ProfileController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/empresas', [EmpresaController::class, 'index']);
    Route::post('/empresas', [EmpresaController::class, 'store']);
    Route::put('/empresas/{empresa}', [EmpresaController::class, 'update']);
    Route::put('/empresas/{empresa}/activar', [EmpresaController::class, 'activar']);

    Route::get('/empresas/{empresa}/sedes', [SedeController::class, 'index']);
    Route::post('/empresas/{empresa}/sedes', [SedeController::class, 'store']);
    Route::put('/empresas/{empresa}/sedes/{sede}', [SedeController::class, 'update']);

    Route::get('/empresas/{empresa}/areas', [AreaController::class, 'index']);
    Route::post('/empresas/{empresa}/areas', [AreaController::class, 'store']);

    Route::middleware('empresa.admin')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);

        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::patch('/usuarios/{usuario}/rol', [UsuarioController::class, 'actualizarRol']);
        Route::delete('/usuarios/{usuario}', [UsuarioController::class, 'destroy']);
    });
});
