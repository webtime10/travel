<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\CategoryVectorPipelineStore;
use App\Services\RagProductIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IndexCategoryProductJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public int $categoryId,
        public int $productId,
    ) {}

    public function handle(RagProductIndexer $indexer, CategoryVectorPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled($this->categoryId)) {
            return;
        }

        $product = Product::query()->find($this->productId);
        if (! $product) {
            $pipeline->recordFailed($this->categoryId, 'Product #'.$this->productId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent($this->categoryId, $product->id, $product->model);

        try {
            $result = $indexer->indexProduct($product, true);
            $pipeline->recordDone($this->categoryId, (int) ($result['chunks'] ?? 0));
        } catch (\Throwable $e) {
            Log::warning('IndexCategoryProductJob failed', [
                'category_id' => $this->categoryId,
                'product_id' => $this->productId,
                'message' => $e->getMessage(),
            ]);
            $pipeline->recordFailed($this->categoryId, $e->getMessage());
        } finally {
            unset($result, $product, $indexer);
            gc_collect_cycles();
        }

        if ($pipeline->cancelled($this->categoryId)) {
            return;
        }

        $this->dispatchNext($pipeline);
    }

    private function dispatchNext(CategoryVectorPipelineStore $pipeline): void
    {
        $nextId = $pipeline->shiftQueue($this->categoryId);
        if ($nextId === null) {
            return;
        }
        $delay = max(2, (int) config('services.gemini.pipeline_delay_seconds', 8));
        self::dispatch($this->categoryId, $nextId)->delay(now()->addSeconds($delay));
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = app(CategoryVectorPipelineStore::class);
        $pipeline->recordFailed($this->categoryId, $e->getMessage());
        if (! $pipeline->cancelled($this->categoryId)) {
            $this->dispatchNext($pipeline);
        }
    }
}
