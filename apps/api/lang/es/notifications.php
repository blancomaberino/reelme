<?php

/**
 * Push copy, in Spanish — the product's default language.
 *
 * These strings are for the PUSH only. The in-app notification center renders
 * its own copy from `data.type` in the mobile dictionaries, so it follows the
 * language toggle without a round trip; a push is composed in a queued worker
 * and has to be written here, in the recipient's `preferredLocale()`.
 *
 * The two must stay in step — same wording, same placeholders — or the banner a
 * user taps and the row they land next to say different things about one event.
 */
return [

    'share' => [
        'published' => [
            'title' => '¡Lugar añadido!',
            'body' => ':place ya está en tu mapa.',
            'body_fallback' => 'Tu lugar ya está en el mapa.',
        ],
        'review_needed' => [
            'title' => 'Revisá tu lugar',
            'body' => 'Confirmá algunos datos para terminar de agregarlo.',
        ],
        'failed' => [
            'title' => 'No pudimos procesar tu enlace',
            'body' => 'Tocá para ver qué pasó y volver a intentar.',
        ],
    ],

    'social' => [
        'follow' => [
            'title' => 'Nuevo seguidor',
            'body' => '@:username empezó a seguirte.',
        ],
    ],

    'influencer' => [
        'claim_rejected' => [
            'title' => 'Reclamo no aprobado',
            'body' => 'Tu reclamo sobre @:handle no fue aprobado.',
        ],
    ],

    'redemption' => [
        'verified' => [
            'title' => 'Oferta canjeada',
            'body' => 'Canjeaste tu oferta en :place. ¡Que lo disfrutes!',
            'body_fallback' => 'Canjeaste tu oferta. ¡Que lo disfrutes!',
        ],
    ],

    'wallet' => [
        'payout' => [
            'title' => 'Pago enviado',
            'body' => 'Te enviamos :amount. Llega a tu cuenta en unos días hábiles.',
        ],
    ],

];
