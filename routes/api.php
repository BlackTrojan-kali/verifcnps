<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post("/login",[AuthController::class,"login"]);
Route::post("/login-company",[AuthController::class,"loginCompany"]);

Route::middleware("auth:sanctum")->group(function(){
    Route::get("/me",[AuthController::class,"me"]);
    Route::get("/logout",[AuthController::class,'logout']);

    Route::middleware("role:company")->prefix("company")->group(function(){
        Route::get("/index",[CompanyController::class,"index"]);
    });
    Route::middleware("role:bank")->prefix("bank")->group(function(){

    });
    Route::middleware("role:cnps")->prefix("cnps")->group(function(){

    });
});