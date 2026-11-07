<?php

namespace App\Services;

use App\Models\NewShortRoute;

/**
 * Reverse engineering New Short: «Маршрут (как есть)» + «Текст Word (простыня)» → JSON.
 * Старый days-каркас в Gemini не отправляем.
 */
class NewShortRouteReverseEngineer
{
    /** Макс. символов full_word_body в SOURCE. */
    private const MAX_BODY_CHARS = 180_000;

    public function __construct(
        private FtShortRouteReverseEngineer $engineer,
    ) {}

    /**
     * @return array{principle: array<string, mixed>, provider: string}
     */
    public function extract(NewShortRoute $route, string $provider = 'gemini'): array
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }

        $routeAsIs = trim((string) ($route->route_text ?? ''));
        $bodyFull = trim((string) ($route->body_text ?? ''));

        if ($routeAsIs === '' && $bodyFull === '') {
            throw new \RuntimeException(
                'Нет SOURCE: пусты «Маршрут (как есть)» и «Текст Word (простыня)». В документ не записано.'
            );
        }

        $bodyTruncated = false;
        $body = $bodyFull;
        if ($body !== '' && mb_strlen($body) > self::MAX_BODY_CHARS) {
            $body = mb_substr($body, 0, self::MAX_BODY_CHARS)."\n…[truncated]";
            $bodyTruncated = true;
        }

        $material = json_encode([
            'new_short_route_id' => (int) $route->id,
            'doc_code' => $route->doc_code,
            'title' => $route->displayTitle(false),
            'route_as_is' => $routeAsIs,
            'full_word_body' => $body,
            'full_word_body_truncated' => $bodyTruncated,
            'output_language' => 'he',
            'note' => 'SOURCE = route_as_is (Маршрут как есть) + full_word_body (простыня Word). days/каркас не передаём.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data = $this->engineer->analyzeMaterial(
            $provider,
            (string) $material,
            [],
            $this->shortQuestionFallback($route)
        );

        if (FtShortRouteReverseEngineer::isHollowPrinciple($data)) {
            throw new \RuntimeException(
                'Модель вернула пустой разбор (без архетипа и дней). В документ не записано.'
            );
        }

        $data['schema_version'] = FtShortRouteReverseEngineer::SCHEMA_VERSION;
        $data['extracted_at'] = now()->toIso8601String();
        $data['output_language'] = 'he';
        $data['re_provider'] = $provider;
        $data['source'] = 'new_short_route_as_is_and_word';
        $data['source_chars'] = [
            'route_as_is' => mb_strlen($routeAsIs),
            'full_word_body' => mb_strlen($bodyFull),
            'full_word_body_sent' => mb_strlen($body),
            'truncated' => $bodyTruncated,
        ];

        $route->reverse_principle = $data;
        $route->reverse_principle_ru = null;
        $route->save();

        return ['principle' => $data, 'provider' => $provider];
    }

    private function shortQuestionFallback(NewShortRoute $route): string
    {
        $title = trim((string) ($route->displayTitle(false) ?: ''));
        $n = (int) ($route->days_count ?: 0);
        $hubs = $title !== '' ? $title : 'איטליה';
        if ($n > 0) {
            return $hubs.', '.$n.' ימים';
        }

        return $hubs;
    }
}
