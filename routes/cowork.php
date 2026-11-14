<?php

declare(strict_types=1);

use FancyCowork\Http\CoworkController;
use Illuminate\Support\Facades\Route;

Route::post('sessions', [CoworkController::class, 'create'])->name('fancy-cowork.create');
Route::get('sessions/{session}', [CoworkController::class, 'show'])->name('fancy-cowork.show');
Route::post('sessions/{session}/activate', [CoworkController::class, 'activate'])->name('fancy-cowork.activate');
Route::post('sessions/{session}/join', [CoworkController::class, 'join'])->name('fancy-cowork.join');
Route::post('sessions/{session}/consent', [CoworkController::class, 'consent'])->name('fancy-cowork.consent');
Route::post('sessions/{session}/leave', [CoworkController::class, 'leave'])->name('fancy-cowork.leave');
Route::post('sessions/{session}/end', [CoworkController::class, 'end'])->name('fancy-cowork.end');
Route::post('sessions/{session}/follow', [CoworkController::class, 'follow'])->name('fancy-cowork.follow');
Route::post('sessions/{session}/intents', [CoworkController::class, 'intent'])->name('fancy-cowork.intent');
Route::post('auth', [CoworkController::class, 'auth'])->name('fancy-cowork.auth');
