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
];
