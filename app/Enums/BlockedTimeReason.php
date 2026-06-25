<?php

namespace App\Enums;

enum BlockedTimeReason: string
{
    case Vacation = 'Отпуск';
    case SickLeave = 'Больничный';
    case Lunch = 'Обед';
    case Personal = 'Личное время';
    case Other = 'Другое';
}
