<?php

namespace App\Http\Controllers;

use App\Frontend\LegalText;
use Inertia\Inertia;
use Inertia\Response;

/** The privacy notice (the legal keeper's text, docs/legal/privacy-notice.md). Public. */
class PrivacyController extends Controller
{
    public function __invoke(LegalText $legal): Response
    {
        return Inertia::render('privacy', ['markdown' => $legal->render('privacy')]);
    }
}
