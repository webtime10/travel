@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">Database</h4>
  <div class="page-toolbar d-flex flex-wrap gap-1">
    @if($status['ok'] && empty($status['schema_ready']))
      <form method="post" action="{{ route('admin.database.migrate') }}">
        @csrf
        <button type="submit" class="btn btn-primary" title="Create RAG tables">
          <i class="bx bx-data me-1"></i> Initialize RAG schema
        </button>
      </form>
    @elseif($status['ok'])
      <form method="post" action="{{ route('admin.database.migrate') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary" title="Ensure tables exist">
          <i class="bx bx-refresh me-1"></i> Re-check schema
        </button>
      </form>
    @endif
  </div>
</div>

{{-- Connection status --}}
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="fw-semibold">PostgreSQL RAG</span>
          @if($status['ok'])
            <span class="badge bg-label-success">Connected</span>
          @else
            <span class="badge bg-label-danger">Disconnected</span>
          @endif
          @if(!empty($status['schema_ready']))
            <span class="badge bg-label-primary">Schema ready</span>
          @endif
          @if($status['ok'])
            @if(!empty($status['pgvector']))
              <span class="badge bg-label-success">pgvector</span>
            @else
              <span class="badge bg-label-warning">no pgvector (JSONB embeddings)</span>
            @endif
          @endif
        </div>
        <div class="small text-muted font-monospace">
          {{ $status['username'] ?? '—' }}@{{ $status['host'] ?? '—' }}:{{ $status['port'] ?? '—' }}/{{ $status['database'] ?? '—' }}
        </div>
        @if(!empty($status['version']))
          <div class="small text-muted mt-1">{{ $status['version'] }}</div>
        @endif
        @if(!$status['ok'])
          <div class="text-danger small mt-2">{{ $status['message'] }}</div>
        @endif
      </div>
      <div class="small text-muted" style="max-width:28rem;">
        App data stays in <strong>MySQL</strong> (<code>travel</code>).
        This screen is the <strong>PostgreSQL</strong> database for Hebrew RAG chunks &amp; embeddings.
      </div>
    </div>
  </div>
</div>

