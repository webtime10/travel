<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\HelpCategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MainController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPipelineController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TestController;
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
        Route::get('categories/{category}/vector/status', [CategoryController::class, 'vectorStatus'])->name('categories.vector.status');
        Route::post('categories/{category}/vector/start', [CategoryController::class, 'vectorStart'])->name('categories.vector.start');
        Route::post('categories/{category}/vector/resume', [CategoryController::class, 'vectorResume'])->name('categories.vector.resume');
        Route::post('categories/{category}/vector/cancel', [CategoryController::class, 'vectorCancel'])->name('categories.vector.cancel');
        Route::resource('categories', CategoryController::class)->except(['show']);

        Route::post('help-categories/bulk-delete', [HelpCategoryController::class, 'bulkDelete'])->name('help-categories.bulk-delete');
        Route::post('help-categories/upload', [HelpCategoryController::class, 'uploadImage'])->name('help-categories.upload');
        Route::resource('help-categories', HelpCategoryController::class)->except(['show']);

        Route::get('test', [TestController::class, 'index'])->name('test.index');
        Route::post('test/search', [TestController::class, 'search'])->name('test.search');
        Route::post('test/chat', [TestController::class, 'chat'])->name('test.chat');
        Route::post('test/prompt', [TestController::class, 'savePrompt'])->name('test.prompt.save');

        Route::get('products/pipeline/status', [ProductPipelineController::class, 'status'])->name('products.pipeline.status');
        Route::post('products/pipeline/import/start', [ProductPipelineController::class, 'startImport'])->name('products.pipeline.import.start');
        Route::post('products/pipeline/import/cancel', [ProductPipelineController::class, 'cancelImport'])->name('products.pipeline.import.cancel');
        Route::post('products/pipeline/translate/start', [ProductPipelineController::class, 'startTranslate'])->name('products.pipeline.translate.start');
        Route::post('products/pipeline/translate/cancel', [ProductPipelineController::class, 'cancelTranslate'])->name('products.pipeline.translate.cancel');

        Route::post('products/bulk-delete', [ProductController::class, 'bulkDelete'])->name('products.bulk-delete');
        Route::post('products/{product}/translate-ru', [ProductController::class, 'translateRu'])->name('products.translate-ru');
        Route::post('products/{product}/verify-docx', [ProductController::class, 'verifyDocx'])->name('products.verify-docx');
        Route::get('products/{product}/docx-blocks', [ProductController::class, 'docxBlocks'])->name('products.docx-blocks');
        Route::get('products/{product}/docx-json', [ProductController::class, 'docxJson'])->name('products.docx-json');
        Route::resource('products', ProductController::class)->except(['show']);

        Route::resource('languages', LanguageController::class)->except(['show']);

        Route::get('database', [DatabaseController::class, 'index'])->name('database.index');
        Route::post('database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');
        Route::post('database/query', [DatabaseController::class, 'query'])->name('database.query');

        Route::middleware(['admin'])->group(function () {
            Route::resource('roles', RoleController::class)->except(['show']);
            Route::resource('users', UserController::class)->except(['show']);
        });
    });
