<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** The About page (D-059): Ouroboros Creative Collective, the owner, his poem, the logo and the story. Public. */
class AboutController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('about/index');
    }
}
