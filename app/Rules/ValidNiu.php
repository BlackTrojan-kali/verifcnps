<?php

namespace App\Rules;

use App\Services\NiuService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidNiu implements ValidationRule
{
    protected NiuService $niuService;

    public function __construct()
    {
        // On injecte notre service
        $this->niuService = new NiuService();
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->niuService->isValid($value)) {
            $fail('Le format du :attribute est invalide. Il doit commencer par M ou P, suivi de 12 chiffres et se terminer par une lettre.');
        }
    }
}