<?php

namespace App\Enums;

enum PostPrivacy: string
{
    case Public = 'public';
    case Private = 'private';
    case Unknown = 'unknown';
}
