import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { SCRAPING_NOT_IN_DEMO, WEB_SOURCE_NOTE } from '@/lib/web-source-note';

const read = (path: string) =>
    readFileSync(new URL(path, import.meta.url), 'utf8');

describe('the demo says plainly that scraping is limited (D-060)', () => {
    it('words the limit', () => {
        expect(WEB_SOURCE_NOTE).toBe(
            'Reads plain web pages. Pages that build their content with JavaScript may come back empty: upload a PDF or paste the text instead.',
        );
        expect(SCRAPING_NOT_IN_DEMO).toContain('Full web scraping');
    });

    it('shows the note under the web-link field and the line in the greeting’s list', () => {
        expect(read('../components/notebook/add-source-dialog.tsx')).toContain(
            '{WEB_SOURCE_NOTE}',
        );
        expect(read('../pages/greeting.tsx')).toMatch(
            /NOT_IN_DEMO = \[[^\]]*SCRAPING_NOT_IN_DEMO/s,
        );
    });
});
