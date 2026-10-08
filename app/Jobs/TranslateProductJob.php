<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductBulkRuTranslator;
use App\Services\ProductPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TranslateProductJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public int $productId,
        public bool $continueSameProduct = true,
    ) {}

    public function handle(ProductBulkRuTranslator $translator, ProductPipelineStore $pipeline): void
    {
        if ($pipeline->translateCancelled()) {
            return;
        }

        $product = Product::query()->find($this->productId);
        if (! $product) {
            $this->dispatchNextProduct($pipeline);

            return;
        }

        $pipeline->setTranslateCurrent($product->id, $product->model);

        try {
            $batch = $translator->translateProductBatch($product, 12);
        } catch (\Throwable $e) {
            $pipeline->recordTranslateError($e->getMessage());
            Log::warning('TranslateProductJob batch failed', [
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);
            $delay = max(3, (int) config('services.gemini.pipeline_delay_seconds', 8));
            self::dispatch($this->productId, true)->delay(now()->addSeconds($delay));

            return;
        }

        $pipeline->updateTranslateBlocks($batch['blocks_done'], $batch['blocks_total']);

        if (! $batch['done'] && $this->continueSameProduct && ! $pipeline->translateCancelled()) {
            $delay = max(3, (int) config('services.gemini.pipeline_delay_seconds', 8));
            self::dispatch($this->productId, true)->delay(now()->addSeconds($delay));

            return;
        }

        if ($batch['done']) {
            $pipeline->recordTranslateProductDone();
        }

        if ($pipeline->translateCancelled()) {
            return;
        }

        $this->dispatchNextProduct($pipeline);
    }

    private function dispatchNextProduct(ProductPipelineStore $pipeline): void
    {
        $nextId = $pipeline->shiftTranslateQueue();
        if ($nextId === null) {
            return;
        }
        $delay = max(3, (int) config('services.gemini.pipeline_delay_seconds', 8));
        self::dispatch($nextId, true)->delay(now()->addSeconds($delay));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('TranslateProductJob failed', [
            'product_id' => $this->productId,
            'message' => $e->getMessage(),
        ]);
        $pipeline = app(ProductPipelineStore::class);
        $pipeline->recordTranslateError($e->getMessage());
        if (! $pipeline->translateCancelled()) {
            $this->dispatchNextProduct($pipeline);
        }
    }
}
