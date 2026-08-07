<?php

namespace App\Enums;

/**
 * Why a user flagged something (T-049, 02 §3.17).
 *
 * Deliberately short. A long reason list reads as thorough and behaves worse:
 * people pick the first plausible row, the distribution flattens, and the queue
 * loses the one signal it needs — which of these is urgent. Six is enough to
 * route, and `details` carries whatever the enum cannot.
 */
enum ReportReason: string
{
    /** Advertising, repetition, or a link farm. */
    case Spam = 'spam';

    /** The extraction landed on the wrong venue — a data bug, not misconduct. */
    case WrongPlace = 'wrong_place';

    /** Offensive, explicit, or abusive content. */
    case Inappropriate = 'inappropriate';

    /** A rightsholder objecting to their material being here (R-07, IR-2). */
    case Copyright = 'copyright';

    /** Fake offers, fake redemptions, impersonation. */
    case Fraud = 'fraud';

    case Other = 'other';

    /**
     * Reasons a human should look at first.
     *
     * `copyright` and `fraud` carry legal and financial exposure that grows
     * with every hour they sit; `inappropriate` is what an app-store reviewer
     * checks the response time on. `wrong_place` is a correctness bug that can
     * wait for the next triage pass.
     *
     * @return list<self>
     */
    public static function urgent(): array
    {
        return [self::Copyright, self::Fraud, self::Inappropriate];
    }

    public function isUrgent(): bool
    {
        return in_array($this, self::urgent(), true);
    }
}
