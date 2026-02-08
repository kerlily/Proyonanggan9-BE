<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSiswaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->guard('api')->user();
        return $user && $user->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('nama')) {
            $this->merge(['nama' => trim($this->input('nama'))]);
        }
        if ($this->has('tahun_lahir')) {
            // ensure integer-ish
            $this->merge(['tahun_lahir' => (int)$this->input('tahun_lahir')]);
        }
        if ($this->has('nisn')) {
            $this->merge(['nisn' => trim($this->input('nisn'))]);
        }
    }

    public function rules(): array
    {
        $currentYear = (int) date('Y');

        return [
            'nama' => ['required','string','max:255'],
            'nisn' => ['nullable','string','max:20', 'unique:siswa,nisn'],
            'tahun_lahir' => ['required','integer','digits:4','min:1900','max:'.$currentYear],
            'kelas_id' => ['nullable','integer','exists:kelas,id'],
            'is_alumni' => ['nullable','boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kelas_id.exists' => 'Kelas tidak ditemukan. Pilih kelas yang valid.',
            'tahun_lahir.digits' => 'Format tahun lahir harus YYYY (4 digit).',
            'nisn.unique' => 'NISN sudah terdaftar. gunakan NISN lain.',
        ];
    }

    public function withValidator($validator)
    {
        // Example: reject any siswa with tahun_lahir in future
        $validator->after(function ($v) {
            if ($this->filled('tahun_lahir') && $this->input('tahun_lahir') > date('Y')) {
                $v->errors()->add('tahun_lahir', 'Tahun lahir tidak boleh di masa depan.');
            }
        });
    }
}
