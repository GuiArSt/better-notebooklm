import { readFileSync } from 'node:fs';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { sampleDeck } from '@/slides/fixtures';
import { PptxButton } from '@/slides';

describe('the slide viewer’s downloads (D-056)', () => {
    it('puts the .pptx button next to Print / PDF', () => {
        const viewer = readFileSync(
            new URL('./slide-viewer.tsx', import.meta.url),
            'utf8',
        );

        expect(viewer).toContain(
            "import { PptxButton, SlideView } from '@/slides';",
        );
        expect(viewer).toMatch(
            /Print \/ PDF\s*<\/Button>\s*<PptxButton deck=\{deck\} cite=\{cite\} \/>/,
        );
    });

    it('shows the button, ready to click', () => {
        const html = renderToStaticMarkup(<PptxButton deck={sampleDeck} />);

        expect(html).toContain('Download .pptx');
        expect(html).toContain('aria-busy="false"');
    });
});
