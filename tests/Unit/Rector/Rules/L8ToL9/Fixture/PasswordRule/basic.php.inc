<?php

use Illuminate\Validation\Rules\Password;

$rules = [
    'password' => Password::min(8)
        ->requireLetters()
        ->requireMixedCase()
        ->requireNumbers()
        ->requireSymbols()
        ->requireUncompromised(),
];
-----
<?php

use Illuminate\Validation\Rules\Password;

$rules = [
    'password' => Password::min(8)
        ->letters()
        ->mixedCase()
        ->numbers()
        ->symbols()
        ->uncompromised(),
];
