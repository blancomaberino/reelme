<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['nullable', 'url', 'max:2048'],
            'shared_text' => ['nullable', 'string', 'max:5000'],
            'source_hint' => ['nullable', 'string', 'in:instagram,x,tiktok,youtube'],
            'shared_via' => ['nullable', 'string', 'in:share_sheet,paste_url,manual'],
        ];
    }
}
