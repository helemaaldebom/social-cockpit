<?php

namespace App\Exceptions;

use App\Enums\ContentStatus;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(ContentStatus $from, ContentStatus $to)
    {
        parent::__construct(
            "Ongeldige statusovergang van [{$from->label()}] naar [{$to->label()}]."
        );
    }
}
