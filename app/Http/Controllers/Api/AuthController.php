<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registrasi pengguna baru
     *
     * Membuat akun baru. Setiap pengguna dapat berperan sebagai penjual dan/atau penawar.
     *
     * @unauthenticated
     *
     * @bodyParam name string required Nama lengkap pengguna. Example: Budi Santoso
     * @bodyParam email string required Email unik. Example: budi@example.com
     * @bodyParam password string required Minimal 8 karakter. Example: password123
     * @bodyParam password_confirmation string required Harus sama dengan password. Example: password123
     *
     * @response 201 {
     *   "user": {"id": 1, "name": "Budi Santoso", "email": "budi@example.com"},
     *   "token": "1|xxxxxxxxxxxxxxxxxxxx"
     * }
     */
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login
     *
     * @unauthenticated
     *
     * @bodyParam email string required Example: penjual@demo.com
     * @bodyParam password string required Example: password
     *
     * @response 200 {
     *   "user": {"id": 1, "name": "Demo Penjual", "email": "penjual@demo.com"},
     *   "token": "2|xxxxxxxxxxxxxxxxxxxx"
     * }
     */
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout
     *
     * Menghapus token yang sedang digunakan.
     *
     * @response 200 {"message": "Logout berhasil."}
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Data pengguna saat ini
     *
     * Mengambil data pengguna yang sedang login.
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}