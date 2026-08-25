<?php

namespace App\Enums;

enum ConsentAction: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
}
