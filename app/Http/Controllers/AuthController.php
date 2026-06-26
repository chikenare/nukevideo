<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        abort_unless(app(GeneralSettings::class)->registration_enabled, 403, 'Registration is disabled.');

        $this->authService->register($request->validated());
        $request->session()->regenerate();

        return response()->json(['message' => 'Registration successful']);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $this->authService->login($request->validated());

        $request->session()->regenerate();

        return response()->json(['message' => 'Login successful']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
