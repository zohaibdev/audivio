<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password'),
        ]);

        $defaultPlan = Plan::where('is_default', true)->first();

        if ($defaultPlan !== null) {
            Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $defaultPlan->id,
                'started_at' => now(),
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success(['user' => $user, 'token' => $token], status: 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            return ApiResponse::error('Invalid credentials.', 401);
        }

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(message: 'Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user());
    }
}
