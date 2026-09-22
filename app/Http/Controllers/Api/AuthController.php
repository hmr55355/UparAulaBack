<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
        ]);

        $token = $user->createToken('uparaula')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $login = trim($request->email);
        $user = str_contains($login, '@')
            ? User::where('email', $login)->first()
            : User::where('username', mb_strtolower($login))->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        // Un monitor desactivado por su docente ya no puede entrar.
        if ($user->isMonitor() && ! $user->courseMonitors()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Tu usuario de monitor está desactivado. Habla con tu docente.'],
            ]);
        }

        $token = $user->createToken('uparaula')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('institutionTeachers.institution')),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user()->load('institutionTeachers.institution'));
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();

        $user->fill($request->only(['name', 'email', 'phone']));

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store("private/avatars/{$user->id}", 'local');
            $user->avatar = $path;
        }

        $user->save();

        return new UserResource($user);
    }

    public function updateNotificationPreferences(Request $request)
    {
        $validated = $request->validate(['preferences' => ['required', 'array']]);

        $user = $request->user();
        $user->update(['notification_preferences' => $validated['preferences']]);

        return new UserResource($user);
    }

    /**
     * Zona peligrosa (módulo 18): soft-delete de la propia cuenta (User ya usa
     * SoftDeletes) + revoca todos los tokens Sanctum.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Cuenta eliminada.']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Enlace de recuperación enviado.'])
            : response()->json(['message' => 'No pudimos enviar el enlace de recuperación.'], 422);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Contraseña actualizada.'])
            : response()->json(['message' => 'El token de recuperación no es válido.'], 422);
    }
}
