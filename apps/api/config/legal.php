<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Who the legal documents name (T-054)
    |--------------------------------------------------------------------------
    | The privacy policy and the terms have to identify a real, contactable
    | party — that is most of what makes them legally meaningful. That party is
    | a PERSON here, not a company, so their name and domicile are personal data
    | in their own right and do not belong hard-coded in a source file that gets
    | pushed to a git host, mirrored, and cloned.
    |
    | So the documents read these at render time and there are NO DEFAULTS. An
    | unset value is not "publish something generic": it means the operator has
    | not decided to publish their identity yet, and the pages refuse to render
    | (503) rather than serving a contract with no party to it. See
    | LegalDocumentController::identity().
    |
    | Failing loudly is deliberate. A silent fallback here has two ways to go
    | wrong and both are bad: it either leaks the name that was meant to be
    | withheld, or it publishes a privacy policy naming no controller — which is
    | not a lesser version of a privacy policy, it is an invalid one.
    |
    | NOTE: these fill in IDENTITY, not JURISDICTION. The documents are written
    | for Uruguayan law (Ley 18.331, URCDP, courts of Montevideo) with GDPR
    | terms retained for EU users. Moving to another country — or from a person
    | to a company — is a rewrite of the prose, not a change of these values.
    */

    // Full legal name of the data controller, e.g. an individual's name or a
    // registered company name.
    'controller' => env('LEGAL_CONTROLLER_NAME'),

    // Where that party is domiciled, as it should read in a sentence:
    // "… based in {domicile}". e.g. "Montevideo, Uruguay".
    'domicile' => env('LEGAL_CONTROLLER_DOMICILE'),

    // The published contact address, used for privacy requests, moderation
    // reports and copyright notices alike. It must be a real monitored inbox:
    // the terms commit to acting on reports within 24 hours, and Apple checks
    // that a report has somewhere to go.
    'contact_email' => env('LEGAL_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Minimum age (T-113)
    |--------------------------------------------------------------------------
    | ONE number with two consumers: the age the terms and the privacy policy
    | STATE, and the age the signup gate ENFORCES. They were allowed to disagree
    | once already — the documents said "at least 13 years old" while nothing in
    | the app checked anything — and a promise the code does not keep is the
    | failure this binding exists to make impossible. `LegalDocumentTest` asserts
    | the rendered prose carries this value; `RegisterAgeGateTest` asserts the
    | boundary it rejects at.
    |
    | Unlike the identity above this HAS a default, because it is not personal
    | data and an unset minimum age must never mean "no minimum".
    |
    | Raising it is a product and legal decision, not a config tweak: the terms
    | already defer to a higher local minimum where the law sets one (GDPR Art. 8
    | allows member states to set 13–16), so this is the global floor.
    */
    // `max()` against the floor, not a bare cast. A malformed value casts to 0,
    // and 0 does not mean "no opinion" here — it means AgeCheck admits every
    // date of birth ever written, silently, from a typo in an env file. The
    // comment above already said an unset value must never mean "no minimum";
    // this makes that true of a WRONG value too, which is the case that
    // actually reaches production.
    'minimum_age' => max(13, (int) env('LEGAL_MINIMUM_AGE', 13)),
];
