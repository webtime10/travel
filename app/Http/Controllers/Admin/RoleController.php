<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $pageTitle = 'Roles';
        $roles = Role::orderBy('id')->paginate(20);

        return view('admin.roles.index', compact('roles', 'pageTitle'));
    }

    public function create()
    {
        return view('admin.roles.create', ['pageTitle' => 'Roles']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'slug' => 'required|string|max:255|unique:roles,slug|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string',
        ]);

        Role::create($validated);

        return redirect()->route('admin.roles.index')->with('success', 'Роль создана');
    }

    public function edit(Role $role)
    {
        return view('admin.roles.edit', ['pageTitle' => 'Roles', 'role' => $role]);
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,'.$role->id,
            'slug' => 'required|string|max:255|unique:roles,slug,'.$role->id.'|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string',
        ]);

        $role->update($validated);

        return redirect()->route('admin.roles.index')->with('success', 'Роль обновлена');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->count() > 0) {
            return redirect()->route('admin.roles.index')->with('error', 'Нельзя удалить роль с пользователями');
        }

        $role->delete();

        return redirect()->route('admin.roles.index')->with('success', 'Роль удалена');
    }
}
