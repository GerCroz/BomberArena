<?php

use App\Http\Controllers\AdminRulesController;
use App\Http\Controllers\BomberGameController;
use App\Http\Controllers\InstructorMonitorController;
use App\Http\Controllers\RemoteStyleController;
use App\Http\Controllers\RoomSocialController;
use Illuminate\Support\Facades\Route;

Route::get('/api/public-rooms', [RoomSocialController::class, 'index'])->name('api.rooms.public');
Route::post('/api/arena/broadcasting/auth', [RoomSocialController::class, 'authorizeChannel']);

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
    Route::post('/{code}/bot', [BomberGameController::class, 'addBot'])->name('api.rooms.bot');
    Route::post('/{code}/start', [BomberGameController::class, 'start'])->name('api.rooms.start');
    Route::post('/{code}/move', [BomberGameController::class, 'move'])->name('api.rooms.move');
    Route::post('/{code}/bomb', [BomberGameController::class, 'bomb'])->name('api.rooms.bomb');
    Route::post('/{code}/actions', [BomberGameController::class, 'actions'])->name('api.rooms.actions');
    Route::post('/{code}/input', [BomberGameController::class, 'input'])->name('api.rooms.input');
    Route::post('/{code}/detonate', [BomberGameController::class, 'detonate'])->name('api.rooms.detonate');
    Route::post('/{code}/tick', [BomberGameController::class, 'tick'])->name('api.rooms.tick');
    Route::post('/{code}/rematch', [RoomSocialController::class, 'requestRematch'])->name('api.rooms.rematch');
    Route::post('/{code}/rematch/respond', [RoomSocialController::class, 'respond'])->name('api.rooms.rematch.respond');
    Route::post('/{code}/character', [RoomSocialController::class, 'character']);
    Route::post('/{code}/presence', [RoomSocialController::class, 'presence']);
    Route::post('/{code}/leave', [RoomSocialController::class, 'leave']);
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

/*
|--------------------------------------------------------------------------
| Panel Administrativo de Reglas y Monitoreo de Partidas
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminRulesController::class, 'showLogin'])->name('admin.login');
    Route::post('/login', [AdminRulesController::class, 'login'])->name('admin.login.submit');
    Route::post('/logout', [AdminRulesController::class, 'logout'])->name('admin.logout');

    Route::middleware('admin')->group(function () {
        Route::get('/reglas', [AdminRulesController::class, 'rulesDashboard'])->name('admin.rules');
        Route::post('/reglas', [AdminRulesController::class, 'updateRules'])->name('admin.rules.update');
        Route::get('/api/partidas', [AdminRulesController::class, 'getActiveMatches'])->name('admin.matches.api');
        Route::post('/partidas/{code}/cancelar', [AdminRulesController::class, 'cancelMatch'])->name('admin.matches.cancel');
    });
});
