<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateGuruRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->guard('api')->user();
        return $user && $user->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        // normalize input
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
        if ($this->has('name')) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'    => ['required','string','max:255'],
            'email'   => ['required','email','max:255', Rule::unique('users','email')],
            'password'=> ['nullable', Password::min(8)],
            'nip'     => ['nullable','string','max:50', Rule::unique('guru','nip')],
            'no_hp'   => ['nullable','string','max:30'],
            'photo'   => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email sudah terdaftar.',
            'password.min' => 'Password minimal :min karakter.',
            'photo.image'  => 'File harus berupa gambar (jpg/png/webp).',
        ];
    }

    /**
     * Use withValidator if you need to add conditional rules after initial rules resolved.
     * Example: enforce password required in specific conditions.
     */
    public function withValidator($validator)
    {
        // example placeholder - no extra checks for now
    }
}
