<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->getAuthenticatedUser();
        $campaign = $this->route('campaign');

        if (!$user || !$campaign) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->isBrand() && $campaign->brand_id === $user->id;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'budget' => ['sometimes', 'numeric', 'min:1', 'max:999999.99'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_states' => ['sometimes', 'nullable', 'array'],
            'target_states.*' => ['string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'campaign_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'image' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp,avif,svg', 'max:5120'],
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp,avif,svg', 'max:5120'],
            'attach_file' => ['sometimes', 'nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,jpg,jpeg,png,gif,webp,avif,svg,avi,mp4,mov,wmv', 'max:51200'],
            'deadline' => ['sometimes', 'date', 'after:today'],
            'max_bids' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'Campaign title must not exceed 255 characters.',
            'description.max' => 'Campaign description must not exceed 5000 characters.',
            'budget.numeric' => 'Campaign budget must be a valid number.',
            'budget.min' => 'Campaign budget must be at least $1.',
            'budget.max' => 'Campaign budget cannot exceed $999,999.99.',
            'deadline.date' => 'Campaign deadline must be a valid date.',
            'deadline.after' => 'Campaign deadline must be after today.',
            'image_url.url' => 'Image URL must be a valid URL.',
            'image_url.max' => 'Image URL must not exceed 2048 characters.',
            'image.image' => 'The uploaded file must be an image.',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif, webp, avif, svg.',
            'image.max' => 'The image must not be larger than 5MB.',
            'logo.image' => 'The logo must be an image.',
            'logo.mimes' => 'The logo must be a file of type: jpeg, png, jpg, gif, webp, avif, svg.',
            'logo.max' => 'The logo must not be larger than 5MB.',
            'attach_file.file' => 'The attach file must be a valid file.',
            'attach_file.mimes' => 'The attach file must be a file of type: pdf, doc, docx, xls, xlsx, ppt, pptx, txt, zip, rar, jpg, jpeg, png, gif, webp, avif, svg, avi, mp4, mov, wmv.',
            'attach_file.max' => 'The attach file must not be larger than 50MB.',
            'max_bids.integer' => 'Maximum bids must be a valid number.',
            'max_bids.min' => 'Maximum bids must be at least 1.',
            'max_bids.max' => 'Maximum bids cannot exceed 100.',
        ];
    }

    public function attributes(): array
    {
        return [
            'target_states.*' => 'target state',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('image_url') && $this->hasFile('image')) {
                $validator->errors()->add('image', 'Please provide either an image URL or upload an image file, not both.');
            }
        });
    }
}
