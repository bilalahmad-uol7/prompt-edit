<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

// No landing page: guests go to the login screen, signed-in users are sent on to /generate.
Route::redirect('/', '/login')->name('home');

Route::middleware('auth')->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:generations')->name('generations.store');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->middleware('throttle:generations')->name('generations.retry');
    Route::post('quote', QuoteController::class)->name('generations.quote');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', ReportController::class)->middleware('can:view-admin-report')->name('admin.report');
});

require __DIR__.'/settings.php';
