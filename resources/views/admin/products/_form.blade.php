@php
  $item = $product ?? null;
  $selectedCats = array_map('intval', (array) old('category_ids', $item?->categories?->pluck('id')->all() ?? []));
  $selectedCount = count($selectedCats);
  $meta = $item?->meta;
  $blocks = is_array($meta) ? ($meta['blocks'] ?? []) : [];
  $stats = is_array($meta) ? ($meta['stats'] ?? []) : [];
  $metaPhotos = is_array($meta) ? ($meta['images'] ?? []) : [];
  $isSequential = is_array($meta) && ($meta['format'] ?? '') === 'docx_sequential_v1';
  $previewLimit = 200;
  $previewBlocks = $isSequential ? array_slice($blocks, 0, $previewLimit) : [];
  $translatedRu = (int) ($stats['translated_ru'] ?? 0);
  $blocksTotal = $isSequential ? count($blocks) : 0;
  $translatableTotal = 0;
  $translatedRuCounted = 0;
  if ($isSequential) {
    foreach ($blocks as $b) {
      if (! is_array($b) || ($b['type'] ?? '') === 'image') {
        continue;
      }
      if (trim((string) ($b['text'] ?? '')) === '') {
        continue;
      }
      $translatableTotal++;
      if (trim((string) ($b['text_ru'] ?? '')) !== '') {
        $translatedRuCounted++;
      }
    }
    if ($translatedRuCounted > $translatedRu) {
      $translatedRu = $translatedRuCounted;
    }
  }
@endphp

@if($languages->isEmpty())
  <p class="text-muted">Add <a href="{{ route('admin.languages.index') }}">languages</a> first.</p>
@else
<ul class="nav nav-tabs mb-3" role="tablist">
  @foreach($languages as $index => $language)
    <li class="nav-item">
      <button type="button" class="nav-link @if($index === 0) active @endif" data-bs-toggle="tab" data-bs-target="#ppane-{{ $language->code }}" role="tab">{{ strtolower($language->code) }}</button>
    </li>
  @endforeach
