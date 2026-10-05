<!doctype html>
<html lang="en" class="layout-wide customizer-hide" data-assets-path="{{ asset('assets/sneat') }}/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <title>Login — Travel Admin</title>
  <link rel="icon" type="image/x-icon" href="{{ asset('assets/sneat/img/favicon/favicon.ico') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/fonts/iconify-icons.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/css/core.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/css/demo.css') }}" />
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/css/pages/page-auth.css') }}" />
  <script src="{{ asset('assets/sneat/vendor/js/helpers.js') }}"></script>
  <script src="{{ asset('assets/sneat/js/config.js') }}"></script>
</head>
<body>
<div class="container-xxl">
  <div class="authentication-wrapper authentication-basic container-p-y">
    <div class="authentication-inner">
      <div class="card px-sm-6 px-0">
        <div class="card-body">
          <div class="app-brand justify-content-center mb-6">
            <span class="app-brand-text demo text-heading fw-bold">Travel Admin</span>
          </div>
          <h4 class="mb-1">Welcome! 👋</h4>
          <p class="mb-6">Sign in to your account</p>

          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif

          <form action="{{ route('login.post') }}" method="POST">
            @csrf
            <div class="mb-6">
              <label for="login" class="form-label">Name</label>
              <input type="text" class="form-control" id="login" name="login" value="{{ old('login') }}" placeholder="admin" autofocus required />
            </div>
            <div class="mb-6 form-password-toggle">
              <label class="form-label" for="password">Password</label>
              <div class="input-group input-group-merge">
                <input type="password" id="password" class="form-control" name="password" placeholder="············" required />
                <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
              </div>
            </div>
            <div class="mb-6">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="remember" name="remember" />
                <label class="form-check-label" for="remember">Remember Me</label>
              </div>
            </div>
            <div class="mb-6">
              <button class="btn btn-primary d-grid w-100" type="submit">Login</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="{{ asset('assets/sneat/vendor/libs/jquery/jquery.js') }}"></script>
<script src="{{ asset('assets/sneat/vendor/libs/popper/popper.js') }}"></script>
<script src="{{ asset('assets/sneat/vendor/js/bootstrap.js') }}"></script>
<script src="{{ asset('assets/sneat/js/main.js') }}"></script>
</body>
</html>
