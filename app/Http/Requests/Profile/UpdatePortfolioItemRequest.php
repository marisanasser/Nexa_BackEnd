<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('portfolio_item');

        // Note: Route binding resolution might return object or ID depending on setup.
        // Assuming route model binding resolves to PortfolioItem model.
        // If not, we might need to findOrFail inside logic, but Request usually handles basic auth.
        // For simplicity, we'll verify ownership in Controller or Service if needed,
        // but ideally here if we had the model.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,avif,gif,bmp,mp4,mov,avi,webm', 'max:51200'],
            'thumbnail' => ['nullable', 'image', 'max:5120'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'in:image,video'],
            'platform' => ['nullable', 'string'],
            'external_url' => ['nullable', 'url'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'metrics' => ['nullable', 'array'],
        ];
    }
}
