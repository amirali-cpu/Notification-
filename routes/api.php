<?php

use App\Http\Controllers\Api\NotificationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/**
 * Issue a Sanctum token for a valid email/password pair.
 * Returns the plain-text token as the response body.
 */
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $user = User::where('email', $credentials['email'])->first();

    if (! $user || ! Hash::check($credentials['password'], $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    return response($user->createToken('api')->plainTextToken)
        ->header('Content-Type', 'text/plain');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/{notification}/status', [NotificationController::class, 'status']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
});
