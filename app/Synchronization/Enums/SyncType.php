<?php

namespace App\Synchronization\Enums;

enum SyncType: string
{
    case Full = 'full';
    case Incremental = 'incremental';
}
