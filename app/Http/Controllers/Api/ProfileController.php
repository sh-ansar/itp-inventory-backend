<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required','string','min:2','max:255']]);
        $user = $request->user();
        $user->name = trim($data['name']);
        if ($user->isDirty()) $user->save();
        return $this->show($request);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect']]);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['New password must differ from the old one']]);
        }
        $user->password = $data['password'];
        $user->save();
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        return response()->json(['ok' => true]);
    }
}
