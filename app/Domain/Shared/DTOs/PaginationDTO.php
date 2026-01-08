<?php

declare(strict_types=1);

namespace App\Domain\Shared\DTOs;

/**
 * PaginationDTO - Data Transfer Object for pagination info.
 */
readonly class PaginationDTO
{
    public function __construct(
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public ?int $from = null,
        public ?int $to = null,
    ) {}

    /**
     * Create from a Laravel paginator.
     *
     * @param mixed $paginator
     */
    public static function fromPaginator($paginator): self
    {
        return new self(
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            from: $paginator->firstItem(),
            to: $paginator->lastItem(),
        );
    }

    /**
     * Convert to array for JSON response.
     */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
