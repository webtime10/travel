<!DOCTYPE html>
<html lang="{{ $lang === 'ru' ? 'ru' : 'he' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FT Short #{{ $item->id }} · {{ strtoupper($lang) }}</title>
  <style>
    /* Свой минимальный CSS — без Sneat/Bootstrap flex, из‑за них html2canvas режет левый край */
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      background: #fff;
      color: #222;
      font: 14px/1.45 "Segoe UI", "Arial", "Noto Sans", "Noto Sans Hebrew", sans-serif;
    }
    #ft-pdf-status {
      position: sticky;
      top: 0;
      z-index: 5;
      margin: 0;
      padding: 10px 16px;
      background: #e7f3ff;
      border-bottom: 1px solid #cfe2ff;
      font-size: 14px;
    }
    #ft-pdf-status.is-ok { background: #e8f8ee; border-color: #b7e4c7; }
    #ft-pdf-status.is-err { background: #fdecea; border-color: #f5c2c0; }
    #ft-pdf-actions {
      padding: 10px 16px;
      border-bottom: 1px solid #eee;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    #ft-pdf-actions button {
      border: 1px solid #d0d5dd;
      background: #fff;
      border-radius: 6px;
      padding: 6px 12px;
      cursor: pointer;
      font-size: 13px;
    }
    #ft-pdf-actions button.primary {
      background: #696cff;
      border-color: #696cff;
      color: #fff;
    }
    #ft-pdf-root {
      width: 700px;
      max-width: 700px;
      margin: 0;
      padding: 20px 24px 40px;
      background: #fff;
      overflow: visible;
    }
    .ft-short-doc { width: 100%; overflow: visible; }
    .ft-short-doc[dir="rtl"] { direction: rtl; text-align: right; }
    .ft-short-doc[dir="ltr"] { direction: ltr; text-align: left; }

    .pdf-h1 { font-size: 22px; margin: 0 0 6px; font-weight: 700; }
    .pdf-meta { color: #6b7280; font-size: 12px; margin-bottom: 16px; }
    .pdf-card {
      border: 1px solid #d9dee3;
      border-radius: 8px;
      margin: 0 0 14px;
      overflow: visible;
      break-inside: avoid;
      page-break-inside: avoid;
    }
    .pdf-card-h {
      padding: 8px 12px;
      border-bottom: 1px solid #eef0f2;
      background: #f8f9fa;
      font-weight: 600;
      font-size: 13px;
    }
    .pdf-card-b { padding: 10px 12px; font-size: 13px; }
    .pdf-box {
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      padding: 8px 10px;
      margin: 0 0 8px;
      break-inside: avoid;
      page-break-inside: avoid;
    }
    .pdf-box.primary { background: rgba(105,108,255,.12); border-color: transparent; }
    .pdf-box.warn { background: rgba(255,171,0,.14); border-color: transparent; }
    .pdf-box.info { background: rgba(3,195,236,.12); border-color: transparent; }
    .pdf-label { font-weight: 700; }
    .pdf-section { font-weight: 700; margin: 12px 0 8px; }
    .pdf-day {
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      padding: 8px 10px;
      margin: 0 0 10px;
      break-inside: avoid;
      page-break-inside: avoid;
    }
    .pdf-muted { color: #6b7280; }
    .pdf-badge {
      display: inline-block;
      background: #696cff;
      color: #fff;
      border-radius: 999px;
      padding: 2px 8px;
      font-size: 11px;
      margin-inline-start: 6px;
    }
    .pdf-pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
      font: inherit;
      background: #f8f9fa;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      padding: 10px;
    }
    table.pdf-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 12px;
    }
    table.pdf-table th,
    table.pdf-table td {
      border: 1px solid #e5e7eb;
      padding: 6px 8px;
      vertical-align: top;
      word-break: break-word;
      overflow-wrap: anywhere;
    }
    table.pdf-table th { background: #f8f9fa; text-align: start; }
    ul, ol { margin: 6px 0 8px; padding-inline-start: 1.2rem; }
    hr { border: 0; border-top: 1px solid #e5e7eb; margin: 12px 0; }

    @media print {
      #ft-pdf-status, #ft-pdf-actions { display: none !important; }
      #ft-pdf-root { width: auto; max-width: none; padding: 0; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
  <div id="ft-pdf-status">{{ __('ft_short.pdf_preparing') }}</div>
  <div id="ft-pdf-actions">
    <button type="button" class="primary" id="ft-pdf-download">Скачать PDF</button>
    <button type="button" id="ft-pdf-print">Печать / Save as PDF</button>
  </div>
  <div id="ft-pdf-root">
    @include('admin.ft_short_routes._document_pdf', ['item' => $item, 'lang' => $lang])
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script>
  (function () {
    const root = document.getElementById('ft-pdf-root');
    const status = document.getElementById('ft-pdf-status');
    const btnDl = document.getElementById('ft-pdf-download');
    const btnPrint = document.getElementById('ft-pdf-print');
    const fileName = @json($fileName);
    const okMsg = @json(__('ft_short.pdf_done'));
    const errMsg = @json(__('ft_short.pdf_fail'));

    function setStatus(text, kind) {
      if (!status) return;
      status.className = kind === 'ok' ? 'is-ok' : (kind === 'err' ? 'is-err' : '');
      status.textContent = text;
    }

    function buildPdf() {
      if (!root || typeof html2pdf !== 'function') {
        setStatus(errMsg, 'err');
        return Promise.reject(new Error('html2pdf missing'));
      }

      // Важно: фиксированная ширина + LTR-контейнер захвата (даже для HE),
      // иначе html2canvas часто срезает левый край.
      root.style.width = '700px';
      root.style.maxWidth = '700px';
      root.style.margin = '0';
      root.style.padding = '20px 24px 40px';

      const opt = {
        margin: [10, 10, 12, 10],
        filename: fileName,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
          scale: 2,
          useCORS: true,
          logging: false,
          backgroundColor: '#ffffff',
          width: 700,
          windowWidth: 700,
          scrollX: 0,
          scrollY: 0,
          x: 0,
          y: 0,
          letterRendering: true,
          onclone: function (doc) {
            const cloneRoot = doc.getElementById('ft-pdf-root');
            if (!cloneRoot) return;
            cloneRoot.style.width = '700px';
            cloneRoot.style.maxWidth = '700px';
            cloneRoot.style.margin = '0';
            cloneRoot.style.padding = '20px 24px 40px';
            cloneRoot.style.overflow = 'visible';
            cloneRoot.style.position = 'static';
            // Направление только у внутреннего .ft-short-doc, не у html/body
            doc.documentElement.removeAttribute('dir');
            doc.body.style.direction = 'ltr';
            doc.body.style.margin = '0';
            doc.body.style.padding = '0';
          },
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'legacy'] },
      };

      return html2pdf().set(opt).from(root).save();
    }

    function runDownload() {
      setStatus(@json(__('ft_short.pdf_preparing')), '');
      const ready = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
      return ready.then(function () {
        return new Promise(function (resolve) { setTimeout(resolve, 200); });
      }).then(buildPdf).then(function () {
        setStatus(okMsg, 'ok');
      }).catch(function () {
        setStatus(errMsg + ' — можно через «Печать / Save as PDF»', 'err');
      });
    }

    if (btnDl) btnDl.addEventListener('click', runDownload);
    if (btnPrint) btnPrint.addEventListener('click', function () { window.print(); });

    // Автоскачивание один раз при открытии
    runDownload();
  })();
  </script>
</body>
</html>
