<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MainController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AdminLoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AdminLoginController::class, 'login'])->name('login.post');
Route::post('/logout', [AdminLoginController::class, 'logout'])->name('logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth'])
    ->group(function () {
        Route::get('/', [MainController::class, 'index'])->name('index');

        Route::post('categories/bulk-delete', [CategoryController::class, 'bulkDelete'])->name('categories.bulk-delete');
        Route::resource('categories', CategoryController::class)->except(['show']);

        Route::post('products/bulk-delete', [ProductController::class, 'bulkDelete'])->name('products.bulk-delete');
        Route::post('products/{product}/translate-ru', [ProductController::class, 'translateRu'])->name('products.translate-ru');
        Route::post('products/{product}/verify-docx', [ProductController::class, 'verifyDocx'])->name('products.verify-docx');
        Route::get('products/{product}/docx-blocks', [ProductController::class, 'docxBlocks'])->name('products.docx-blocks');
        Route::get('products/{product}/docx-json', [ProductController::class, 'docxJson'])->name('products.docx-json');
        Route::resource('products', ProductController::class)->except(['show']);

        Route::resource('languages', LanguageController::class)->except(['show']);

        Route::middleware(['admin'])->group(function () {
            Route::resource('roles', RoleController::class)->except(['show']);
            Route::resource('users', UserController::class)->except(['show']);
        });
    });
