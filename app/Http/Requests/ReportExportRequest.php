<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['today', 'week', 'month', 'custom'])],
            'date_from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'before_or_equal:date_to'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'scope' => ['required', Rule::in(['current', 'full'])],
            'section' => ['required', Rule::in(['summary', 'sales', 'bouquets', 'inventory', 'expenses', 'profit'])],
        ];
    }
}
