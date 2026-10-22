<?php

use App\Http\Controllers\Admin\AdminLangController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AttributeGroupController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\HelpCategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\ManufacturerController;
use App\Http\Controllers\Admin\MainController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPipelineController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RouteSkeletonController;
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
        Route::get('lang/{code}', [AdminLangController::class, 'switch'])->name('lang');

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
        Route::get('test/locations', [TestController::class, 'locations'])->name('test.locations');
        Route::post('test/locations/search', [TestController::class, 'locationsSearch'])->name('test.locations.search');
        Route::post('test/locations/assemble', [TestController::class, 'locationsAssemble'])->name('test.locations.assemble');
        Route::get('test/scenario', [TestController::class, 'scenario'])->name('test.scenario');
        Route::post('test/scenario/plan', [TestController::class, 'scenarioPlan'])->name('test.scenario.plan');
        Route::post('test/scenario/audit', [TestController::class, 'scenarioAudit'])->name('test.scenario.audit');
        Route::get('test/chain', [TestController::class, 'chain'])->name('test.chain');
        Route::post('test/chain/run', [TestController::class, 'chainRun'])->name('test.chain.run');
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

        Route::post('locations/bulk-delete', [LocationController::class, 'bulkDelete'])->name('locations.bulk-delete');
        Route::get('locations/facts-pipeline/status', [LocationController::class, 'factsPipelineStatus'])->name('locations.facts-pipeline.status');
        Route::post('locations/facts-pipeline/start', [LocationController::class, 'factsPipelineStart'])->name('locations.facts-pipeline.start');
        Route::post('locations/facts-pipeline/cancel', [LocationController::class, 'factsPipelineCancel'])->name('locations.facts-pipeline.cancel');
        Route::get('locations/vector-pipeline/status', [LocationController::class, 'vectorPipelineStatus'])->name('locations.vector-pipeline.status');
        Route::post('locations/vector-pipeline/start', [LocationController::class, 'vectorPipelineStart'])->name('locations.vector-pipeline.start');
        Route::post('locations/vector-pipeline/cancel', [LocationController::class, 'vectorPipelineCancel'])->name('locations.vector-pipeline.cancel');
        Route::post('locations/{location}/index-vector', [LocationController::class, 'indexVector'])->name('locations.index-vector');
        Route::post('locations/{location}/check-facts', [LocationController::class, 'checkFacts'])->name('locations.check-facts');
        Route::post('locations/{location}/translate-ru', [LocationController::class, 'translateRu'])->name('locations.translate-ru');
        Route::post('locations/{location}/translate-facts-ru', [LocationController::class, 'translateFactsRu'])->name('locations.translate-facts-ru');
        Route::post('locations/{location}/split-facts', [LocationController::class, 'splitFacts'])->name('locations.split-facts');
        Route::post('locations/{location}/facts', [LocationController::class, 'factStore'])->name('locations.facts.store');
        Route::put('locations/{location}/facts/{fact}', [LocationController::class, 'factUpdate'])->name('locations.facts.update');
        Route::delete('locations/{location}/facts/{fact}', [LocationController::class, 'factDestroy'])->name('locations.facts.destroy');
        Route::resource('locations', LocationController::class)->except(['show']);

        Route::resource('manufacturers', ManufacturerController::class)->except(['show']);
        Route::post('areas/{area}/assign-locations', [AreaController::class, 'assignLocations'])->name('areas.assign-locations');
        Route::resource('areas', AreaController::class)->except(['show']);

        Route::get('route-skeletons', [RouteSkeletonController::class, 'index'])->name('route-skeletons.index');
        Route::get('route-skeletons/{route_skeleton}', [RouteSkeletonController::class, 'show'])->name('route-skeletons.show');
        Route::post('route-skeletons/{route_skeleton}/translate-ru', [RouteSkeletonController::class, 'translateRu'])->name('route-skeletons.translate-ru');

        Route::resource('attribute-groups', AttributeGroupController::class)->except(['show']);
        Route::resource('attributes', AttributeController::class)->except(['show']);

        Route::resource('languages', LanguageController::class)->except(['show']);

        Route::get('database', [DatabaseController::class, 'index'])->name('database.index');
        Route::post('database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');
        Route::post('database/query', [DatabaseController::class, 'query'])->name('database.query');

        Route::middleware(['admin'])->group(function () {
            Route::resource('roles', RoleController::class)->except(['show']);
            Route::resource('users', UserController::class)->except(['show']);
        });
    });
