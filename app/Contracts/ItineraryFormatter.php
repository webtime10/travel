<?php

namespace App\Contracts;

interface ItineraryFormatter
{
    /**
     * @param  array<string, mixed>  $tourMeta  Full products.meta / meta_ru (single tour)
     * @param  array<string, mixed>  $brief  Questionnaire / user question context
     * @return array{text: string, structured: array<string, mixed>|null}
     */
    public function format(array $tourMeta, array $brief = [], ?string $systemPrompt = null): array;

    /**
     * Build one itinerary from several full tours (stitch days).
     *
     * @param  list<array{product_id: int, model: string|null, estimated_days: int, full_tour: array<string, mixed>}>  $tours
     * @param  array<string, mixed>  $brief
     * @return array{text: string, structured: array<string, mixed>|null}
     */
    public function formatPool(array $tours, array $brief = [], ?string $systemPrompt = null): array;
}
