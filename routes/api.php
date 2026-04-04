<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CnpsController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DeclarationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SupervisorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
// ==========================================
// ROUTES D'AUTHENTIFICATION (Publiques)
// ==========================================
Route::post("/login", [AuthController::class, "login"]);
Route::post("/login-company", [AuthController::class, "loginCompany"]);
Route::get('/declarations/search/specific', [DeclarationController::class, 'findByNiuAmountPeriod']);
Route::middleware("auth:sanctum")->group(function(){
    Route::get("/me",[AuthController::class,"me"]);
    Route::get("/logout",[AuthController::class,'logout']);
    Route::prefix("notifications")->group(function(){
            Route::get("/unread",[NotificationController::class,"unread"]);
            Route::get("/all",[NotificationController::class,"all"]);
            Route::put("/mark-as-read/{id}",[NotificationController::class,"MarkAsRead"]);
            Route::post("/mark-all-as-read",[NotificationController::class,"MarkAllAsRead"]);
    });
    // ROUTE GLOBALE POUR LE TÉLÉCHARGEMENT DU PDF
    Route::get('/declarations/{id}', [DeclarationController::class, 'show']);
    Route::get('/declarations/{id}/download-proof', [DeclarationController::class, 'downloadProof']);
    Route::middleware("role:company")->prefix("company")->group(function(){
        
        Route::get('/banks', [BankController::class, 'listBanks']);
        Route::get('/declarations', [CompanyController::class, 'index']);
        Route::get('/declarations/{id}/download-receipt', [CompanyController::class, 'downloadReceipt']);    
        Route::post('/declarations', [CompanyController::class, 'InitiateDeclaration']);
         
       Route::put('/declarations/{id}', [CompanyController::class, 'EditDeclaration']);
        
        Route::patch('/declarations/{id}/status', [CompanyController::class, 'changeDeclarationStatus']);
            
    
    });
    Route::middleware("role:bank")->prefix("bank")->group(function(){
        Route::get('/dashboard-stats', [BankController::class, 'dashboardStats']);
        Route::get('/declarations', [BankController::class, 'index']);
        Route::get('/declarations/{id}', [BankController::class, 'show']);
        Route::put('/declarations/{id}/validate', [BankController::class, 'validatePayment']);
        Route::put('/declarations/{id}/reject', [BankController::class, 'rejectPayment']);
        
        // Gestion des dépôts au guichet
        Route::post('/counter-deposits', [BankController::class, 'storeCounterDeposit']);
        Route::put('/counter-deposits/{id}', [BankController::class, 'updateCounterDeposit']); // POST avec _method=PUT pour le FormData
        
        // Recherche d'entreprise au guichet
// NOUVELLE LIGNE CORRIGÉE
Route::get('/companies/search', [BankController::class, 'searchCompanyByNumeroEmployeur']);
        // ==========================================
        // Gestion des Agents (Guichetiers/Admins) de la Banque
        // ==========================================
     // ==========================================
        // Gestion des Agents (Guichetiers/Admins) de la Banque
        // ==========================================
        Route::get('/agents', [BankController::class, 'listAgents']); // <-- NOUVELLE LIGNE
        Route::post('/agents', [BankController::class, 'storeBankAgent']);
        Route::patch('/agents/{id}/password', [BankController::class, 'updateBankAgentPassword']);
        Route::patch('/agents/{id}/toggle-admin', [BankController::class, 'toggleAdminStatus']); // <-- NOUVELLE LIGNE
        });
    Route::middleware("role:cnps")->prefix("cnps")->group(function(){
        
        // ==========================================
        // STATISTIQUES ET RAPPORTS
        // ==========================================
        Route::get('/statistics', [CnpsController::class, 'statistics']);
        Route::get('/reports/declarations/pdf', [CnpsController::class, 'exportPdf']);

        // ==========================================
        // GESTION DES DÉCLARATIONS
        // ==========================================
        Route::get('/declarations', [CnpsController::class, 'index']);
        Route::put('/declarations/{id}/reconcile', [CnpsController::class, 'reconcilePayment']);
        Route::put('/declarations/{id}/reject', [CnpsController::class, 'rejectPayment']);
        Route::post('/declarations/{id}/receipt', [CnpsController::class, 'uploadReceipt']);
        
        // ==========================================
        // GESTION DES AGENTS CNPS
        // ==========================================
        Route::get('/agents', [CnpsController::class, 'listCnpsAgents']);
        Route::post('/agents', [CnpsController::class, 'storeCnpsAgent']);
        Route::patch('/agents/{id}/toggle-admin', [CnpsController::class, 'toggleAdminStatus']);
        Route::patch('/agents/{id}/password', [CnpsController::class, 'updateAgentPassword']); // Nouvelle route
        
    });
    Route::middleware("role:supervisor")->prefix("supervisor")->group(function(){
        
        // Vue d'ensemble et KPI
        Route::get('/dashboard-stats', [SupervisorController::class, 'dashboardStats']);
        
        // Liste globale et filtrage
        Route::get('/declarations', [SupervisorController::class, 'index']);
        Route::get('/declarations/{id}', [SupervisorController::class, 'show']);
        
        Route::get('/banks', [BankController::class, 'listBanks']);
        // Gestion des Banques (Intégrez ici vos routes de création de banque)
        // Route::post('/banks/agents', [SupervisorController::class, 'storeBankAgent']);
        // Route::patch('/banks/agents/{id}/password', [SupervisorController::class, 'updateBankAgentPassword']);
        
    });
});