</ul>
<div class="tab-content p-0 mb-4">
  @foreach($languages as $index => $language)
    @php
      $c = $language->code;
      $desc = $item?->descriptions?->firstWhere('language_id', $language->id);
      $isRu = strtolower($c) === 'ru';
    @endphp
    <div class="tab-pane fade @if($index === 0) show active @endif" id="ppane-{{ $c }}" role="tabpanel">
      <div class="mb-3">
        <label class="form-label">Name @if($language->is_default)<span class="text-danger">*</span>@endif</label>
        <input type="text" name="name_{{ $c }}" class="form-control" value="{{ old('name_'.$c, $desc->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
      </div>
      <div class="mb-3">
        <label class="form-label">Description</label>
        <textarea name="description_{{ $c }}" class="form-control" rows="4">{{ old('description_'.$c, $desc->description ?? '') }}</textarea>
      </div>

      @if($isSequential)
        <hr class="my-3">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <h6 class="mb-0">DOCX data ({{ strtoupper($c) }})</h6>
          <div class="d-flex align-items-center gap-1">
            <button
              type="button"
              class="btn btn-icon btn-outline-dark docx-json-btn"
              title="Показать JSON"
              data-url="{{ route('admin.products.docx-json', $item->id) }}"
            >
              <span class="d-inline-flex align-items-center justify-content-center" style="width:1.5rem;height:1.5rem;font-weight:700;font-size:.75rem;line-height:1;font-family:ui-monospace,monospace;" aria-hidden="true">{ }</span>
            </button>
            @if(!$isRu)
              <button
                type="button"
                class="btn btn-icon btn-outline-secondary docx-verify-btn"
                title="Сверить Word ↔ JSON"
                data-url="{{ route('admin.products.verify-docx', $item->id) }}"
                data-csrf="{{ csrf_token() }}"
              >
                <i class="bx bx-check-shield bx-md"></i>
              </button>
            @endif
            @if($isRu)
              <button
                type="button"
                class="btn btn-icon btn-outline-primary docx-translate-ru-btn"
                title="Перевести на русский"
                data-url="{{ route('admin.products.translate-ru', $item->id) }}"
                data-csrf="{{ csrf_token() }}"
                data-already="{{ $translatedRu }}"
                data-total="{{ $translatableTotal }}"
              >
                <span class="d-inline-flex align-items-center justify-content-center" style="width:1.5rem;height:1.5rem;font-weight:700;font-size:.85rem;line-height:1;" aria-hidden="true">A文</span>
              </button>
            @endif
          </div>
        </div>
        <p class="mb-1 small text-muted">
          Source: {{ $meta['source_file'] ?? '—' }}
          · blocks {{ $stats['blocks'] ?? count($blocks) }}
          @if($isRu)
            · RU {{ $translatedRu }}
          @endif
          @if(!empty($meta['coverage_report']['coverage_percent']))
            · coverage {{ $meta['coverage_report']['coverage_percent'] }}%
          @endif
        </p>

        <div class="docx-json-panel border rounded p-3 mb-3" style="display:none;">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <div class="fw-semibold">JSON</div>
            <div class="d-flex align-items-center gap-1">
              <button type="button" class="btn btn-sm btn-outline-secondary docx-json-copy">Copy</button>
              <button type="button" class="btn btn-sm btn-outline-secondary docx-json-close">Закрыть</button>
            </div>
          </div>
          <pre class="docx-json-pre mb-0 small" style="max-height:28rem;overflow:auto;white-space:pre;direction:ltr;text-align:left;background:#f8f9fa;padding:0.75rem;border-radius:0.375rem;"></pre>
        </div>

        @if(!$isRu)
          <div class="docx-verify-report border rounded p-3 mb-3" style="display:none;">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
              <div class="fw-semibold docx-verify-title">Сверка Word ↔ JSON</div>
              <div class="fw-bold docx-verify-pct">—</div>
            </div>
            <div class="progress mb-2" style="height:0.75rem;display:none;">
              <div class="progress-bar progress-bar-striped progress-bar-animated docx-verify-bar" style="width:100%"></div>
            </div>
            <div class="small mb-2 docx-verify-status text-muted">Нажмите щит для проверки…</div>
            <div class="docx-verify-body" style="display:none;"></div>
          </div>
        @endif

        @if($isRu)
          <div class="docx-translate-ru-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
              <div class="fw-semibold docx-translate-ru-label">Перевод на русский…</div>
              <div class="fw-bold docx-translate-ru-pct">0%</div>
            </div>
            <div class="progress mb-2" style="height:1rem;">
              <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary docx-translate-ru-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="small docx-translate-ru-status">Подготовка…</div>
          </div>
        @endif

        <div
          class="border rounded p-3 mb-0 docx-blocks-box"
          style="max-height:28rem;overflow:auto;"
          @if(!$isRu) dir="rtl" @endif
          data-lang="{{ $isRu ? 'ru' : 'he' }}"
          data-offset="{{ min($previewLimit, $blocksTotal) }}"
          data-total="{{ $blocksTotal }}"
          data-limit="{{ $previewLimit }}"
          data-url="{{ route('admin.products.docx-blocks', $item->id) }}"
        >
          <div class="docx-blocks-list">
          @if($isRu && $translatedRu === 0)
            <p class="text-muted mb-0 docx-blocks-empty">Русская вкладка пустая. Нажмите A文 — появится тот же JSON на русском.</p>
          @else
            @foreach($previewBlocks as $i => $block)
              @if(($block['type'] ?? '') === 'image')
                <div class="mb-2 docx-block-item">
                  <span class="badge bg-label-info">#{{ $i + 1 }} image</span>
                  <div>
                    <img src="{{ asset($block['src']) }}" alt="" style="max-width:100%;max-height:180px;object-fit:contain;border-radius:6px;">
                  </div>
                  <div class="text-muted small">{{ $block['src'] ?? '' }}</div>
                </div>
              @else
                @php
                  $line = $isRu
                    ? trim((string) ($block['text_ru'] ?? ''))
                    : (string) ($block['text'] ?? '');
                @endphp
                <div class="mb-2 docx-block-item">
                  <span class="badge bg-label-secondary">#{{ $i + 1 }} {{ $block['type'] ?? 'text' }}</span>
                  @if(!empty($block['style']))
                    <span class="text-muted small">{{ $block['style'] }}</span>
                  @endif
                  @if($isRu && $line === '')
                    <div class="text-muted" style="white-space:pre-wrap;">—</div>
                  @else
                    <div style="white-space:pre-wrap;">{{ $line }}</div>
                  @endif
                  @if(!empty($block['url']))
                    <div class="small mt-1">
                      <a href="{{ $block['url'] }}" target="_blank" rel="noopener noreferrer">{{ $block['url'] }}</a>
                    </div>
                  @endif
                </div>
              @endif
            @endforeach
          @endif
          </div>
          @if(!($isRu && $translatedRu === 0) && $blocksTotal > $previewLimit)
            <div class="text-center mt-3 docx-blocks-more-wrap">
              <button type="button" class="btn btn-sm btn-outline-primary docx-blocks-more-btn">
                Ещё <span class="docx-blocks-more-count">{{ min($previewLimit, $blocksTotal - $previewLimit) }}</span>
                <span class="text-muted">· осталось <span class="docx-blocks-remaining">{{ $blocksTotal - $previewLimit }}</span></span>
              </button>
            </div>
          @endif
        </div>
      @endif
    </div>
  @endforeach
