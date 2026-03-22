<?php

use Illuminate\Validation\Rule;

// Already using whereNotIn — should not be changed
$rules = [
    'email' => Rule::unique('users', 'email')->whereNotIn('status', ['inactive']),
];
