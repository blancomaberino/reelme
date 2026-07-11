<?php

namespace App\Enums;

/**
 * Taxonomy of discovery tags (02 §3.10). Extraction fields map directly:
 * `cuisines[]` → Cuisine, `dishes[]` → Dish, `vibe_tags[]` → Vibe,
 * `dietary_tags[]` → Diet. Other is the bucket for future free-text sources.
 */
enum TagKind: string
{
    case Cuisine = 'cuisine';
    case Vibe = 'vibe';
    case Dish = 'dish';
    case Diet = 'diet';
    case Other = 'other';
}
