<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuideRequest extends FormRequest
{
    public function authorize()
    {

        return true;
    }

    public function rules()
    {
        return [
            'title' => 'required|string|min:2|max:255',
            'audience' => 'required|string|in:Brand,Creator',
            'description' => 'required|string|min:10',
            'steps' => 'sometimes|array',
            'steps.*.title' => 'required_with:steps|string|min:2|max:255',
            'steps.*.description' => 'required_with:steps|string|min:10',
            'steps.*.videoFile' => 'sometimes|nullable',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => 'O título é obrigatório.',
            'title.min' => 'O título deve ter pelo menos 2 caracteres.',
            'title.max' => 'O título não pode ter mais de 255 caracteres.',
            'audience.required' => 'O público é obrigatório.',
            'audience.in' => 'O público deve ser Brand ou Creator.',
            'description.required' => 'A descrição é obrigatória.',
            'description.min' => 'A descrição deve ter pelo menos 10 caracteres.',
            'steps.array' => 'Os passos devem ser uma lista.',
            'steps.*.title.required_with' => 'O título do passo é obrigatório.',
            'steps.*.title.min' => 'O título do passo deve ter pelo menos 2 caracteres.',
            'steps.*.title.max' => 'O título do passo não pode ter mais de 255 caracteres.',
            'steps.*.description.required' => 'A descrição do passo é obrigatória.',
            'steps.*.description.min' => 'A descrição deve ter pelo menos 10 caracteres.',
            'steps.*.videoFile.file' => 'O arquivo de vídeo do passo deve ser um arquivo válido.',
            'steps.*.videoFile.mimes' => 'O arquivo de vídeo do passo deve ser um vídeo válido (MP4, MOV, AVI, WMV, MPEG).',
            'steps.*.videoFile.max' => 'O arquivo de vídeo do passo não pode ter mais de 80MB.',
        ];
    }

    public function attributes()
    {
        return [
            'steps.*.title' => 'step title',
            'steps.*.description' => 'step description',
            'steps.*.videoFile' => 'step video file',
        ];
    }
}
