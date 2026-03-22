<?php

use Illuminate\Validation\Rules\Password;

// Already using new method names — should not be changed
$rules = [
    'password' => Password::min(8)->letters()->mixedCase()->numbers(),
];
