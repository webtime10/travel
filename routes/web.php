<?php

use App\Http\Controllers\Admin\AdminLangController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AttributeGroupController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\GoldenDayController;
use App\Http\Controllers\Admin\GoldenLabelController;
use App\Http\Controllers\Admin\HelpCategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MainController;
use App\Http\Controllers\Admin\ManufacturerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPipelineController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\FtShortRouteController;
use App\Http\Controllers\Admin\NewShortRouteController;
use App\Http\Controllers\Admin\RouteSkeletonController;
use App\Http\Controllers\Admin\SavedRouteController;
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
        Route::get('test/php', [TestController::class, 'phpMock'])->name('test.php');
        Route::get('test/php/map', [TestController::class, 'phpMap'])->name('test.php.map');
        Route::get('test/php/gemini-locations', [TestController::class, 'phpGemini'])->name('test.php.gemini');
        Route::post('test/php/parse-query', [TestController::class, 'phpParseQuery'])->name('test.php.parse');
        Route::get('test/scenario', [TestController::class, 'scenario'])->name('test.scenario');
        Route::post('test/scenario/plan', [TestController::class, 'scenarioPlan'])->name('test.scenario.plan');
        Route::post('test/scenario/audit', [TestController::class, 'scenarioAudit'])->name('test.scenario.audit');
        Route::get('test/chain', [TestController::class, 'chain'])->name('test.chain');
        Route::post('test/chain/run', [TestController::class, 'chainRun'])->name('test.chain.run');
        Route::get('test/engineering-test', [TestController::class, 'engineeringTest'])->name('test.engineering');
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

        Route::get('locations/suggest', [LocationController::class, 'suggest'])->name('locations.suggest');
        Route::post('locations/bulk-delete', [LocationController::class, 'bulkDelete'])->name('locations.bulk-delete');
        Route::get('locations/facts-pipeline/status', [LocationController::class, 'factsPipelineStatus'])->name('locations.facts-pipeline.status');
        Route::post('locations/facts-pipeline/start', [LocationController::class, 'factsPipelineStart'])->name('locations.facts-pipeline.start');
        Route::post('locations/facts-pipeline/cancel', [LocationController::class, 'factsPipelineCancel'])->name('locations.facts-pipeline.cancel');
        Route::get('locations/passport-tags-pipeline/status', [LocationController::class, 'passportTagsPipelineStatus'])->name('locations.passport-tags-pipeline.status');
        Route::post('locations/passport-tags-pipeline/start', [LocationController::class, 'passportTagsPipelineStart'])->name('locations.passport-tags-pipeline.start');
        Route::post('locations/passport-tags-pipeline/cancel', [LocationController::class, 'passportTagsPipelineCancel'])->name('locations.passport-tags-pipeline.cancel');
        Route::get('locations/vector-pipeline/status', [LocationController::class, 'vectorPipelineStatus'])->name('locations.vector-pipeline.status');
        Route::post('locations/vector-pipeline/start', [LocationController::class, 'vectorPipelineStart'])->name('locations.vector-pipeline.start');
        Route::post('locations/vector-pipeline/cancel', [LocationController::class, 'vectorPipelineCancel'])->name('locations.vector-pipeline.cancel');
        Route::get('locations/ru-names-pipeline/status', [LocationController::class, 'ruNamesPipelineStatus'])->name('locations.ru-names-pipeline.status');
        Route::post('locations/ru-names-pipeline/start', [LocationController::class, 'ruNamesPipelineStart'])->name('locations.ru-names-pipeline.start');
        Route::post('locations/ru-names-pipeline/cancel', [LocationController::class, 'ruNamesPipelineCancel'])->name('locations.ru-names-pipeline.cancel');
        Route::get('locations/ru-content-pipeline/status', [LocationController::class, 'ruContentPipelineStatus'])->name('locations.ru-content-pipeline.status');
        Route::post('locations/ru-content-pipeline/start', [LocationController::class, 'ruContentPipelineStart'])->name('locations.ru-content-pipeline.start');
        Route::post('locations/ru-content-pipeline/cancel', [LocationController::class, 'ruContentPipelineCancel'])->name('locations.ru-content-pipeline.cancel');
        Route::post('locations/{location}/index-vector', [LocationController::class, 'indexVector'])->name('locations.index-vector');
        Route::post('locations/{location}/check-facts', [LocationController::class, 'checkFacts'])->name('locations.check-facts');
        Route::post('locations/{location}/translate-ru', [LocationController::class, 'translateRu'])->name('locations.translate-ru');
        Route::post('locations/{location}/translate-facts-ru', [LocationController::class, 'translateFactsRu'])->name('locations.translate-facts-ru');
        Route::post('locations/{location}/split-facts', [LocationController::class, 'splitFacts'])->name('locations.split-facts');
        Route::post('locations/{location}/passport-tags', [LocationController::class, 'extractPassportTags'])->name('locations.passport-tags');
        Route::post('locations/{location}/facts', [LocationController::class, 'factStore'])->name('locations.facts.store');
        Route::put('locations/{location}/facts/{fact}', [LocationController::class, 'factUpdate'])->name('locations.facts.update');
        Route::delete('locations/{location}/facts/{fact}', [LocationController::class, 'factDestroy'])->name('locations.facts.destroy');
        Route::resource('locations', LocationController::class)->except(['show']);

        Route::resource('manufacturers', ManufacturerController::class)->except(['show']);
        Route::post('areas/{area}/assign-locations', [AreaController::class, 'assignLocations'])->name('areas.assign-locations');
        Route::resource('areas', AreaController::class)->except(['show']);

        Route::get('new-short-routes', [NewShortRouteController::class, 'index'])->name('new-short-routes.index');
        Route::post('new-short-routes/scan', [NewShortRouteController::class, 'scan'])->name('new-short-routes.scan');
        Route::post('new-short-routes/gemini-overnights-batch', [NewShortRouteController::class, 'geminiOvernightsBatch'])->name('new-short-routes.gemini-overnights-batch');
        Route::post('new-short-routes/translate-ru-batch', [NewShortRouteController::class, 'translateRuBatch'])->name('new-short-routes.translate-ru-batch');
        Route::get('new-short-routes/vector-pipeline/status', [NewShortRouteController::class, 'vectorPipelineStatus'])->name('new-short-routes.vector-pipeline.status');
        Route::post('new-short-routes/vector-pipeline/start', [NewShortRouteController::class, 'vectorPipelineStart'])->name('new-short-routes.vector-pipeline.start');
        Route::post('new-short-routes/vector-pipeline/cancel', [NewShortRouteController::class, 'vectorPipelineCancel'])->name('new-short-routes.vector-pipeline.cancel');
        Route::get('new-short-routes/{new_short_route}', [NewShortRouteController::class, 'show'])->name('new-short-routes.show');
        Route::post('new-short-routes/{new_short_route}/translate-ru', [NewShortRouteController::class, 'translateRu'])->name('new-short-routes.translate-ru');
        Route::post('new-short-routes/{new_short_route}/rescan', [NewShortRouteController::class, 'rescanOne'])->name('new-short-routes.rescan');
        Route::post('new-short-routes/{new_short_route}/save-days', [NewShortRouteController::class, 'saveDays'])->name('new-short-routes.save-days');
        Route::post('new-short-routes/{new_short_route}/gemini-overnights', [NewShortRouteController::class, 'geminiOvernights'])->name('new-short-routes.gemini-overnights');
        Route::delete('new-short-routes/{new_short_route}', [NewShortRouteController::class, 'destroy'])->name('new-short-routes.destroy');

        Route::get('ft-short-routes', [FtShortRouteController::class, 'index'])->name('ft-short-routes.index');
        Route::post('ft-short-routes/build-batch', [FtShortRouteController::class, 'buildBatch'])->name('ft-short-routes.build-batch');
        Route::post('ft-short-routes/translate-ru-batch', [FtShortRouteController::class, 'translateRuBatch'])->name('ft-short-routes.translate-ru-batch');
        Route::post('ft-short-routes/reverse-prompt', [FtShortRouteController::class, 'saveReversePrompt'])->name('ft-short-routes.reverse-prompt.save');
        Route::post('ft-short-routes/reverse-prompt/reset', [FtShortRouteController::class, 'resetReversePrompt'])->name('ft-short-routes.reverse-prompt.reset');
        Route::get('ft-short-routes/reverse-pipeline/status', [FtShortRouteController::class, 'reversePipelineStatus'])->name('ft-short-routes.reverse-pipeline.status');
        Route::post('ft-short-routes/reverse-pipeline/start', [FtShortRouteController::class, 'reversePipelineStart'])->name('ft-short-routes.reverse-pipeline.start');
        Route::post('ft-short-routes/reverse-pipeline/cancel', [FtShortRouteController::class, 'reversePipelineCancel'])->name('ft-short-routes.reverse-pipeline.cancel');
        Route::get('ft-short-routes/{ft_short_route}', [FtShortRouteController::class, 'show'])->name('ft-short-routes.show');
        Route::get('ft-short-routes/{ft_short_route}/pdf/{lang}', [FtShortRouteController::class, 'pdf'])
            ->whereIn('lang', ['he', 'ru'])
            ->name('ft-short-routes.pdf');
        Route::post('ft-short-routes/{ft_short_route}/rebuild', [FtShortRouteController::class, 'rebuildOne'])->name('ft-short-routes.rebuild');
        Route::post('ft-short-routes/{ft_short_route}/translate-ru', [FtShortRouteController::class, 'translateRu'])->name('ft-short-routes.translate-ru');
        Route::post('ft-short-routes/{ft_short_route}/reverse', [FtShortRouteController::class, 'reverseOne'])->name('ft-short-routes.reverse');
        Route::delete('ft-short-routes/{ft_short_route}', [FtShortRouteController::class, 'destroy'])->name('ft-short-routes.destroy');

        Route::get('route-skeletons', [RouteSkeletonController::class, 'index'])->name('route-skeletons.index');
        Route::get('route-skeletons/reverse-pipeline/status', [RouteSkeletonController::class, 'reversePipelineStatus'])->name('route-skeletons.reverse-pipeline.status');
        Route::post('route-skeletons/reverse-pipeline/start', [RouteSkeletonController::class, 'reversePipelineStart'])->name('route-skeletons.reverse-pipeline.start');
        Route::post('route-skeletons/reverse-pipeline/cancel', [RouteSkeletonController::class, 'reversePipelineCancel'])->name('route-skeletons.reverse-pipeline.cancel');
        Route::post('route-skeletons/reverse-principles/clear', [RouteSkeletonController::class, 'clearReversePrinciples'])->name('route-skeletons.reverse-principles.clear');
        Route::post('route-skeletons/reverse-prompt', [RouteSkeletonController::class, 'saveReversePrompt'])->name('route-skeletons.reverse-prompt.save');
        Route::post('route-skeletons/reverse-prompt/reset', [RouteSkeletonController::class, 'resetReversePrompt'])->name('route-skeletons.reverse-prompt.reset');
        Route::get('route-skeletons/export/finetune-jsonl', [RouteSkeletonController::class, 'exportFineTuneJsonl'])->name('route-skeletons.export.finetune-jsonl');
        Route::get('route-skeletons/{route_skeleton}', [RouteSkeletonController::class, 'show'])->name('route-skeletons.show');
        Route::post('route-skeletons/{route_skeleton}/translate-ru', [RouteSkeletonController::class, 'translateRu'])->name('route-skeletons.translate-ru');
        Route::post('route-skeletons/{route_skeleton}/reverse', [RouteSkeletonController::class, 'reverseOne'])->name('route-skeletons.reverse');
        Route::delete('route-skeletons/{route_skeleton}', [RouteSkeletonController::class, 'destroy'])->name('route-skeletons.destroy');

        Route::get('saved-routes', [SavedRouteController::class, 'index'])->name('saved-routes.index');
        Route::post('saved-routes', [SavedRouteController::class, 'store'])->name('saved-routes.store');
        Route::get('saved-routes/{saved_route}', [SavedRouteController::class, 'show'])->name('saved-routes.show');
        Route::put('saved-routes/{saved_route}', [SavedRouteController::class, 'update'])->name('saved-routes.update');
        Route::delete('saved-routes/{saved_route}', [SavedRouteController::class, 'destroy'])->name('saved-routes.destroy');

        Route::resource('golden-labels', GoldenLabelController::class)->except(['show']);
        Route::get('golden-days', [GoldenDayController::class, 'index'])->name('golden-days.index');
        Route::get('golden-days/create', [GoldenDayController::class, 'create'])->name('golden-days.create');
        Route::post('golden-days', [GoldenDayController::class, 'store'])->name('golden-days.store');
        Route::post('golden-days/rebuild', [GoldenDayController::class, 'rebuild'])->name('golden-days.rebuild');
        Route::post('golden-days/translate-ru-batch', [GoldenDayController::class, 'translateRuBatch'])->name('golden-days.translate-ru-batch');
        Route::post('golden-days/sync-hub-labels', [GoldenDayController::class, 'syncHubLabels'])->name('golden-days.sync-hub-labels');
        Route::get('golden-days/reverse-pipeline/status', [GoldenDayController::class, 'reversePipelineStatus'])->name('golden-days.reverse-pipeline.status');
        Route::post('golden-days/reverse-pipeline/start', [GoldenDayController::class, 'reversePipelineStart'])->name('golden-days.reverse-pipeline.start');
        Route::post('golden-days/reverse-pipeline/cancel', [GoldenDayController::class, 'reversePipelineCancel'])->name('golden-days.reverse-pipeline.cancel');
        Route::get('golden-days/{golden_day}', [GoldenDayController::class, 'show'])->name('golden-days.show');
        Route::put('golden-days/{golden_day}', [GoldenDayController::class, 'update'])->name('golden-days.update');
        Route::post('golden-days/{golden_day}/toggle', [GoldenDayController::class, 'toggle'])->name('golden-days.toggle');
        Route::post('golden-days/{golden_day}/translate-ru', [GoldenDayController::class, 'translateRu'])->name('golden-days.translate-ru');
        Route::post('golden-days/{golden_day}/reverse', [GoldenDayController::class, 'reverseOne'])->name('golden-days.reverse');
        Route::post('golden-days/{golden_day}/labels', [GoldenDayController::class, 'syncLabels'])->name('golden-days.sync-labels');
        Route::post('golden-days/{golden_day}/stops', [GoldenDayController::class, 'storeStop'])->name('golden-days.stops.store');
        Route::post('golden-days/{golden_day}/stops/reorder', [GoldenDayController::class, 'reorderStops'])->name('golden-days.stops.reorder');
        Route::delete('golden-days/{golden_day}/stops/{stop}', [GoldenDayController::class, 'destroyStop'])->name('golden-days.stops.destroy');
        Route::post('golden-days/{golden_day}/stops/{stop}/link', [GoldenDayController::class, 'linkStop'])->name('golden-days.stops.link');
        Route::post('golden-days/{golden_day}/stops/{stop}/unlink', [GoldenDayController::class, 'unlinkStop'])->name('golden-days.stops.unlink');

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
