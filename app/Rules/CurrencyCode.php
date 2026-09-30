<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ResourceBundle;

class CurrencyCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currencies = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');
        if (! is_string($value) || ! preg_match('/^[A-Z]{3}$/D', $value) || ! $currencies?->get($value)) {
            $fail('Currency harus berupa kode mata uang yang valid, misalnya IDR.');
        }
    }
}
