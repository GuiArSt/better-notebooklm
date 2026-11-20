<?php

namespace App\Frontend;

use App\Models\Notebook;
use App\Models\User;
use App\Sources\EmbedSource;

/**
 * The one featured notebook: a demo about this demo, written for it, so a tester has something to ask at once.
 * Opening it makes the tester their own copy (their budget pays for the embeddings; nobody else's notes leak).
 */
class Featured
{
    public const SLUG = 'about-this-demo';

    /** @return array{slug: string, emoji: string, title: string, blurb: string} */
    public static function card(): array
    {
        return ['slug' => self::SLUG, 'emoji' => '🧪', 'title' => 'About this demo', 'blurb' => 'A ready notebook about how Better NotebookLM is built and what it can and cannot do. Ask it anything.'];
    }

    public static function open(User $user): Notebook
    {
        $existing = Notebook::query()->where('user_id', $user->id)->where('featured_slug', self::SLUG)->first();
        if ($existing) {
            return $existing;
        }

        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'About this demo', 'emoji' => '🧪', 'featured_slug' => self::SLUG]);

        foreach (self::sources() as $title => $content) {
            $source = $notebook->sources()->create(['kind' => 'text', 'title' => $title, 'content' => self::filled($content), 'status' => 'pending']);
            EmbedSource::dispatch($source->id);
        }

        return $notebook;
    }

    /** Infographics are listed as working or as not in the demo, by the server's switch. */
    private static function filled(string $content): string
    {
        $on = (bool) config('infographics.enabled');

        return strtr($content, ['{infographics_works}' => $on ? 'infographics for pro members, ' : '', '{infographics}' => $on ? '' : 'infographics, ']);
    }

    /** @return array<string, string> */
    private static function sources(): array
    {
        return [
            'How it works' => <<<'TXT'
Better NotebookLM is a replica of Google's NotebookLM, built in three days for a job application test. You add sources to a notebook: a PDF, a web page or pasted text. Each source is cut into passages of a few sentences, and every passage is turned into a vector by an embedding model. When you ask a question, the app searches the passages of the checked sources twice, once by meaning (vectors) and once by words (full-text), and merges the two lists. The best passages go to a language model with one strict instruction: answer only from these passages and mark every claim with the number of the passage it came from. The server then checks every marker. A marker that does not point at a real passage is thrown away, so a citation you can hover is always a real quote from your own sources.
TXT,
            'What truly works' => <<<'TXT'
In this demo the following works for real: signing in by invitation, notebooks with an icon, sources from PDFs, web pages and pasted text, checkboxes that choose which sources the chat may use, a viewer with a short summary of each source, grounded chat that streams its answer with citations, notes (including saving an answer to a note and using a note as a source), the audio overview with two hosts, written reports such as a briefing, a study guide and an FAQ, a mind map of the topics, a quiz with explanations and hints, slide decks, {infographics_works}a Customize step before each Studio item (language, sources, a focus and one choice such as length), a usage meter with a hard monthly budget, and a pricing page with a Stripe test-mode checkout. Every call to a language model is traced in Langfuse, including the question and the sources used, so the person who built this can see what the demo did.
TXT,
            'What is not in this demo' => <<<'TXT'
Some things from the real NotebookLM are shown greyed out as "not in this demo" because they were not built in the three days: video overviews, flashcards, {infographics}data tables and sites in the Studio panel; YouTube, audio files, images and Google Drive as sources; and sharing a notebook with other people. The pricing page shows a pro plan at the model cost times 1.5 and a checkout that runs in Stripe's test mode: it is marked "Demo: no real payments" and no money moves. The budget is small on purpose: each tester has a small budget, shown in the corner of every screen, and once it is used up the app says so instead of continuing.
TXT,
        ];
    }
}
