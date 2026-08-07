<?php

namespace App\Enums;

/**
 * Who is asking for the takedown (T-049).
 *
 * The distinction is not cosmetic: an INFLUENCER asking us to stop citing their
 * own post is a relationship question with an easy answer, while a
 * RIGHTSHOLDER notice carries legal weight and a response clock. They arrive
 * through the same inbox and need to be told apart on sight.
 */
enum TakedownRequesterRole: string
{
    case Rightsholder = 'rightsholder';
    case Influencer = 'influencer';
}
