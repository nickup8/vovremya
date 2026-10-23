<?php

namespace App\Enums;

enum BackupTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
}
