<?php

namespace App\Enums;

enum SlotRequestSource: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case Max = 'max';
}
