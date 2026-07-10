<?php

namespace App\Enums;

enum FetchStatus: string
{
    case Pending = 'pending';
    case Fetching = 'fetching';
    case Fetched = 'fetched';
    case Manual = 'manual';
    case Failed = 'failed';
}
