<?php

// Legal links shown in every page's footer. The owner offers the demo personally (D-019): the links point to his own site, so no
// name or address is kept in this repository (it goes public through the mirror). To use other pages, change the two values.
return [
    'impressum_url' => env('LEGAL_IMPRESSUM_URL', 'https://guillermo.tartarus-vault.com/impressum/'),
    'datenschutz_url' => env('LEGAL_DATENSCHUTZ_URL', 'https://guillermo.tartarus-vault.com/datenschutz/'),

    // The date by which the whole demo and all its data are deleted, printed on /privacy and /terms (the owner's answer to Themis's Q1: 31 December 2026, D-023).
    'end_date' => env('LEGAL_END_DATE', '31 December 2026'),

    // True once account deletion removes everything (files too). False swaps in the privacy notice's fallback wording.
    'deletion_complete' => (bool) env('LEGAL_DELETION_COMPLETE', true),
];
