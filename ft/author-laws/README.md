# Author Laws (library)

Централизованная полка: **законы авторской ДНК** для Fine-Tune и промптов.

```
ft/author-laws/
  README.md                 ← эта шпаргалка
  interview-source.md       ← сырое интервью (источник)
  system-prompt.md          ← сжатые законы → system instruction модели
  examples/                 ← JSONL-примеры обучения
    01-family-garda.jsonl
```

## Как использовать

| Файл | Роль |
|------|------|
| `interview-source.md` | Архив / справка для разработчика. В Gemini FT целиком не заливать. |
| `system-prompt.md` | Текст системной инструкции (законы). |
| `examples/*.jsonl` | Пары `user` (анкета) → `model` (эталонный JSON тура). |

Жёсткие числа (≤4 ч руля, 2–4 точки, без церквей…) дублируй ещё в **PHP validators** — это не только текст для модели.
