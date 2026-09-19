<?php

namespace App\Enums;

enum ExceptionType: string
{
    case Skip = 'skip';
    case Override = 'override';
}
