<?php

use App\Http\Controllers\CspReportController;
use Illuminate\Support\Facades\Route;

Route::post('/csp-report', [CspReportController::class, 'store'])
    ->middleware('throttle:120,1');
