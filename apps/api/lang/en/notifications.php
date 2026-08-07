<?php

/**
 * Push copy, in English. See the Spanish file for why these live server-side
 * while the notification center's copy lives in the mobile dictionaries.
 *
 * Every key here must exist in `lang/es/notifications.php` too — a missing key
 * renders the dotted key path to the user rather than falling back to the other
 * language. `NotificationCopyTest` pins that both files carry the same keys.
 */
return [

    'share' => [
        'published' => [
            'title' => 'Place added!',
            'body' => ':place is on your map now.',
            'body_fallback' => 'Your place is on the map now.',
        ],
        'review_needed' => [
            'title' => 'Check your place',
            'body' => 'Confirm a few details to finish adding it.',
        ],
        'failed' => [
            'title' => "We couldn't process your link",
            'body' => 'Tap to see what happened and try again.',
        ],
    ],

    'social' => [
        'follow' => [
            'title' => 'New follower',
            'body' => '@:username started following you.',
        ],
    ],

    'influencer' => [
        'claim_rejected' => [
            'title' => 'Claim not approved',
            'body' => 'Your claim on @:handle was not approved.',
        ],
    ],

    'redemption' => [
        'verified' => [
            'title' => 'Offer redeemed',
            'body' => 'Your offer was redeemed at :place. Enjoy!',
            'body_fallback' => 'Your offer was redeemed. Enjoy!',
        ],
    ],

    'wallet' => [
        'payout' => [
            'title' => 'Payout sent',
            'body' => 'We sent you :amount. It lands in your account in a few business days.',
        ],
    ],

    'account' => [
        'export_ready' => [
            'title' => 'Your data export is ready',
            'body' => 'We emailed you the download link.',
        ],
    ],

];
