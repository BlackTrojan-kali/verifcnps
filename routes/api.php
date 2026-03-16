<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CnpsController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DeclarationController;
use App\Http\Controllers\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post("/login",[AuthController::class,"login"]);
Route::post("/login-company",[AuthController::class,"loginCompany"]);

Route::middleware("auth:sanctum")->group(function(){
    Route::get("/me",[AuthController::class,"me"]);
    Route::get("/logout",[AuthController::class,'logout']);
    Route::prefix("notifications")->group(function(){
            Route::get("/unread",[NotificationController::class,"unread"]);
            Route::get("/all",[NotificationController::class,"all"]);
            Route::put("/mark-as-read/{id}",[NotificationController::class,"MarkAsRead"]);
            Route::post("/mark-all-as-read",[NotificationController::class,"MarkAllAsRead"]);
    });
    Route::middleware("role:company")->prefix("company")->group(function(){
        Route::get('/declarations', [CompanyController::class, 'index']);
        
        Route::post('/declarations', [CompanyController::class, 'InitiateDeclaration']);
        
       Route::post('/declarations/{id}', [CompanyController::class, 'EditDeclaration']);
        
        Route::patch('/declarations/{id}/status', [CompanyController::class, 'changeDeclarationStatus']);
        
    
    });
    Route::middleware("role:bank")->prefix("bank")->group(function(){

    });
    Route::middleware("role:cnps")->prefix("cnps")->group(function(){
        // Lister toutes les déclarations (avec filtres et pagination)
        Route::get('/declarations', [CnpsController::class, 'index']);
        // ... vos routes CNPS existantes ...
        
        // Exporter le rapport PDF
        Route::get('/reports/declarations/pdf', [CnpsController::class, 'exportPdf']);
        // Récupérer les données pour les graphiques du Dashboard
        Route::get('/statistics', [CnpsController::class, 'statistics']);
        
        // Valider (rapprocher) un paiement
        Route::put('/declarations/{id}/reconcile', [CnpsController::class, 'reconcilePayment']);
        
        // Rejeter un paiement
        Route::put('/declarations/{id}/reject', [CnpsController::class, 'rejectPayment']);
                // Gestion des Banques
        Route::get('/banks', [CnpsController::class, 'listBanks']);
        Route::post('/banks', [CnpsController::class, 'storeBank']);

        // Gestion des Agents CNPS
        Route::get('/agents', [CnpsController::class, 'listCnpsAgents']);
        Route::post('/agents', [CnpsController::class, 'storeCnpsAgent']);
    });
});