<link rel="stylesheet" href="{{ asset('assets/vendor/summernote/summernote-bs5.min.css') }}">
<style>
  /* Force icon font from known public path (fixes empty toolbar) */
  @font-face {
    font-family: "summernote";
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url("{{ asset('assets/vendor/summernote/font/summernote.woff2') }}") format("woff2"),
         url("{{ asset('assets/vendor/summernote/font/summernote.woff') }}") format("woff"),
         url("{{ asset('assets/vendor/summernote/font/summernote.ttf') }}") format("truetype");
  }

  .note-editor.note-frame {
    background: #fff;
    border: 1px solid #d9dee3 !important;
    border-radius: 0.375rem;
    box-shadow: none;
  }
  .note-editor .note-toolbar {
    background-color: #f5f5f9 !important;
    border-bottom: 1px solid #d9dee3 !important;
    padding: 8px 10px !important;
    position: relative;
    z-index: 2;
  }
  .note-editor .note-toolbar .note-btn-group {
    margin: 2px 3px !important;
  }
  .note-editor .note-toolbar .note-btn {
    background: #fff !important;
    border: 1px solid #d9dee3 !important;
    color: #566a7f !important;
    padding: 0.35rem 0.55rem !important;
    min-width: 2rem;
    min-height: 2rem;
  }
  .note-editor .note-toolbar .note-btn:hover {
    background: #eef0ff !important;
    color: #696cff !important;
  }
  .note-editor .note-toolbar .note-btn i,
  .note-editor .note-toolbar .note-icon {
    color: inherit;
  }
  .note-editor .note-editing-area .note-editable {
    background: #fff !important;
    padding: 12px 14px !important;
    min-height: 280px !important;
    color: #566a7f;
  }
  .note-editable img {
    max-width: 100%;
    height: auto;
  }
</style>
