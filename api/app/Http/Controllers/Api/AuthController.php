<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Checked when no account matches, so a missing email takes as long as a wrong password.
    private const DUMMY_HASH = '$2y$12$8LYznvAqS82weYXOcH2sxOqsfYEc2zLpSKgVltXQgVMGFChT.1VOq';

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        return response()->json(['token' => $user->issueToken(), 'user' => $user->toProfile()], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();
        $valid = Hash::check($data['password'], $user?->password ?? self::DUMMY_HASH);

        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        return response()->json(['token' => $user->issueToken(), 'user' => $user->toProfile()]);
    }

    public function demo(): JsonResponse
    {
        $user = config('services.demo.login_enabled') ? User::where('email', User::DEMO_EMAIL)->first() : null;
        abort_unless($user, 404, 'The demo is not available right now.');

        return response()->json(['token' => $user->issueToken(), 'user' => $user->toProfile()]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toProfile()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }
}
