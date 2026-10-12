<?php

use App\Http\Controllers\RemoteStyleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => redirect('/emisor'));

Route::get('/emisor', [RemoteStyleController::class, 'emisor'])->name('emisor');
Route::post('/emitir', [RemoteStyleController::class, 'emitir'])->name('emitir');

Route::get('/receptor', [RemoteStyleController::class, 'receptor'])->name('receptor');
