<?php

namespace App\Enums;

enum PaymentReceiptStatus: string
{
    /** Live payment succeeded; the NPD receipt still has to be issued manually. */
    case AwaitingManual = 'awaiting_manual';

    /** An administrator attached a lknpd.nalog.ru link visible in the cabinet. */
    case Attached = 'attached';
}
