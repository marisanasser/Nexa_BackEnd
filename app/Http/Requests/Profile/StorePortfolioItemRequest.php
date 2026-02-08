<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\User\PortfolioItem;
use Illuminate\Foundation\Http\FormRequest;

class StorePortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PortfolioItem::class)
               || $this->user()->isCreator()
               || $this->user()->isStudent();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpeg,png,jpg,webp,avif,gif,bmp,mp4,mov,avi,webm', 'max:51200'], // 50MB
            'thumbnail' => ['nullable', 'image', 'max:5120'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:image,video'],
            'platform' => ['nullable', 'string'],
            'external_url' => ['nullable', 'url'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'metrics' => ['nullable', 'array'],
        ];
    }
}
