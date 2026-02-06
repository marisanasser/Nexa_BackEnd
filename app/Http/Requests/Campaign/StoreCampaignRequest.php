<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isBrand();
    }

    protected function prepareForValidation()
    {
        try {
            // Debug log to understand what's coming
            $debugFiles = [];
            foreach ($this->allFiles() as $key => $file) {
                if (is_array($file)) {
                    foreach ($file as $subKey => $subFile) {
                        if ($subFile instanceof \Illuminate\Http\UploadedFile) {
                            $debugFiles["{$key}.{$subKey}"] = [
                                'name' => $subFile->getClientOriginalName(),
                                'size' => $subFile->getSize(),
                                'mime' => $subFile->getClientMimeType(),
                                'error' => $subFile->getError(),
                                'isValid' => $subFile->isValid(),
                                'realMime' => $subFile->getMimeType(), // Requires file to exist
                            ];
                        }
                    }
                } elseif ($file instanceof \Illuminate\Http\UploadedFile) {
                    $debugFiles[$key] = [
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime' => $file->getClientMimeType(),
                        'error' => $file->getError(),
                        'isValid' => $file->isValid(),
                        'realMime' => $file->getMimeType(),
                    ];
                }
            }

            \Illuminate\Support\Facades\Log::info('StoreCampaignRequest FILE DEBUG', [
                'input_keys' => array_keys($this->input()),
                'files_info' => $debugFiles,
            ]);

            $keysToCheck = ['image', 'logo'];
            foreach ($keysToCheck as $key) {
                // Check if it exists in input (POST/GET) but is not a valid file in FILES
                if ($this->has($key)) {
                    $file = $this->file($key);
                    // Short-circuit: if not instance, don't call isValid
                    if (!($file instanceof \Illuminate\Http\UploadedFile) || !$file->isValid()) {
                        $this->offsetUnset($key);
                        if ($this->files->has($key)) {
                            $this->files->remove($key);
                        }
                        if ($this->request->has($key)) {
                            $this->request->remove($key);
                        }
                    }
                }
            }

            // Special handling for attach_file array
            if ($this->has('attach_file')) {
                $allFiles = $this->allFiles();
                $files = $allFiles['attach_file'] ?? [];
                
                // If it's not an array of files, or empty, just remove the input entirely
                if (!is_array($files)) {
                    // It might be in input as strings
                    $this->offsetUnset('attach_file');
                    if ($this->files->has('attach_file')) {
                        $this->files->remove('attach_file');
                    }
                    if ($this->request->has('attach_file')) {
                        $this->request->remove('attach_file');
                    }
                } else {
                    // Filter out invalid files
                    $validFiles = [];
                    foreach ($files as $key => $file) {
                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                            $validFiles[$key] = $file;
                        }
                    }

                    if (empty($validFiles)) {
                        $this->offsetUnset('attach_file');
                        if ($this->files->has('attach_file')) {
                            $this->files->remove('attach_file');
                        }
                        if ($this->request->has('attach_file')) {
                            $this->request->remove('attach_file');
                        }
                    } else {
                        // Update files bag
                        $this->files->set('attach_file', $validFiles);
                        // Update input to match (merge files into input)
                        $this->merge(['attach_file' => $validFiles]);
                    }
                }
            }
        } catch (\Throwable $e) {
          Log::error('Error in prepareForValidation: ' . $e->getMessage());
            // Intentionally swallow error to let validation proceed (and likely fail if data is bad)
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'budget' => ['required_if:remuneration_type,paga', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'remuneration_type' => ['required', 'in:paga,permuta'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected,completed,cancelled'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'target_states' => ['nullable'],
            'category' => ['nullable', 'string', 'max:255'],
            'campaign_type' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp,avif,svg', 'max:5120'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp,avif,svg', 'max:5120'],
            'attach_file' => ['nullable'],
            'attach_file.*' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,jpg,jpeg,png,gif,webp,avif,svg', 'max:10240'],
            'deadline' => ['required', 'date'],
            'max_bids' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_age' => ['nullable', 'integer', 'min:18', 'max:100'],
            'max_age' => ['nullable', 'integer', 'min:18', 'max:100'],
            'target_genders' => ['nullable', 'array'],
            'target_genders.*' => ['string', 'in:male,female,other'],
            'target_creator_types' => ['required', 'array', 'min:1'],
            'target_creator_types.*' => ['string', 'in:ugc,influencer,both'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Campaign title is required.',
            'title.max' => 'Campaign title must not exceed 255 characters.',
            'description.required' => 'Campaign description is required.',
            'description.max' => 'Campaign description must not exceed 5000 characters.',
            'budget.required_if' => 'Campaign budget is required for paid campaigns.',
            'budget.numeric' => 'Campaign budget must be a valid number.',
            'budget.min' => 'Campaign budget must be at least $0.',
            'budget.max' => 'Campaign budget cannot exceed $999,999.99.',
            'deadline.required' => 'Campaign deadline is required.',
            'deadline.date' => 'Campaign deadline must be a valid date.',
            'image_url.url' => 'Image URL must be a valid URL.',
            'image_url.max' => 'Image URL must not exceed 2048 characters.',
            'image.image' => 'The uploaded file must be an image.',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif, webp.',
            'image.max' => 'The image must not be larger than 5MB.',
            'logo.image' => 'The logo must be an image.',
            'logo.mimes' => 'The logo must be a file of type: jpeg, png, jpg, gif, webp.',
            'logo.max' => 'The logo must not be larger than 5MB.',
            'attach_file.file' => 'The attach file must be a valid file.',
            'attach_file.mimes' => 'The attach file must be a file of type: pdf, doc, docx, xls, xlsx, ppt, pptx, txt, zip, rar.',
            'attach_file.max' => 'The attach file must not be larger than 10MB.',
            'max_bids.integer' => 'Maximum bids must be a valid number.',
            'max_bids.min' => 'Maximum bids must be at least 1.',
            'max_bids.max' => 'Maximum bids cannot exceed 100.',
            'min_age.integer' => 'Minimum age must be a valid number.',
            'min_age.min' => 'Minimum age must be at least 18.',
            'min_age.max' => 'Minimum age cannot exceed 100.',
            'max_age.integer' => 'Maximum age must be a valid number.',
            'max_age.min' => 'Maximum age must be at least 18.',
            'max_age.max' => 'Maximum age cannot exceed 100.',
            'target_genders.array' => 'Target genders must be an array.',
            'target_genders.*.string' => 'Each target gender must be a string.',
            'target_genders.*.in' => 'Invalid target gender value.',
            'target_creator_types.required' => 'At least one creator type must be selected.',
            'target_creator_types.array' => 'Target creator types must be an array.',
            'target_creator_types.min' => 'At least one creator type must be selected.',
            'target_creator_types.*.string' => 'Each target creator type must be a string.',
            'target_creator_types.*.in' => 'Invalid target creator type value.',
        ];
    }

    public function attributes(): array
    {
        return [
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
