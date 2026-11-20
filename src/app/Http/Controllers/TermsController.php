<?php

namespace App\Http\Controllers;

use App\Frontend\LegalText;
use Inertia\Inertia;
use Inertia\Response;

/** The terms (the legal keeper's text, docs/legal/terms.md): the honest part, the Covenant, the cube. Public. */
class TermsController extends Controller
{
    public function __invoke(LegalText $legal): Response
    {
        $parts = preg_split('/^(?=## Part \d)/m', $legal->render('terms'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Inertia::render('terms', [
            'title' => 'Terms',
            // [0] the title line, [1] part 1 (honest), [2] part 2 (the Covenant), [3] part 3 (the cube)
            'parts' => array_values($parts),
        ]);
    }
}
