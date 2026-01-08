<?php

declare(strict_types=1);

namespace App\Domain\Shared\DTOs;

/**
 * ApiResponseDTO - Standardized API response structure.
 */
readonly class ApiResponseDTO
{
    public function __construct(
        public bool $success,
        public string $message,
        public mixed $data = null,
        public ?PaginationDTO $pagination = null,
        public ?array $errors = null,
        public ?array $meta = null,
    ) {}

    /**
     * Create a success response.
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        ?PaginationDTO $pagination = null,
        ?array $meta = null,
    ): self {
        return new self(
            success: true,
            message: $message,
            data: $data,
            pagination: $pagination,
            meta: $meta,
        );
    }

    /**
     * Create an error response.
     */
    public static function error(
        string $message = 'Error',
        ?array $errors = null,
    ): self {
        return new self(
            success: false,
            message: $message,
            errors: $errors,
        );
    }

    /**
     * Convert to array for JSON response.
     */
    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if (null !== $this->data) {
            $response['data'] = $this->data;
        }

        if (null !== $this->pagination) {
            $response['pagination'] = $this->pagination->toArray();
        }

        if (null !== $this->errors) {
            $response['errors'] = $this->errors;
        }

        if (null !== $this->meta) {
            $response['meta'] = $this->meta;
        }

        return $response;
    }
}
