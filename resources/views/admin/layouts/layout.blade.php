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
    /* Sneat .table .btn-icon locks color to table text and blocks white icons on hover */
    .table .btn.btn-icon:hover,
    .table .btn.btn-icon:focus,
    .table .btn.btn-icon:focus-visible,
    .table .btn.btn-icon:active {
      color: var(--bs-btn-hover-color);
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
</head>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
      <div class="app-brand demo">
        <a href="{{ route('admin.index') }}" class="app-brand-link">
          <span class="app-brand-text demo menu-text fw-bold ms-2">Travel</span>
        </a>
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
            <div>Categories</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
          <a href="{{ route('admin.products.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-package"></i>
            <div>Products</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.languages.*') ? 'active' : '' }}">
          <a href="{{ route('admin.languages.index') }}" class="menu-link">
            <i class="menu-icon tf-icons bx bx-globe"></i>
            <div>Languages</div>
          </a>
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
      if (n === 0) label.textContent = 'Select categories';
      else if (n === 1) label.textContent = '1 category selected';
      else label.textContent = n + ' categories selected';
    }
    boxes.forEach(function (box) {
      box.addEventListener('change', refresh);
    });
  });
</script>
@yield('scripts')
</body>
</html>
