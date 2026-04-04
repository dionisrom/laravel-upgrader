<?php

declare(strict_types=1);

namespace AppContainer\Composer;

enum ChangeType: string
{
    case ADDITION = 'addition';
    case REMOVAL = 'removal';
    case UPDATE = 'update';
    case REPLACEMENT = 'replacement';
}
