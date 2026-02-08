<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Notification\Services\AdminNotificationService;
use Exception;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Base\Controller;
use App\Mail\SignupMail;
use App\Models\Common\EmailToken;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Handle user registration.
     */
    public function store(Request $request)
    {
        $this->logRegistrationRequest($request);

        // Check for soft-deleted users
        $restoreResponse = $this->checkSoftDeletedUser($request->email);
        if ($restoreResponse) {
            return $restoreResponse;
        }

        // Validate the request
        $this->validateRegistration($request);

        // Handle avatar upload
        $avatarUrl = $this->handleAvatarUpload($request);

        // Create the user
        $result = $this->createUser($request, $avatarUrl);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        /** @var User $user */
        $user = $result['user'];
        $isStudent = $result['isStudent'];

        // Generate auth token
        $tokenResult = $this->generateAuthToken($user);
        if ($tokenResult instanceof JsonResponse) {
            return $tokenResult;
        }

        // Send welcome email and notify admins
        $this->sendWelcomeEmail($user, $tokenResult);
        AdminNotificationService::notifyAdminOfNewRegistration($user);

        Log::info('User registration completed successfully', ['user_id' => $user->id, 'email' => $user->email]);

        return $this->buildSuccessResponse($user, $tokenResult, $isStudent);
    }

    public function magicLogin(Request $request)
    {
        $tokenParam = $request->query('token');
        if (!$tokenParam) {
            return response()->json(['error' => 'Token missing'], 400);
        }

        $record = EmailToken::where('token', $tokenParam)->first();

        if (!$record) {
            return response()->json(['error' => 'Invalid token'], 400);
        }
        if ($record->used) {
            return response()->json(['error' => 'Token already used'], 400);
        }
        if ($record->expires_at->isPast()) {
            return response()->json(['error' => 'Token expired'], 400);
        }

        $record->update(['used' => true]);

        $user = $record->user;

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Log incoming registration request details.
     */
    private function logRegistrationRequest(Request $request): void
    {
        Log::info('Registration request received', [
            'content_type' => $request->header('Content-Type'),
            'has_files' => !empty($request->allFiles()),
            'all_files' => $request->allFiles(),
            'has_avatar' => $request->hasFile('avatar_url'),
            'request_method' => $request->method(),
            'input_keys' => array_keys($request->all()),
            'all_data' => $request->all(),
        ]);
    }

    /**
     * Check if email belongs to a soft-deleted user and return appropriate response.
     */
    private function checkSoftDeletedUser(string $email): ?JsonResponse
    {
        if (!$this->safeHasColumn('users', 'deleted_at')) {
            return null;
        }

        $softDeletedUser = User::withTrashed()
            ->where('email', strtolower(trim($email)))
            ->whereNotNull('deleted_at')
            ->first()
        ;

        if (!$softDeletedUser) {
            return null;
        }

        $daysSinceDeletion = now()->diffInDays($softDeletedUser->deleted_at);

        if ($daysSinceDeletion <= 30) {
            return response()->json([
                'success' => false,
                'message' => 'Este e-mail está associado a uma conta que foi removida recentemente. Você pode restaurar sua conta em vez de criar uma nova.',
                'can_restore' => true,
                'removed_at' => $softDeletedUser->deleted_at->toISOString(),
                'days_since_deletion' => $daysSinceDeletion,
            ], 422);
        }

        return response()->json([
            'success' => false,
            'message' => 'Este e-mail está associado a uma conta que foi removida há mais de 30 dias. Entre em contato com o suporte para mais informações.',
            'can_restore' => false,
        ], 422);
    }

    /**
     * Validate the registration request.
     */
    private function validateRegistration(Request $request): void
    {
        $request->validate($this->getValidationRules(), $this->getValidationMessages());
        $this->validateCustomRules($request);
        Log::info('Validation passed successfully');
    }

    /**
     * Get validation rules for registration.
     */
    private function getValidationRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[\p{L}\p{M}\s\.\'\-]+$/u'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class, 'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'],
            'password' => ['required', 'confirmed', 'min:8', 'max:128', Rules\Password::defaults()],
            'role' => ['sometimes', 'string', Rule::in(['creator', 'brand', 'admin']), 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[\+]?[1-9][\d]{0,15}$/'],
            'avatar_url' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=1024,max_height=1024'],
            'bio' => ['nullable', 'string', 'max:1000', 'min:10'],
            'company_name' => ['nullable', 'string', 'max:255', 'min:2', 'regex:/^[a-zA-Z0-9\s\-\.&]+$/'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other']), 'max:10'],
            'state' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z\s\-]+$/'],
            'language' => ['nullable', 'string', 'max:10', Rule::in(['en', 'es', 'fr', 'de', 'it', 'pt', 'ru', 'zh', 'ja', 'ko', 'ar'])],
            'isStudent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get validation error messages.
     */
    private function getValidationMessages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres.',
            'name.regex' => 'O nome só pode conter letras, espaços, hífens, pontos e apóstrofos.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um endereço de e-mail válido.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'email.regex' => 'Informe um formato de e-mail válido.',
            'password.required' => 'A senha é obrigatória.',
            'password.confirmed' => 'A confirmação de senha não confere.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.max' => 'A senha não pode ter mais de 128 caracteres.',
            'role.in' => 'O papel selecionado é inválido.',
            'whatsapp.regex' => 'Informe um número de telefone válido.',
            'avatar_url.image' => 'O avatar deve ser um arquivo de imagem.',
            'avatar_url.mimes' => 'O avatar deve ser do tipo: jpeg, png, jpg, gif, webp.',
            'avatar_url.max' => 'O avatar não pode ser maior que 2MB.',
            'avatar_url.dimensions' => 'O avatar deve ter entre 100x100 e 1024x1024 pixels.',
            'bio.min' => 'A bio deve ter pelo menos 10 caracteres.',
            'bio.max' => 'A bio não pode ter mais de 1000 caracteres.',
            'company_name.min' => 'O nome da empresa deve ter pelo menos 2 caracteres.',
            'company_name.regex' => 'O nome da empresa só pode conter letras, números, espaços, hífens, pontos e &.',
            'gender.in' => 'O gênero selecionado é inválido.',
            'state.regex' => 'O estado só pode conter letras, espaços e hífens.',
            'language.in' => 'O idioma selecionado não é suportado.',
            'has_premium.boolean' => 'O status premium deve ser verdadeiro ou falso.',
        ];
    }

    /**
     * Handle avatar file upload if present.
     */
    private function handleAvatarUpload(Request $request): ?string
    {
        if (!$request->hasFile('avatar_url')) {
            Log::info('No avatar file in request');

            return null;
        }

        $file = $request->file('avatar_url');
        Log::info('Avatar file detected', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);

        $avatarUrl = $this->uploadAvatar($file);
        Log::info('Avatar URL generated: ' . $avatarUrl);

        return $avatarUrl;
    }

    /**
     * Create user with the provided request data.
     *
     * @return array|JsonResponse
     */
    private function createUser(Request $request, ?string $avatarUrl)
    {
        $isStudent = $request->isStudent ?? false;
        $freeTrialExpiresAt = $isStudent ? now()->addYear() : null;

        Log::info('About to create user with data', [
            'name' => trim($request->name),
            'email' => strtolower(trim($request->email)),
            'role' => $request->role ?? 'creator',
            'gender' => $request->gender ?? 'other',
            'birth_date' => $request->birth_date ?? null,
        ]);

        $attributes = [
            'name' => trim($request->name),
            'email' => strtolower(trim($request->email)),
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'creator',
            'whatsapp' => $request->whatsapp ? $this->formatPhoneNumber($request->whatsapp) : null,
            'avatar_url' => $avatarUrl,
            'bio' => $request->bio ? trim($request->bio) : null,
            'company_name' => $request->company_name ? trim($request->company_name) : null,
            'student_verified' => false,
            'student_expires_at' => null,
            'gender' => $request->gender ?? 'other',
            'birth_date' => $request->birth_date ?? null,
            'state' => $request->state ? trim($request->state) : null,
            'language' => 'en',
            'has_premium' => false,
            'premium_expires_at' => null,
            'free_trial_expires_at' => $freeTrialExpiresAt,
            'email_verified_at' => now(),
        ];

        $filtered = $this->filterAttributesBySchema($attributes);

        try {
            $user = $this->insertAndRetrieveUser($filtered);
        } catch (Exception $e) {
            Log::error('User creation failed', ['error' => $e->getMessage(), 'filtered' => $filtered]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao criar conta. Tente novamente mais tarde.',
            ], 422);
        }

        if (!$user || !$user->id) {
            Log::error('User instance not available after creation');

            return response()->json([
                'success' => false,
                'message' => 'Falha ao criar conta. Tente novamente mais tarde.',
            ], 422);
        }

        Log::info('User created successfully', ['user_id' => $user->id]);

        return ['user' => $user, 'isStudent' => $isStudent];
    }

    /**
     * Filter attributes to only include existing database columns.
     */
    private function filterAttributesBySchema(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if ($this->safeHasColumn('users', $key)) {
                $filtered[$key] = $value;
            } else {
                Log::warning('Skipping non-existent users column', ['column' => $key]);
            }
        }

        if ($this->safeHasColumn('users', 'created_at')) {
            $filtered['created_at'] = now();
        }
        if ($this->safeHasColumn('users', 'updated_at')) {
            $filtered['updated_at'] = now();
        }

        return $filtered;
    }

    /**
     * Insert user into database and retrieve the model instance.
     */
    private function insertAndRetrieveUser(array $filtered): ?User
    {
        $id = DB::table('users')->insertGetId($filtered);
        $user = User::withoutGlobalScopes()->find($id);

        if (!$user) {
            $row = DB::table('users')->where('id', $id)->first();
            if ($row) {
                $user = new User((array) $row);
                $user->exists = true;
            }
        }

        return $user;
    }

    /**
     * Generate authentication token for user.
     *
     * @return JsonResponse|string
     */
    private function generateAuthToken(User $user)
    {
        try {
            return $user->createToken('auth_token')->plainTextToken;
        } catch (Throwable $e) {
            Log::error('Failed to create auth token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Falha ao criar token de acesso. Tente novamente mais tarde.',
            ], 422);
        }
    }

    /**
     * Send welcome email to the newly registered user.
     */
    private function sendWelcomeEmail(User $user, string $token): void
    {
        $frontend = config('app.frontend_url', env('APP_FRONTEND_URL', 'http://localhost:5000'));
        $link = "{$frontend}/{$user->role}?token={$token}";

        try {
            Mail::to($user->email)->send(new SignupMail($user, $link));
        } catch (Exception $e) {
            Log::error('Error sending signup email: ' . $e->getMessage());
        }
    }

    /**
     * Build the success response for registration.
     */
    private function buildSuccessResponse(User $user, string $token, bool $isStudent): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Registration successful! Your account has been created and Check your email.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'role' => $user->role,
                'whatsapp' => $user->whatsapp,
                'avatar_url' => $user->avatar_url,
                'bio' => $user->bio,
                'company_name' => $user->company_name,
                'student_verified' => $user->student_verified,
                'student_expires_at' => $user->free_trial_expires_at,
                'gender' => $user->gender,
                'state' => $user->state,
                'language' => $user->language,
                'has_premium' => $user->has_premium,
                'premium_expires_at' => $user->premium_expires_at,
                'free_trial_expires_at' => $user->free_trial_expires_at,
                'isStudent' => $isStudent,
            ],
        ], 201);
    }

    private function uploadAvatar($file): string
    {
        try {
            // Use FileUploadHelper for consistent avatar uploads
            $url = \App\Helpers\FileUploadHelper::upload($file, 'avatars');
            
            if (!$url) {
                throw new Exception('Failed to upload avatar via FileUploadHelper');
            }

            return $url;
        } catch (Exception $e) {
            Log::error('Avatar upload failed in RegisteredUserController: ' . $e->getMessage());

            throw $e;
        }
    }

    private function validateCustomRules(Request $request): void
    {
        if ($request->email) {
            $domain = substr(strrchr($request->email, '@'), 1);
            $disallowedDomains = ['tempmail.com', '10minutemail.com', 'guerrillamail.com'];

            if (in_array(strtolower($domain), $disallowedDomains)) {
                throw ValidationException::withMessages([
                    'email' => ['Endereços de e-mail temporários não são permitidos.'],
                ]);
            }
        }

        if ($request->password) {
            $password = $request->password;
            $errors = [];

            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'A senha deve conter ao menos uma letra maiúscula.';
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'A senha deve conter ao menos uma letra minúscula.';
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'A senha deve conter ao menos um número.';
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = 'A senha deve conter ao menos um caractere especial.';
            }

            if (!empty($errors)) {
                throw ValidationException::withMessages([
                    'password' => $errors,
                ]);
            }
        }

        if ($request->whatsapp) {
            $phone = $this->formatPhoneNumber($request->whatsapp);
            if (strlen($phone) < 10 || strlen($phone) > 15) {
                throw ValidationException::withMessages([
                    'whatsapp' => ['O número de telefone deve ter entre 10 e 15 dígitos.'],
                ]);
            }
        }

        if ($request->bio) {
            $bio = $request->bio;
            $forbiddenWords = ['spam', 'advertisement', 'promote'];

            foreach ($forbiddenWords as $word) {
                if (false !== stripos($bio, $word)) {
                    throw ValidationException::withMessages([
                        'bio' => ['A bio contém conteúdo impróprio.'],
                    ]);
                }
            }
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    private function safeHasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable $e) {
            Log::error('Schema hasColumn check failed', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
