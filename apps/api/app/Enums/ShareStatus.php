<?php

namespace App\Enums;

enum ShareStatus: string
{
    case Pending = 'pending';
    case Fetching = 'fetching';
    case Analyzing = 'analyzing';
    case Review = 'review';
    case Published = 'published';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
