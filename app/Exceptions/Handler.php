<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Throwable;
use Illuminate\Support\Facades\Log;

class Handler extends ExceptionHandler
{
    
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            
        });

        
        $this->renderable(function (ValidationException $e, $request) {
            if ($request->expectsJson()) {
                Log::warning('Validation failed', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'errors' => $e->errors(),
                    'user_id' => auth()->id(),
                    'user_agent' => $request->userAgent()
                ]);

                return new JsonResponse([
                    'success' => false,
                    'message' => 'Os dados fornecidos são inválidos.',
                    'errors' => $e->errors()
                ], 422);
            }
        });
    }
}