</div>
@endif

<div class="mb-3">
  <label class="form-label">Categories</label>
  @if(empty($categories))
    <p class="text-muted mb-0">No categories yet. <a href="{{ route('admin.categories.create') }}">Add category</a></p>
  @else
    <div class="dropdown checkbox-select" data-checkbox-select>
      <button class="form-select text-start checkbox-select-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <span data-checkbox-select-label>
          @if($selectedCount === 0)
            Select categories
          @elseif($selectedCount === 1)
            1 category selected
          @else
            {{ $selectedCount }} categories selected
          @endif
        </span>
      </button>
      <div class="dropdown-menu w-100 py-2 checkbox-select-menu">
        @foreach($categories as $opt)
          <label class="dropdown-item d-flex align-items-center gap-2 mb-0" for="pcat-{{ $opt['id'] }}">
            <input
              class="form-check-input m-0"
              type="checkbox"
              name="category_ids[]"
              value="{{ $opt['id'] }}"
              id="pcat-{{ $opt['id'] }}"
              {{ in_array((int) $opt['id'], $selectedCats, true) ? 'checked' : '' }}
            >
            <span>{{ $opt['label'] }}</span>
          </label>
        @endforeach
      </div>
    </div>
  @endif
</div>
<div class="mb-3">
  <label class="form-label">Sort order</label>
  <input type="number" name="sort_order" class="form-control" style="max-width:12rem" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
</div>
<input type="hidden" name="status" value="0">
<div class="form-check mb-3">
  <input class="form-check-input" type="checkbox" name="status" value="1" id="pstatus" {{ old('status', $item->status ?? true) ? 'checked' : '' }}>
  <label class="form-check-label" for="pstatus">Status</label>
</div>

