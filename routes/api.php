<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PayloadController;

Route::post('/relay', [PayloadController::class, 'relay']);
