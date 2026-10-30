<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class InventorySessionController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = strtolower(trim($credentials['email']));
        $credentials['is_active'] = true;
        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials']);
        }
        $request->session()->regenerate();
        return response()->json(['id' => Auth::id(), 'name' => Auth::user()->name]);
    }

    public function me(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_active === true, 403);
        return response()->json(['id' => $request->user()->id, 'name' => $request->user()->name]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['ok' => true]);
    }
}