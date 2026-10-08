<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportDocxFileJob;
use App\Jobs\TranslateProductJob;
use App\Services\DocxLiteralImportService;
use App\Services\ProductBulkRuTranslator;
use App\Services\ProductPipelineStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPipelineController extends Controller
{
    public function status(ProductPipelineStore $pipeline, DocxLiteralImportService $importService): JsonResponse
    {
        $import = $pipeline->importStatus();
        $translate = $pipeline->translateStatus();
        $pendingImport = $importService->pendingCount();

        return response()->json([
            'import' => array_merge($import, [
                'pending_files' => $pendingImport,
            ]),
            'translate' => $translate,
        ]);
    }

    public function startImport(ProductPipelineStore $pipeline, DocxLiteralImportService $importService): JsonResponse
    {
        if ($pipeline->importRunning()) {
            return response()->json(['ok' => false, 'message' => 'Импорт уже выполняется'], 409);
        }

        $pending = $importService->pendingFiles();
        if ($pending === []) {
            return response()->json([
                'ok' => true,
                'message' => 'Нет новых DOCX — всё уже импортировано',
                'started' => 0,
            ]);
        }

        $importService->ensureLanguagesAndCategory();
        $pipeline->beginImport($pending);

        $first = $pipeline->shiftImportQueue();
        if ($first !== null) {
            ImportDocxFileJob::dispatch($first['n'], $first['path']);
        } else {
            $pipeline->finishImportIfEmpty();
        }

        return response()->json([
            'ok' => true,
            'started' => count($pending),
            'message' => 'Импорт запущен ('.count($pending).' файлов). Запустите queue:work',
        ]);
    }

    public function cancelImport(ProductPipelineStore $pipeline): JsonResponse
    {
        $pipeline->cancelImport();

        return response()->json(['ok' => true, 'message' => 'Импорт отменён']);
    }

    public function startTranslate(
        Request $request,
        ProductPipelineStore $pipeline,
        ProductBulkRuTranslator $bulkTranslator,
    ): JsonResponse {
        if ($pipeline->translateRunning()) {
            return response()->json(['ok' => false, 'message' => 'Перевод уже выполняется'], 409);
        }

        $ids = $bulkTranslator->productIdsNeedingTranslation();
        if ($ids === []) {
            return response()->json([
                'ok' => true,
                'message' => 'Нечего переводить — все продукты уже с RU',
                'started' => 0,
            ]);
        }

        $blocksTotal = $bulkTranslator->estimateBlockTotal();
        $pipeline->beginTranslate($ids, $blocksTotal);

        $firstId = $pipeline->shiftTranslateQueue();
        if ($firstId !== null) {
            TranslateProductJob::dispatch($firstId, true);
        }

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'message' => 'Перевод запущен ('.count($ids).' продуктов). Нужен php artisan queue:work',
        ]);
    }

    public function cancelTranslate(ProductPipelineStore $pipeline): JsonResponse
    {
        $pipeline->cancelTranslate();

        return response()->json(['ok' => true, 'message' => 'Перевод отменён']);
    }
}
