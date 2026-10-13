<?php

use App\Http\Controllers\BomberGameController;
use App\Http\Controllers\InstructorMonitorController;
use App\Http\Controllers\RemoteStyleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bomber Arena - Rutas Principales
|--------------------------------------------------------------------------
*/

// Lobby principal
Route::get('/', [BomberGameController::class, 'lobby'])->name('home');
Route::get('/lobby', [BomberGameController::class, 'lobby'])->name('lobby');
Route::get('/arena/{code}', [BomberGameController::class, 'arena'])->name('arena');

// API de gestión de salas y juego
Route::prefix('api/rooms')->group(function () {
    Route::post('/', [BomberGameController::class, 'createRoom'])->name('api.rooms.create');
    Route::post('/join', [BomberGameController::class, 'joinRoom'])->name('api.rooms.join');
    Route::get('/{code}/state', [BomberGameController::class, 'getState'])->name('api.rooms.state');
    Route::post('/{code}/ready', [BomberGameController::class, 'toggleReady'])->name('api.rooms.ready');
    Route::post('/{code}/start', [BomberGameController::class, 'start'])->name('api.rooms.start');
    Route::post('/{code}/move', [BomberGameController::class, 'move'])->name('api.rooms.move');
    Route::post('/{code}/bomb', [BomberGameController::class, 'bomb'])->name('api.rooms.bomb');
    Route::post('/{code}/actions', [BomberGameController::class, 'actions'])->name('api.rooms.actions');
    Route::post('/{code}/detonate', [BomberGameController::class, 'detonate'])->name('api.rooms.detonate');
    Route::post('/{code}/rematch', [BomberGameController::class, 'rematch'])->name('api.rooms.rematch');
});

// Panel de Monitoreo Educativo de WebSockets para el Docente
Route::get('/docente/monitoreo', [InstructorMonitorController::class, 'dashboard'])->name('docente.monitoreo');
Route::get('/api/docente/telemetry', [InstructorMonitorController::class, 'getTelemetry'])->name('api.docente.telemetry');
Route::post('/api/docente/clear', [InstructorMonitorController::class, 'clearLogs'])->name('api.docente.clear');

/*
|--------------------------------------------------------------------------
| Ejercicio Previo: Emisor - Receptor Reverb
|--------------------------------------------------------------------------
*/
Route::get('/emisor', [RemoteStyleController::class, 'emisor'])->name('emisor');
Route::post('/emitir', [RemoteStyleController::class, 'emitir'])->name('emitir');
Route::get('/receptor', [RemoteStyleController::class, 'receptor'])->name('receptor');
