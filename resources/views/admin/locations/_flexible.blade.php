@php
  $layouts = \App\Models\Location::FLEXIBLE_LAYOUTS;
  $blocks = old('flexible_blocks');
  if (! is_array($blocks)) {
      $blocks = is_array($location->flexible_blocks ?? null) ? $location->flexible_blocks : [];
  }
  $layoutIcons = [
      'text' => 'bx-text',
      'link' => 'bx-link',
      'photo' => 'bx-image',
      'ad' => 'bx-purchase-tag',
      'map_screenshot' => 'bx-map-alt',
  ];
@endphp

<div class="card mt-4" id="flexible-blocks">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h5 class="mb-0">Flexible блоки</h5>
      <div class="dropdown">
        <button
          type="button"
          class="btn btn-outline-primary dropdown-toggle px-3"
          id="flexibleLayoutPick"
          data-bs-toggle="dropdown"
          data-bs-auto-close="true"
          aria-expanded="false"
        >
          <i class="bx bx-list-plus me-1"></i>
          Выберите тип блока
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border" aria-labelledby="flexibleLayoutPick" style="min-width:17rem">
          <li><h6 class="dropdown-header text-uppercase small">Добавить блок</h6></li>
          @foreach($layouts as $slug => $label)
            <li>
              <button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2" data-flexible-add="{{ $slug }}">
                <i class="bx {{ $layoutIcons[$slug] ?? 'bx-plus' }} text-primary fs-5"></i>
                <span class="fw-medium">{{ $label }}</span>
              </button>
            </li>
          @endforeach
        </ul>
      </div>
    </div>
    <p class="text-muted small mb-3">Кнопка «Выберите тип блока» открывает список. После выбора блок добавляется сразу; его можно раскрыть и перетащить за ручку.</p>
    <div id="flexibleBlocksList" class="d-flex flex-column gap-2">
      @foreach($blocks as $i => $block)
        @include('admin.locations._flexible_block', [
          'index' => $i,
          'type' => $block['type'] ?? 'text',
          'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
          'layouts' => $layouts,
        ])
      @endforeach
    </div>
    <div id="flexibleEmpty" class="text-muted small @if(count($blocks)) d-none @endif">Пока нет блоков — откройте список типов справа сверху.</div>
  </div>
</div>

<template id="flexibleBlockTpl-text">
  @include('admin.locations._flexible_block', ['index' => '__I__', 'type' => 'text', 'data' => [], 'layouts' => $layouts])
</template>
<template id="flexibleBlockTpl-link">
  @include('admin.locations._flexible_block', ['index' => '__I__', 'type' => 'link', 'data' => [], 'layouts' => $layouts])
</template>
<template id="flexibleBlockTpl-photo">
  @include('admin.locations._flexible_block', ['index' => '__I__', 'type' => 'photo', 'data' => [], 'layouts' => $layouts])
</template>
<template id="flexibleBlockTpl-ad">
  @include('admin.locations._flexible_block', ['index' => '__I__', 'type' => 'ad', 'data' => [], 'layouts' => $layouts])
</template>
<template id="flexibleBlockTpl-map_screenshot">
  @include('admin.locations._flexible_block', ['index' => '__I__', 'type' => 'map_screenshot', 'data' => [], 'layouts' => $layouts])
</template>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(function () {
  const list = document.getElementById('flexibleBlocksList');
  const empty = document.getElementById('flexibleEmpty');
  const root = document.getElementById('flexible-blocks');
  if (!list || !root) return;

  function reindex() {
    list.querySelectorAll('[data-flexible-block]').forEach(function (el, i) {
      el.querySelectorAll('[name]').forEach(function (input) {
        input.name = input.name.replace(/flexible_blocks\[\d+]|flexible_blocks\[__I__\]/, 'flexible_blocks[' + i + ']');
      });
      const badge = el.querySelector('[data-flexible-index]');
      if (badge) badge.textContent = String(i + 1);
    });
    if (empty) empty.classList.toggle('d-none', list.children.length > 0);
  }

  function addBlock(type) {
    const tpl = document.getElementById('flexibleBlockTpl-' + type);
    if (!tpl) return;
    const i = list.querySelectorAll('[data-flexible-block]').length;
    const html = tpl.innerHTML.replaceAll('__I__', String(i));
    list.insertAdjacentHTML('beforeend', html);
    const el = list.lastElementChild;
    if (el) {
      const body = el.querySelector('.collapse');
      if (body && window.bootstrap) {
        window.bootstrap.Collapse.getOrCreateInstance(body, { toggle: false }).show();
      } else if (body) {
        body.classList.add('show');
      }
    }
    reindex();
  }

  root.addEventListener('click', function (e) {
    const add = e.target.closest('[data-flexible-add]');
    if (add) {
      e.preventDefault();
      addBlock(add.getAttribute('data-flexible-add'));
      return;
    }
    const remove = e.target.closest('[data-flexible-remove]');
    if (remove) {
      remove.closest('[data-flexible-block]')?.remove();
      reindex();
    }
  });

  if (window.Sortable) {
    Sortable.create(list, {
      handle: '[data-flexible-handle]',
      animation: 150,
      onEnd: reindex,
    });
  }
})();
</script>
@endpush
