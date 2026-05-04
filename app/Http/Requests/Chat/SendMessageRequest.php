<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => 'required|string',
            'message' => 'required_without:file|string|max:1000',
            'file' => 'nullable|file|max:256000',
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'O ID da sala é obrigatório.',
            'room_id.string' => 'O ID da sala deve ser uma string.',
            'message.required_without' => 'A mensagem é obrigatória quando nenhum arquivo é enviado.',
            'message.string' => 'A mensagem deve ser um texto.',
            'message.max' => 'A mensagem não pode ter mais de 1000 caracteres.',
            'file.file' => 'O arquivo enviado é inválido.',
            'file.max' => 'O arquivo não pode ser maior que 256KB.',
        ];
    }
}