@if($isSequential)
  <script>
    (function () {
      function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, function (ch) {
          return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]);
        });
      }

      document.querySelectorAll('.docx-json-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
          const pane = btn.closest('.tab-pane') || document;
          const panel = pane.querySelector('.docx-json-panel');
          const pre = pane.querySelector('.docx-json-pre');
          if (!panel || !pre) return;

          if (panel.style.display !== 'none' && pre.dataset.loaded === '1') {
            panel.style.display = 'none';
            return;
          }

          panel.style.display = 'block';
          pre.textContent = 'Загрузка…';
          pre.dataset.loaded = '0';
          try {
            const res = await fetch(btn.dataset.url, {
              headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (!res.ok) {
              throw new Error((data && data.message) || ('HTTP ' + res.status));
            }
            pre.textContent = JSON.stringify(data, null, 2);
            pre.dataset.loaded = '1';
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          } catch (e) {
            pre.textContent = 'Ошибка: ' + (e && e.message ? e.message : e);
          }
        });
      });

      document.querySelectorAll('.docx-json-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const panel = btn.closest('.docx-json-panel');
          if (panel) panel.style.display = 'none';
        });
      });

      document.querySelectorAll('.docx-json-copy').forEach(function (btn) {
        btn.addEventListener('click', async function () {
          const panel = btn.closest('.docx-json-panel');
          const pre = panel ? panel.querySelector('.docx-json-pre') : null;
          if (!pre || !pre.textContent) return;
          try {
            await navigator.clipboard.writeText(pre.textContent);
            const prev = btn.textContent;
            btn.textContent = 'OK';
            setTimeout(function () { btn.textContent = prev; }, 1200);
          } catch (e) {
            alert('Не удалось скопировать');
          }
        });
      });

      document.querySelectorAll('.docx-verify-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
          const pane = btn.closest('.tab-pane') || document;
          const report = pane.querySelector('.docx-verify-report');
          if (!report) return;
          const title = report.querySelector('.docx-verify-title');
          const pct = report.querySelector('.docx-verify-pct');
          const status = report.querySelector('.docx-verify-status');
          const body = report.querySelector('.docx-verify-body');
          const barWrap = report.querySelector('.progress');
          report.style.display = 'block';
          report.classList.remove('bg-label-success', 'bg-label-warning', 'bg-label-danger');
          report.classList.add('bg-label-secondary');
          if (body) { body.style.display = 'none'; body.innerHTML = ''; }
          if (barWrap) barWrap.style.display = 'block';
          if (title) title.textContent = 'Сверка Word ↔ JSON…';
          if (pct) pct.textContent = '…';
          if (status) status.textContent = 'Читаю DOCX и сравниваю с JSON…';
          btn.disabled = true;
          report.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

          try {
            const res = await fetch(btn.dataset.url, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': btn.dataset.csrf,
                'X-Requested-With': 'XMLHttpRequest',
              },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
              throw new Error(data.message || ('HTTP ' + res.status));
            }
            if (barWrap) barWrap.style.display = 'none';
            const cov = Number(data.coverage_percent || 0);
            if (pct) pct.textContent = cov + '%';
            if (title) title.textContent = 'Сверка готова';
            report.classList.remove('bg-label-secondary');
            report.classList.add(cov >= 97 ? 'bg-label-success' : (cov >= 90 ? 'bg-label-warning' : 'bg-label-danger'));
            if (status) {
              status.textContent = (data.verdict || '')
                + ' · Word абзацев: ' + (data.word_text_units || 0)
                + ' · JSON текстов: ' + (data.json_text_blocks || 0)
                + ' · картинки Word/JSON: ' + (data.word_images || 0) + '/' + (data.json_image_blocks || 0)
                + (data.missing_count ? (' · упущено: ' + data.missing_count) : '');
            }
            if (body) {
              if (data.missing && data.missing.length) {
                body.style.display = 'block';
                body.innerHTML = '<div class="fw-semibold mb-1">Упущено / не найдено в JSON:</div><ol class="mb-0 ps-3">'
                  + data.missing.map(function (m) {
                    return '<li class="mb-1" style="white-space:pre-wrap;dir:auto">' + esc(m) + '</li>';
                  }).join('')
                  + '</ol>'
                  + (data.missing_truncated ? '<div class="text-muted small mt-2">Показаны первые 80…</div>' : '');
              } else {
                body.style.display = 'block';
                body.innerHTML = '<div class="text-success">Критических упущений не найдено.</div>';
              }
            }
          } catch (e) {
            if (barWrap) barWrap.style.display = 'none';
            report.classList.remove('bg-label-secondary');
            report.classList.add('bg-label-danger');
            if (title) title.textContent = 'Ошибка сверки';
            if (pct) pct.textContent = '—';
            if (status) status.textContent = (e && e.message) ? e.message : String(e);
          } finally {
            btn.disabled = false;
          }
        });
      });

      function renderItem(item) {
        if (item.type === 'image') {
          const src = item.src || '';
          const raw = item.src_raw || '';
          return '<div class="mb-2 docx-block-item">'
            + '<span class="badge bg-label-info">#' + item.index + ' image</span>'
            + (src ? '<div><img src="' + esc(src) + '" alt="" style="max-width:100%;max-height:180px;object-fit:contain;border-radius:6px;"></div>' : '')
            + (raw ? '<div class="text-muted small">' + esc(raw) + '</div>' : '')
            + '</div>';
        }
        const style = item.style ? '<span class="text-muted small"> ' + esc(item.style) + '</span>' : '';
        const body = item.empty_ru
          ? '<div class="text-muted" style="white-space:pre-wrap;">—</div>'
          : '<div style="white-space:pre-wrap;">' + esc(item.text) + '</div>';
        const link = item.url
          ? '<div class="small mt-1"><a href="' + esc(item.url) + '" target="_blank" rel="noopener noreferrer">' + esc(item.url) + '</a></div>'
          : '';
        return '<div class="mb-2 docx-block-item">'
          + '<span class="badge bg-label-secondary">#' + item.index + ' ' + esc(item.type || 'text') + '</span>'
          + style + body + link + '</div>';
      }

      document.querySelectorAll('.docx-blocks-box').forEach(function (box) {
        const list = box.querySelector('.docx-blocks-list');
        const moreBtn = box.querySelector('.docx-blocks-more-btn');
        const moreWrap = box.querySelector('.docx-blocks-more-wrap');
        const moreCount = box.querySelector('.docx-blocks-more-count');
        const remainingEl = box.querySelector('.docx-blocks-remaining');
        if (!list || !moreBtn) return;

        let loading = false;
        moreBtn.addEventListener('click', async function () {
          if (loading) return;
          loading = true;
          moreBtn.disabled = true;
          const prevLabel = moreBtn.innerHTML;
          moreBtn.textContent = 'Загрузка…';
          try {
            const url = new URL(box.dataset.url, window.location.origin);
            url.searchParams.set('lang', box.dataset.lang || 'he');
            url.searchParams.set('offset', box.dataset.offset || '0');
            url.searchParams.set('limit', box.dataset.limit || '200');
            const res = await fetch(url.toString(), {
              headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
              throw new Error(data.message || ('HTTP ' + res.status));
            }
            const html = (data.items || []).map(renderItem).join('');
            list.insertAdjacentHTML('beforeend', html);
            box.dataset.offset = String(data.next_offset || 0);
            box.dataset.total = String(data.total || box.dataset.total || 0);
            if (data.has_more) {
              const rem = data.remaining || 0;
              const chunk = Math.min(Number(box.dataset.limit || 200), rem);
              if (moreCount) moreCount.textContent = String(chunk);
              if (remainingEl) remainingEl.textContent = String(rem);
              moreBtn.innerHTML = 'Ещё <span class="docx-blocks-more-count">' + chunk
                + '</span> <span class="text-muted">· осталось <span class="docx-blocks-remaining">'
                + rem + '</span></span>';
              moreBtn.disabled = false;
            } else if (moreWrap) {
              moreWrap.innerHTML = '<div class="text-muted small">Все ' + (data.total || '') + ' блоков загружены</div>';
            }
            // keep scroll near the new content
            moreBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          } catch (e) {
            moreBtn.innerHTML = prevLabel;
            moreBtn.disabled = false;
            alert('Не удалось загрузить: ' + (e && e.message ? e.message : e));
          } finally {
            loading = false;
          }
        });
      });

      const btn = document.querySelector('.docx-translate-ru-btn');
      if (!btn) return;
      const pane = btn.closest('.tab-pane') || document;
      const box = pane.querySelector('.docx-translate-ru-progress');
      const status = pane.querySelector('.docx-translate-ru-status');
      const label = pane.querySelector('.docx-translate-ru-label');
      const pctEl = pane.querySelector('.docx-translate-ru-pct');
      const bar = pane.querySelector('.docx-translate-ru-bar');
      if (!box || !status || !label || !pctEl || !bar) return;

      function setProgress(done, total, detail) {
        const t = Math.max(0, Number(total) || 0);
        const d = Math.max(0, Math.min(t || done, Number(done) || 0));
        const pct = t > 0 ? Math.round((d / t) * 100) : 0;
        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
        pctEl.textContent = pct + '%';
        status.textContent = detail || (d + ' / ' + (t || '?'));
      }

      async function runBatch() {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': btn.dataset.csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ limit: 12 }),
        });
        const data = await res.json().catch(() => ({}));
        // Soft failure: skips saved, progress kept — let the loop decide
        if (data && data.translated_ru != null && data.message && !data.ok && !data.done) {
          data._soft = true;
          return data;
        }
        if (!res.ok || !data.ok) {
          const err = new Error(data.message || ('HTTP ' + res.status));
          err.payload = data;
          throw err;
        }
        return data;
      }

      btn.addEventListener('click', async function () {
        if (btn.disabled) return;
        const seedAlready = Number(btn.dataset.already || 0);
        const seedTotal = Number(btn.dataset.total || 0);
        const resumeHint = seedAlready > 0
          ? ('Продолжить с ' + seedAlready + (seedTotal ? (' / ' + seedTotal) : '') + '? Уже переведённые блоки не трогаем.')
          : 'Перевести DOCX на русский? Вкладка he не изменится, на ru появится русский JSON.';
        if (!confirm(resumeHint)) {
          return;
        }
        btn.disabled = true;
        btn.classList.add('disabled');
        box.style.display = 'block';
        label.textContent = seedAlready > 0 ? 'Продолжаю перевод…' : 'Перевод на русский…';
        bar.classList.add('progress-bar-animated');
        bar.classList.remove('bg-danger');
        setProgress(
          seedAlready,
          seedTotal,
          seedAlready > 0
            ? ('Уже готово ' + seedAlready + (seedTotal ? (' из ' + seedTotal) : '') + ' — беру следующие…')
            : 'Запуск…'
        );
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        try {
          let guard = 0;
          let softStreak = 0;
          let total = seedTotal;
          let doneCount = seedAlready;
          while (guard < 400) {
            guard++;
            const data = await runBatch();
            total = data.total || total;
            doneCount = Math.max(
              doneCount,
              Number(data.translated_ru || 0),
              Number(data.already || 0) + Number(data.translated || 0),
              total > 0 ? total - Number(data.remaining || 0) : 0
            );
            btn.dataset.already = String(doneCount);
            if (total) btn.dataset.total = String(total);
            if (data._soft) {
              softStreak++;
              setProgress(
                doneCount,
                total,
                (data.message || 'Сбой пакета') + ' · уже ' + doneCount + '/' + total + ' — беру следующие…'
              );
              if (softStreak > 10) {
                throw Object.assign(
                  new Error('Слишком много сбоев подряд — нажмите ещё раз, продолжит с ' + doneCount),
                  { payload: data }
                );
              }
              continue;
            }
            softStreak = 0;
            setProgress(
              doneCount,
              total,
              'Готово ' + doneCount + ' из ' + total
                + (data.translated ? (' · сейчас +' + data.translated) : '')
                + (data.remaining ? (' · осталось ' + data.remaining) : '')
            );
            if (data.done) {
              label.textContent = 'Готово';
              bar.classList.remove('progress-bar-animated');
              setProgress(total, total, 'Переведено ' + (data.translated_ru || doneCount) + ' блоков. Обновляю…');
              try { sessionStorage.setItem('travel_open_ru_tab', '1'); } catch (e) {}
              window.location.reload();
              return;
            }
          }
          label.textContent = 'Пауза';
          bar.classList.remove('progress-bar-animated');
          status.textContent = 'Остановлено по лимиту — нажмите ещё раз, продолжит с ' + doneCount + '.';
          btn.disabled = false;
          btn.classList.remove('disabled');
        } catch (e) {
          const payload = e && e.payload ? e.payload : {};
          const keepDone = Math.max(
            seedAlready,
            Number(payload.translated_ru || 0),
            Number(payload.already || 0)
          );
          const keepTotal = Number(payload.total || seedTotal || 0);
          if (keepDone || keepTotal) {
            setProgress(keepDone, keepTotal, (e && e.message ? e.message : String(e))
              + ' · сохранено ' + keepDone + (keepTotal ? (' / ' + keepTotal) : '')
              + ' — нажмите ещё раз, продолжит.');
          } else {
            status.textContent = (e && e.message ? e.message : String(e));
          }
          btn.dataset.already = String(keepDone);
          if (keepTotal) btn.dataset.total = String(keepTotal);
          label.textContent = 'Ошибка (можно продолжить)';
          bar.classList.remove('progress-bar-animated');
          bar.classList.add('bg-danger');
          btn.disabled = false;
          btn.classList.remove('disabled');
        }
      });

      try {
        if (sessionStorage.getItem('travel_open_ru_tab') === '1') {
          sessionStorage.removeItem('travel_open_ru_tab');
          const ruTab = document.querySelector('[data-bs-target="#ppane-ru"]');
          if (ruTab && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(ruTab).show();
          } else if (ruTab) {
            ruTab.click();
          }
        }
      } catch (e) {}
    })();
  </script>
@endif

@if(!empty($meta))
  @if(!$isSequential)
    <hr class="my-4">
    <h6 class="mb-2">DOCX data</h6>
    <p class="mb-1"><strong>Title RU:</strong> {{ $meta['title_ru'] ?? '—' }}</p>
    <p class="mb-1"><strong>Days:</strong> {{ count($meta['days'] ?? []) }} · <strong>Photos:</strong> {{ count($metaPhotos) }}</p>
  @endif
  @if(count($metaPhotos))
    <div class="d-flex flex-wrap gap-2 mt-2 mb-3">
      @foreach(array_slice($metaPhotos, 0, 12) as $photo)
        <img src="{{ asset($photo) }}" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:6px;">
      @endforeach
    </div>
  @endif
  <details>
    <summary class="mb-2">Full JSON</summary>
    <pre class="p-3 bg-lighter rounded" style="max-height:24rem;overflow:auto;white-space:pre-wrap;">{{ json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
  </details>
@endif
