<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class AdminLangController extends Controller
{
    public function switch(string $code): RedirectResponse
    {
        if (! in_array($code, ['he', 'ru'], true)) {
            abort(404);
        }
        session(['admin_lang' => $code]);

        return redirect()->back(fallback: route('admin.index'));
    }
}
