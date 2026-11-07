<!DOCTYPE html>
<html lang="{{ $lang === 'ru' ? 'ru' : 'he' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FT Short #{{ $item->id }} · {{ strtoupper($lang) }}</title>
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/css/core.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/sneat/css/demo.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/sneat/vendor/fonts/boxicons.css') }}">
  <style>
    body { background: #f5f5f9; margin: 0; padding: 16px; }
    #ft-pdf-shell { max-width: 920px; margin: 0 auto; }
    #ft-pdf-status { font-size: 14px; margin-bottom: 12px; }
    .bg-label-primary { background: rgba(105,108,255,.16) !important; }
    .bg-label-warning { background: rgba(255,171,0,.16) !important; }
    .bg-label-info { background: rgba(3,195,236,.16) !important; }
    .bg-label-success { background: rgba(113,221,55,.16) !important; }
    pre { font-family: inherit; }
  </style>
</head>
<body>
  <div id="ft-pdf-shell">
    <div id="ft-pdf-status" class="alert alert-info py-2">{{ __('ft_short.pdf_preparing') }}</div>
    <div id="ft-pdf-root" class="container-xxl flex-grow-1 container-p-y bg-white border rounded p-3">
      @include('admin.ft_short_routes._document', ['item' => $item, 'lang' => $lang, 'forPdf' => true])
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script>
  (function () {
    const root = document.getElementById('ft-pdf-root');
    const status = document.getElementById('ft-pdf-status');
    const fileName = @json($fileName);
    const okMsg = @json(__('ft_short.pdf_done'));
    const errMsg = @json(__('ft_short.pdf_fail'));

    function setStatus(text, ok) {
      if (!status) return;
      status.className = 'alert py-2 ' + (ok ? 'alert-success' : 'alert-danger');
      status.textContent = text;
    }

    if (!root || typeof html2pdf !== 'function') {
      setStatus(errMsg, false);
      return;
    }

    const opt = {
      margin: [10, 10, 10, 10],
      filename: fileName,
      image: { type: 'jpeg', quality: 0.95 },
      html2canvas: { scale: 2, useCORS: true, logging: false, windowWidth: 920 },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['avoid-all', 'css', 'legacy'] },
    };

    html2pdf().set(opt).from(root).save().then(function () {
      setStatus(okMsg, true);
    }).catch(function () {
      setStatus(errMsg, false);
    });
  })();
  </script>
</body>
</html>
