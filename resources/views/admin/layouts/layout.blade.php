<!doctype html>
<html lang="en" class="layout-menu-fixed layout-compact" data-assets-path="{{ asset('assets/sneat') }}/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'Travel Admin' }}</title>

  <link rel="icon" type="image/x-icon" href="{{ asset('assets/sneat/img/favicon/favicon.ico') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/fonts/iconify-icons.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/css/core.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/css/demo.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/libs/perfect-scrollbar/perfect-scrollbar.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/vendor/tom-select/tom-select.bootstrap5.min.css') }}" />

  <script src="{{ asset('assets/sneat/vendor/js/helpers.js') }}"></script>
  <script src="{{ asset('assets/sneat/js/config.js') }}"></script>
  <style>
    :root {
      --bs-success: #4e9b83;
      --bs-success-rgb: 78, 155, 131;
      --bs-success-text-emphasis: #2f5d4f;
      --bs-success-bg-subtle: #e6f3ef;
      --bs-success-border-subtle: #c5e4d9;
      --bs-success-contrast: #fff;
    }
    .page-toolbar .btn { margin-left: .35rem; }
    /* Table action icons: always visible; color change only on hover (no active/focus "empty" look) */
    .table td .btn.btn-icon {
      user-select: none;
      -webkit-user-select: none;
    }
    .table td .btn.btn-icon.btn-outline-primary,
    .table td .btn.btn-icon.btn-outline-primary:focus,
    .table td .btn.btn-icon.btn-outline-primary:focus-visible,
    .table td .btn.btn-icon.btn-outline-primary:active,
    .table td .btn.btn-icon.btn-outline-primary.active,
    .table td .btn.btn-icon.btn-outline-primary:active:focus {
      color: #696cff !important;
      background-color: transparent !important;
      border-color: #696cff !important;
      box-shadow: none !important;
    }
    .table td .btn.btn-icon.btn-outline-primary:hover {
      color: #fff !important;
      background-color: #696cff !important;
      border-color: #696cff !important;
    }
    .table td .btn.btn-icon.btn-outline-danger,
    .table td .btn.btn-icon.btn-outline-danger:focus,
    .table td .btn.btn-icon.btn-outline-danger:focus-visible,
    .table td .btn.btn-icon.btn-outline-danger:active,
    .table td .btn.btn-icon.btn-outline-danger.active,
    .table td .btn.btn-icon.btn-outline-danger:active:focus {
      color: #ff3e1d !important;
      background-color: transparent !important;
      border-color: #ff3e1d !important;
      box-shadow: none !important;
    }
    .table td .btn.btn-icon.btn-outline-danger:hover {
      color: #fff !important;
      background-color: #ff3e1d !important;
      border-color: #ff3e1d !important;
    }
    .table td.text-nowrap {
      user-select: none;
    }
    .checkbox-select-toggle::after { display: none; }
    .checkbox-select-menu {
      max-height: 16rem;
      overflow-y: auto;
    }
    .checkbox-select-menu .dropdown-item {
      cursor: pointer;
    }
    .checkbox-select-menu .dropdown-item:active,
    .checkbox-select-menu .dropdown-item:hover {
      color: inherit;
      background-color: rgba(105, 108, 255, 0.08);
    }
    .checkbox-select-menu .form-check-input {
      float: none;
    }
    .alert-success {
      --bs-alert-color: #2f5d4f;
      --bs-alert-bg: #e6f3ef;
      --bs-alert-border-color: #c5e4d9;
      --bs-alert-link-color: #2f5d4f;
      color: #2f5d4f;
      background-color: #e6f3ef;
      border-color: #c5e4d9;
    }
    .alert-success .btn-close {
      filter: none;
      opacity: .65;
    }
    .bg-label-success {
      background-color: #e6f3ef !important;
      color: #4e9b83 !important;
    }
    .btn-success {
      --bs-btn-bg: #4e9b83;
      --bs-btn-border-color: #4e9b83;
      --bs-btn-hover-bg: #458a74;
      --bs-btn-hover-border-color: #458a74;
      --bs-btn-active-bg: #3d7a67;
      --bs-btn-active-border-color: #3d7a67;
      --bs-btn-disabled-bg: #4e9b83;
      --bs-btn-disabled-border-color: #4e9b83;
    }
    /* Tom Select → Sneat primary (#696cff) */
    .ts-wrapper.form-select {
      padding: 0;
      border: 0;
      background: none;
      box-shadow: none;
    }
    .ts-wrapper.form-select .ts-control {
      border: 1px solid var(--bs-border-color);
      border-radius: var(--bs-border-radius);
      min-height: calc(1.5em + .875rem + calc(var(--bs-border-width) * 2));
      padding: .4375rem .875rem;
      background-color: var(--bs-paper-bg, #fff);
      box-shadow: none;
    }
    .ts-wrapper.form-select.focus .ts-control,
    .ts-wrapper.form-select.dropdown-active .ts-control {
      border-color: #696cff;
      box-shadow: 0 0 0 .25rem rgba(105, 108, 255, .1);
    }
    .ts-dropdown {
      border-color: var(--bs-border-color);
      border-radius: var(--bs-border-radius);
      box-shadow: 0 .25rem 1rem rgba(161, 172, 184, .45);
      z-index: 1091;
    }
    .ts-dropdown .option {
      padding: .5rem .875rem;
    }
    .ts-dropdown .option.active,
    .ts-dropdown .option.selected,
    .ts-dropdown .option:hover {
      background-color: #696cff !important;
      color: #fff !important;
    }
    .ts-dropdown .option.active::after,
    .ts-dropdown .option.selected::after {
      display: none;
    }
  </style>
  @yield('styles')
  @stack('styles')
</head>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
      <div class="app-brand demo">
        <div class="d-flex flex-column ms-2">
          <a href="{{ route('admin.index') }}" class="app-brand-link">
            <span class="app-brand-text demo menu-text fw-bold">Travel</span>
          </a>
          @php $adminLangCode = \App\Models\Language::admin()?->code ?? 'he'; @endphp
          <div class="d-flex align-items-center gap-1 small mb-1">
            <a href="{{ route('admin.lang', 'he') }}" class="{{ $adminLangCode === 'he' ? 'fw-bold text-primary' : 'text-muted' }}">he</a>
            <span class="text-muted">/</span>
            <a href="{{ route('admin.lang', 'ru') }}" class="{{ $adminLangCode === 'ru' ? 'fw-bold text-primary' : 'text-muted' }}">ru</a>
          </div>
        </div>
        <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
          <i class="bx bx-chevron-left d-block d-xl-none align-middle"></i>
        </a>
      </div>
      <div class="menu-inner-shadow"></div>
      <ul class="menu-inner py-1">
        <li class="menu-item {{ request()->routeIs('admin.index') ? 'active' : '' }}">
          <a href="{{ route('admin.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-home-smile"></i>
            <div>Dashboard</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
          <a href="{{ route('admin.categories.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-folder"></i>
            <div>Regions</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
          <a href="{{ route('admin.products.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-package"></i>
            <div>Products</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.locations.*') ? 'active' : '' }}">
          <a href="{{ route('admin.locations.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-map-pin"></i>
            <div>Locations</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.areas.*') ? 'active' : '' }}">
          <a href="{{ route('admin.areas.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-map-alt"></i>
            <div>Areas</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.route-skeletons.*') ? 'active' : '' }}">
          <a href="{{ route('admin.route-skeletons.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-route"></i>
            <div>Short routes</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.golden-days.*') ? 'active' : '' }}">
          <a href="{{ route('admin.golden-days.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-calendar-star"></i>
            <div>Golden days</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.golden-labels.*') ? 'active' : '' }}">
          <a href="{{ route('admin.golden-labels.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-purchase-tag"></i>
            <div>Golden labels</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.manufacturers.*') ? 'active' : '' }}">
          <a href="{{ route('admin.manufacturers.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-flag"></i>
            <div>Manufacturers</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.attribute-groups.*') || request()->routeIs('admin.attributes.*') ? 'active' : '' }}">
          <a href="{{ route('admin.attributes.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-list-check"></i>
            <div>Attributes</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.languages.*') ? 'active' : '' }}">
          <a href="{{ route('admin.languages.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-globe"></i>
            <div>Languages</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.database.*') ? 'active' : '' }}">
          <a href="{{ route('admin.database.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-data"></i>
            <div>Database</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.test.*') ? 'active open' : '' }}">
          <a href="javascript:void(0);" class="menu-link menu-toggle">
            <i class="menu-icon tf-icons bx bx-flask"></i>
            <div>Test</div>
          </a>
          <ul class="menu-sub">
            <li class="menu-item {{ request()->routeIs('admin.test.index') ? 'active' : '' }}">
              <a href="{{ route('admin.test.index') }}" class="menu-link">
                <div>Обзор</div>
              </a>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.test.locations*') ? 'active' : '' }}">
              <a href="{{ route('admin.test.locations') }}" class="menu-link">
                <div>Поиск мест</div>
              </a>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.test.php') && !request()->routeIs('admin.test.php.*') ? 'active' : '' }} {{ request()->routeIs('admin.test.php.*') ? 'active open' : '' }}">
              <a href="javascript:void(0);" class="menu-link menu-toggle">
                <div>PHP</div>
              </a>
              <ul class="menu-sub">
                <li class="menu-item {{ request()->routeIs('admin.test.php') && !request()->routeIs('admin.test.php.*') ? 'active' : '' }}">
                  <a href="{{ route('admin.test.php') }}" class="menu-link"><div>Слоты дней</div></a>
                </li>
                <li class="menu-item {{ request()->routeIs('admin.test.php.map') ? 'active' : '' }}">
                  <a href="{{ route('admin.test.php.map') }}" class="menu-link"><div>День · карта</div></a>
                </li>
                <li class="menu-item {{ request()->routeIs('admin.test.php.gemini') ? 'active' : '' }}">
                  <a href="{{ route('admin.test.php.gemini') }}" class="menu-link"><div>День · Gemini</div></a>
                </li>
              </ul>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.test.scenario*') ? 'active' : '' }}">
              <a href="{{ route('admin.test.scenario') }}" class="menu-link">
                <div>Gemini сценарий</div>
              </a>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.test.chain*') ? 'active' : '' }}">
              <a href="{{ route('admin.test.chain') }}" class="menu-link">
                <div>Gemini — 3 проверки</div>
              </a>
            </li>
          </ul>
        </li>
        @if(auth()->user()?->isAdmin())
        <li class="menu-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
          <a href="{{ route('admin.users.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-user"></i>
            <div>Users</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">
          <a href="{{ route('admin.roles.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-shield"></i>
            <div>Roles</div>
          </a>
        </li>
        @endif
        <li class="menu-item {{ request()->routeIs('admin.help-categories.*') ? 'active' : '' }}">
          <a href="{{ route('admin.help-categories.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-help-circle"></i>
            <div>Help</div>
          </a>
        </li>
      </ul>
    </aside>

    <div class="layout-page">
      <nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme" id="layout-navbar">
        <div class="layout-menu-toggle navbar-nav align-items-xl-center me-4 me-xl-0 d-xl-none">
          <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
            <i class="bx bx-menu bx-md"></i>
          </a>
        </div>
        <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
          <div class="navbar-nav align-items-center me-auto">
            <span class="fw-medium">{{ $pageTitle ?? 'Admin' }}</span>
          </div>
          <ul class="navbar-nav flex-row align-items-center ms-auto">
            <li class="nav-item">
              <form action="{{ route('logout') }}" method="post" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-icon btn-text-secondary rounded-pill" title="Logout">
                  <i class="bx bx-power-off bx-md"></i>
                </button>
              </form>
            </li>
          </ul>
        </div>
      </nav>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">
          @if(session('success'))
            <div class="alert alert-success alert-dismissible" role="alert">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
          @endif
          @if(session('error'))
            <div class="alert alert-danger alert-dismissible" role="alert">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
          @endif
          @if(session('info'))
            <div class="alert alert-info alert-dismissible" role="alert">{{ session('info') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
          @endif
          @yield('content')
        </div>
        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>
  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<script src="{{ asset('assets/sneat/vendor/libs/jquery/jquery.js') }}"></script>
<script src="{{ asset('assets/sneat/vendor/libs/popper/popper.js') }}"></script>
<script src="{{ asset('assets/sneat/vendor/js/bootstrap.js') }}"></script>
<script src="{{ asset('assets/sneat/vendor/libs/perfect-scrollbar/perfect-scrollbar.js') }}"></script>
<script src="{{ asset('assets/sneat/vendor/js/menu.js') }}"></script>
<script src="{{ asset('assets/sneat/js/main.js') }}"></script>
<script src="{{ asset('assets/vendor/tom-select/tom-select.complete.min.js') }}"></script>
<script>
  document.querySelectorAll('select.form-select:not([multiple])').forEach(function (el) {
    if (el.tomselect) return;
    new TomSelect(el, {
      allowEmptyOption: true,
      create: false,
      maxOptions: null,
      controlInput: null,
      render: {
        no_results: function () {
          return '<div class="no-results">No results</div>';
        }
      }
    });
  });
  document.querySelectorAll('[data-checkbox-select]').forEach(function (wrap) {
    var label = wrap.querySelector('[data-checkbox-select-label]');
    var boxes = wrap.querySelectorAll('input[type="checkbox"]');
    function refresh() {
      var n = wrap.querySelectorAll('input[type="checkbox"]:checked').length;
      if (!label) return;
      if (n === 0) label.textContent = 'Select regions';
      else if (n === 1) label.textContent = '1 region selected';
      else label.textContent = n + ' regions selected';
    }
    boxes.forEach(function (box) {
      box.addEventListener('change', refresh);
    });
  });
</script>
@yield('scripts')
@stack('scripts')
</body>
</html>