@if($status['ok'])
<div class="row g-4">
  {{-- Tables --}}
  <div class="col-12 col-lg-3">
    <div class="card h-100">
      <div class="card-header py-3">
        <span class="fw-semibold">Tables</span>
        <span class="badge bg-label-secondary ms-1">{{ count($status['tables'] ?? []) }}</span>
      </div>
      <div class="list-group list-group-flush" style="max-height:28rem;overflow:auto;">
        @forelse(($status['tables'] ?? []) as $t)
          <a
            href="{{ route('admin.database.index', ['table' => $t]) }}"
            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $table === $t ? 'active' : '' }}"
          >
            <span class="font-monospace small">{{ $t }}</span>
            @if(str_starts_with($t, 'rag_'))
              <span class="badge {{ $table === $t ? 'bg-white text-primary' : 'bg-label-primary' }}">RAG</span>
            @endif
          </a>
        @empty
          <div class="p-3 text-muted small">No tables yet — click <em>Initialize RAG schema</em>.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-9">
    @if($table !== '')
      {{-- Columns --}}
      <div class="card mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Columns · <code>{{ $table }}</code></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Type</th>
                <th>Nullable</th>
                <th>Default</th>
              </tr>
            </thead>
            <tbody>
              @forelse($columns as $col)
                <tr>
                  <td class="text-muted">{{ $col['pos'] ?? '' }}</td>
                  <td class="font-monospace">{{ $col['name'] ?? '' }}</td>
                  <td class="font-monospace small">
                    {{ $col['udt'] ?? $col['data_type'] ?? '' }}
                    @if(!empty($col['max_length']))({{ $col['max_length'] }})@endif
                  </td>
                  <td>{{ ($col['nullable'] ?? '') === 'YES' ? 'YES' : 'NO' }}</td>
                  <td class="small text-truncate" style="max-width:180px" title="{{ $col['default_value'] ?? '' }}">{{ $col['default_value'] ?? '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted">No columns</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

      {{-- Preview --}}
      <div class="card mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="fw-semibold">Data preview</span>
          <span class="text-muted small">
            @if(isset($preview['total']))
              {{ min(50, $preview['total']) }} of {{ $preview['total'] }} rows
            @endif
          </span>
        </div>
        @if(!empty($preview['error']))
          <div class="card-body text-danger">{{ $preview['error'] }}</div>
        @else
          <div class="table-responsive" style="max-height:22rem;">
            <table class="table table-sm table-hover mb-0">
              <thead class="sticky-top bg-white">
                <tr>
                  @foreach(($preview['columns'] ?? []) as $c)
                    <th class="font-monospace small text-nowrap">{{ $c }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                @forelse(($preview['rows'] ?? []) as $row)
                  <tr>
                    @foreach(($preview['columns'] ?? []) as $c)
                      <td class="small text-truncate" style="max-width:220px" dir="auto" title="{{ $row[$c] ?? '' }}">{{ $row[$c] ?? '' }}</td>
                    @endforeach
                  </tr>
                @empty
                  <tr><td colspan="{{ max(1, count($preview['columns'] ?? [])) }}" class="text-center text-muted">Empty table</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        @endif
      </div>
    @else
      <div class="card mb-4">
        <div class="card-body text-muted">
          Select a table on the left to see columns and sample rows.
          @if(empty($status['schema_ready']))
            <br>Or initialize the RAG schema first.
          @endif
        </div>
      </div>
    @endif

    {{-- SQL console --}}
    <div class="card">
      <div class="card-header py-3">
        <span class="fw-semibold">SQL console</span>
        <span class="text-muted small ms-2">PostgreSQL · {{ $status['database'] }}</span>
      </div>
      <div class="card-body">
        <textarea
          id="ragSqlInput"
          class="form-control font-monospace mb-3"
          rows="6"
          placeholder="SELECT * FROM rag_chunks LIMIT 20;"
          spellcheck="false"
        >SELECT table_name, column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public'
ORDER BY table_name, ordinal_position;</textarea>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button type="button" class="btn btn-primary" id="ragSqlRun">
            <i class="bx bx-play me-1"></i> Run
          </button>
          <button type="button" class="btn btn-outline-secondary" id="ragSqlSampleChunks">Sample: rag_chunks</button>
          <button type="button" class="btn btn-outline-secondary" id="ragSqlSampleDocs">Sample: rag_documents</button>
        </div>
        <div id="ragSqlStatus" class="small text-muted mb-2"></div>
        <div class="table-responsive" style="max-height:28rem;">
          <table class="table table-sm table-bordered mb-0" id="ragSqlResult">
            <thead></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
@endif
@endsection

@section('scripts')
<script>
(function () {
  const input = document.getElementById('ragSqlInput');
  const runBtn = document.getElementById('ragSqlRun');
  const status = document.getElementById('ragSqlStatus');
  const table = document.getElementById('ragSqlResult');
  if (!input || !runBtn || !table) return;

  const thead = table.querySelector('thead');
  const tbody = table.querySelector('tbody');
  const csrf = @json(csrf_token());
  const url = @json(route('admin.database.query'));

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  async function runSql() {
    status.textContent = 'Running…';
    status.className = 'small text-muted mb-2';
    thead.innerHTML = '';
    tbody.innerHTML = '';
    runBtn.disabled = true;
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ sql: input.value }),
      });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) {
        status.textContent = data.message || ('HTTP ' + res.status);
        status.className = 'small text-danger mb-2';
        return;
      }
      status.textContent = data.message + (data.affected != null ? (' · affected ' + data.affected) : '');
      status.className = 'small text-success mb-2';
      const cols = data.columns || [];
      if (cols.length) {
        thead.innerHTML = '<tr>' + cols.map(function (c) {
          return '<th class="font-monospace small text-nowrap">' + esc(c) + '</th>';
        }).join('') + '</tr>';
        tbody.innerHTML = (data.rows || []).map(function (row) {
          return '<tr>' + cols.map(function (c) {
            return '<td class="small text-truncate" style="max-width:240px" dir="auto" title="' + esc(row[c]) + '">' + esc(row[c]) + '</td>';
          }).join('') + '</tr>';
        }).join('') || '<tr><td colspan="' + cols.length + '" class="text-muted text-center">No rows</td></tr>';
      }
    } catch (e) {
      status.textContent = e && e.message ? e.message : String(e);
      status.className = 'small text-danger mb-2';
    } finally {
      runBtn.disabled = false;
    }
  }

  runBtn.addEventListener('click', runSql);
  input.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      runSql();
    }
  });

  const btnChunks = document.getElementById('ragSqlSampleChunks');
  const btnDocs = document.getElementById('ragSqlSampleDocs');
  if (btnChunks) {
    btnChunks.addEventListener('click', function () {
      input.value = 'SELECT id, product_id, model, chunk_index, block_type, LEFT(content_he, 120) AS content_he\nFROM rag_chunks\nORDER BY id\nLIMIT 50;';
    });
  }
  if (btnDocs) {
    btnDocs.addEventListener('click', function () {
      input.value = 'SELECT id, product_id, model, source_file, language, title, chunk_count, indexed_at\nFROM rag_documents\nORDER BY id\nLIMIT 50;';
    });
  }
})();
</script>
@endsection
