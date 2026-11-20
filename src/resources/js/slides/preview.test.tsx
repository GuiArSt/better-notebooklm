/**
 * Writes the static preview page (docs/assets/slides-preview.html) from the real components, when asked:
 *   SLIDES_PREVIEW_OUT=../docs/assets/slides-preview.html npx vp test run resources/js/slides/preview
 * Without the variable this test is skipped, so the normal test run never writes a file.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { maxDeck, sampleDeck } from '@/slides/fixtures';
import { SlideView } from '@/slides/slide';
import type { Deck } from '@/slides/types';
import type { Citation } from '@/types/app';

const OUT = process.env.SLIDES_PREVIEW_OUT;
const here = (p: string) => resolve(__dirname, p);

const cite = (id: string): Citation | undefined =>
    /^P[1-6]$/.test(id)
        ? {
              id: Number(id.slice(1)),
              source_id: 1,
              source_title: 'Voyager 1',
              page: null,
              start: 0,
              end: 10,
              quote: 'a passage',
          }
        : undefined;

const deckHtml = (deck: Deck) =>
    deck.slides
        .map((slide, index) =>
            renderToStaticMarkup(
                <SlideView
                    slide={slide}
                    context={{
                        index,
                        total: deck.slides.length,
                        deckTitle: deck.title,
                        cite,
                    }}
                />,
            ),
        )
        .join('');

/** The token blocks of the app's stylesheet, so the preview wears the same colours. */
function tokens(): string {
    const css = readFileSync(here('../../css/app.css'), 'utf8');
    const block = (selector: string) => {
        const start = css.indexOf(`${selector} {`);

        return start < 0 ? '' : css.slice(start, css.indexOf('}', start) + 1);
    };

    return `${block(':root')}\n${block('.dark')}`;
}

describe('the slides preview page', () => {
    it.skipIf(!OUT)('is written from the real components', () => {
        const slidesCss = readFileSync(here('slides.css'), 'utf8');
        const panel = (
            title: string,
            width: number,
            theme: 'light' | 'dark',
            deck: Deck,
        ) => `
<section class="pv ${theme}" data-width="${width}">
  <h2>${title} <span>${width} px wide · ${theme}</span></h2>
  <div class="pv-strip" style="width:${width}px">${deckHtml(deck)}</div>
</section>`;

        const page = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Slide templates</title>
<style>
${tokens()}
:root { --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif; }
* { box-sizing: border-box; }
body { margin: 0; background: #e9ebee; color: #171717; font: 15px/1.5 var(--font-sans); }
main { padding: 32px 16px 64px; }
header.top { max-width: 900px; margin: 0 auto 24px; }
h1 { font-size: 28px; margin: 0 0 6px; }
#verdict { display: inline-block; margin-top: 8px; padding: 6px 14px; border-radius: 999px; font-weight: 600; background: #fff; }
#verdict.ok { background: #d1fae5; color: #065f46; } #verdict.bad { background: #fee2e2; color: #991b1b; }
.pv { margin: 0 auto 40px; padding: 20px; border-radius: 24px; width: fit-content; max-width: 100%; overflow-x: auto; }
.pv.light { background: var(--canvas); color: var(--foreground); }
.pv.dark { background: var(--canvas); color: var(--foreground); }
.pv > h2 { font-size: 16px; margin: 0 0 14px; } .pv > h2 span { font-weight: 400; opacity: .6; }
.pv-strip { display: flex; flex-direction: column; gap: 20px; }
[data-testid='citation'] { border: 0; border-radius: 4px; padding: 0 4px; font: 600 11px/1.5 var(--font-sans);
  background: color-mix(in oklab, var(--primary) 10%, transparent); color: var(--primary); cursor: pointer; }
[data-testid='citation-quote'] { display: none; }
.sl.overflow { outline: 4px solid #dc2626; outline-offset: 2px; }
${slidesCss}
</style>
</head>
<body>
<main>
<header class="top">
  <h1>Slide templates · D-034</h1>
  <p>The seven templates, drawn by the real components (<code>src/resources/js/slides</code>), filled to every limit of the contract:
  the longest heading, the most bullets, twelve characters of number. Shown at 1280 px and at a 375 px phone, in light and dark,
  then a deck as a model would write it. The page checks itself: any slide whose content leaves its frame is outlined in red.</p>
  <p id="verdict">checking…</p>
</header>
${panel('Every field at its maximum', 1280, 'light', maxDeck)}
${panel('Every field at its maximum', 1280, 'dark', maxDeck)}
${panel('Every field at its maximum, on a phone', 375, 'light', maxDeck)}
${panel('Every field at its maximum, on a phone', 375, 'dark', maxDeck)}
${panel('A deck as a model would write it', 960, 'light', sampleDeck)}
${panel('A deck as a model would write it', 960, 'dark', sampleDeck)}
</main>
<script>
function check() {
  const slides = [...document.querySelectorAll('.sl')];
  let bad = 0;
  for (const s of slides) {
    const box = s.getBoundingClientRect();
    const body = s.querySelector('.sl-body');
    let out = body.scrollHeight > body.clientHeight + 1 || body.scrollWidth > body.clientWidth + 1;
    for (const el of s.querySelectorAll('.sl-body *, .sl-footer *')) {
      if (el.closest('.sl-constellation') || el.closest('[data-testid="citation-quote"]')) continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) continue;
      if (r.right > box.right + 0.5 || r.bottom > box.bottom + 0.5 || r.left < box.left - 0.5 || r.top < box.top - 0.5) { out = true; break; }
    }
    // text must also stay inside its own card (the two columns, the big number's grid)
    for (const c of s.querySelectorAll('.sl-col, .sl-big, .sl-steps')) {
      if (c.scrollHeight > c.clientHeight + 1 || c.scrollWidth > c.clientWidth + 1) out = true;
    }
    s.classList.toggle('overflow', out);
    if (out) bad++;
  }
  const v = document.getElementById('verdict');
  v.textContent = bad ? bad + ' of ' + slides.length + ' slides overflow' : 'No overflow: all ' + slides.length + ' slides fit their frame';
  v.className = bad ? 'bad' : 'ok';
  document.body.dataset.overflow = String(bad);
}
(document.fonts ? document.fonts.ready : Promise.resolve()).then(check);
</script>
</body>
</html>
`;

        // the .dark tokens apply to the dark panels only
        writeFileSync(OUT!, page.replace('.dark {', '.pv.dark, .dark {'));
        expect(page).toContain('sl--steps');
    });
});
