<script src="{{ asset('assets/vendor/summernote/summernote-bs5.min.js') }}"></script>
<script>
jQuery(function ($) {
  var uploadUrl = @json(route('admin.help-categories.upload'));
  var csrf = @json(csrf_token());
  var $el = $('#helpCategoryContent');

  if (!$el.length) {
    console.error('[Summernote] #helpCategoryContent not found');
    return;
  }
  if (typeof $.fn.summernote !== 'function') {
    console.error('[Summernote] plugin not loaded from', @json(asset('assets/vendor/summernote/summernote-bs5.min.js')));
    return;
  }

  if ($el.next('.note-editor').length) {
    try { $el.summernote('destroy'); } catch (e) {}
  }

  function uploadImage(file) {
    var data = new FormData();
    data.append('file', file);
    return fetch(uploadUrl, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      },
      body: data,
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok || !json.url) {
          var msg = (json.errors && json.errors.file && json.errors.file[0])
            || json.message
            || ('Upload failed HTTP ' + res.status);
          throw new Error(msg);
        }
        return json.url;
      });
    });
  }

  $el.summernote({
    height: 300,
    focus: false,
    dialogsInBody: true,
    toolbar: [
      ['style', ['bold', 'italic', 'underline']],
      ['font', ['fontname']],
      ['color', ['color']],
      ['para', ['ul', 'ol', 'paragraph']],
      ['table', ['table']],
      ['insert', ['link', 'picture', 'video']],
      ['view', ['codeview', 'help']],
    ],
    fontNames: [
      'Arial', 'Arial Black', 'Comic Sans MS', 'Courier New',
      'Helvetica', 'Impact', 'Tahoma', 'Times New Roman', 'Verdana', 'sans-serif',
    ],
    callbacks: {
      onImageUpload: function (files) {
        Array.prototype.forEach.call(files || [], function (file) {
          uploadImage(file)
            .then(function (url) {
              $el.summernote('insertImage', url, function ($image) {
                $image.css('max-width', '100%');
              });
            })
            .catch(function (err) {
              alert(err.message || String(err));
            });
        });
      },
    },
  });

  // If toolbar still empty, surface a clear error
  setTimeout(function () {
    var $toolbar = $el.next('.note-editor').find('.note-toolbar');
    if (!$toolbar.length) {
      console.error('[Summernote] editor frame not created');
      return;
    }
    console.log('[Summernote] ready, toolbar buttons:', $toolbar.find('.note-btn').length);
  }, 100);
});
</script>
