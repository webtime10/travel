<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RagDatabaseService;
use Illuminate\Http\Request;

class DatabaseController extends Controller
{
    public function index(Request $request, RagDatabaseService $rag)
    {
        $pageTitle = 'Database';
        $status = $rag->connectionStatus();
        $table = trim((string) $request->query('table', ''));
        $columns = [];
        $preview = null;

        if ($status['ok'] && $table !== '' && in_array($table, $status['tables'] ?? [], true)) {
            try {
                $columns = $rag->tableColumns($table);
                $preview = $rag->previewTable($table, 50, 0);
            } catch (\Throwable $e) {
                $columns = [];
                $preview = ['error' => $e->getMessage(), 'columns' => [], 'rows' => [], 'total' => 0];
            }
        } else {
            $table = '';
        }

        return view('admin.database.index', compact(
            'pageTitle',
            'status',
            'table',
            'columns',
            'preview'
        ));
    }

    public function migrate(RagDatabaseService $rag)
    {
        if (! ($rag->connectionStatus()['ok'] ?? false)) {
            return redirect()->route('admin.database.index')
                ->with('error', 'PostgreSQL RAG is not connected');
        }

        try {
            $result = $rag->migrateSchema();
        } catch (\Throwable $e) {
            return redirect()->route('admin.database.index')
                ->with('error', $e->getMessage());
        }

        return redirect()->route('admin.database.index')
            ->with('success', $result['message']);
    }

    public function query(Request $request, RagDatabaseService $rag)
    {
        $request->validate([
            'sql' => 'required|string|max:20000',
        ]);

        if (! ($rag->connectionStatus()['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Not connected'], 503);
        }

        $result = $rag->runSql((string) $request->input('sql'), 200);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
