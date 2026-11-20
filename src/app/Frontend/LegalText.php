<?php

namespace App\Frontend;

/**
 * The texts of /privacy and /terms, as written by the legal keeper (docs/legal/*.md; the body between the two rules is copied
 * to resources/legal/, because the container image holds only src/). Placeholders come from config/legal.php.
 */
class LegalText
{
    public function render(string $name): string
    {
        $text = (string) file_get_contents(resource_path("legal/$name.md"));

        $text = $this->deletion($text);
        $text = $this->images($text);
        $text = $this->monitoring($text);

        return strtr($text, [
            '{impressum_url}' => (string) config('legal.impressum_url'),
            '{datenschutz_url}' => (string) config('legal.datenschutz_url'),
            '{end_date}' => (string) config('legal.end_date'),
        ]);
    }

    /** The OpenAI row (a notebook title to draw an icon, an infographic's brief to draw a picture) is shown only while generated icons or infographics are switched on (D-032, D-045). */
    private function images(string $text): string
    {
        if ((bool) config('images.enabled') || (bool) config('infographics.enabled')) {
            return $text;
        }

        return preg_replace('/^\| \*\*OpenAI\*\* \|.*\n/m', '', $text) ?? $text;
    }

    /** The Sentry row (error reports with the account number) is shown only while error monitoring is on, that is, a DSN is set (D-043). */
    private function monitoring(string $text): string
    {
        if ((bool) config('sentry.enabled')) {
            return $text;
        }

        return preg_replace('/^\| \*\*Sentry\*\* \|.*\n/m', '', $text) ?? $text;
    }

    /**
     * The privacy notice has a fallback wording for when account deletion is not complete (marked "[if P-1 and P-2 are not merged]").
     * With `legal.deletion_complete` true (the default) the fallback is dropped; with false it replaces the full promise.
     */
    private function deletion(string $text): string
    {
        $pattern = '/^(- \*\*Settings.*?)\n  - \*\*\[if P-1 and P-2 are not merged\]\*\* Instead: "(.*?)"\n/ms';

        if (! preg_match($pattern, $text, $m)) {
            return $text;
        }

        $replacement = (bool) config('legal.deletion_complete') ? $m[1]."\n" : '- '.$m[2]."\n";

        return preg_replace($pattern, $replacement, $text, 1) ?? $text;
    }
}
