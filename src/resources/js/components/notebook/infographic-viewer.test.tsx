import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
    InfographicViewer,
    citationOf,
} from '@/components/notebook/infographic-viewer';
import type { Citation } from '@/types/app';
import type { StudioItem } from '@/types/studio';

const citation = (id: number): Citation => ({
    id,
    source_id: 1,
    source_title: 'Orion',
    page: 2,
    start: 0,
    end: 10,
    quote: 'the quoted passage',
});

const item = {
    id: 5,
    notebook_id: 1,
    kind: 'infographic',
    title: 'Mission Orion',
    status: 'done',
    image_url: '/notebooks/1/studio/5/image',
    infographic: {
        title: 'Mission Orion',
        points: [
            {
                heading: 'Launch',
                figure: '2026',
                text: 'It flew.',
                refs: ['P12', 'P99'],
            },
        ],
    },
    citations: [citation(12)],
} as unknown as StudioItem;

describe('the infographic viewer', () => {
    it('shows the picture, a download, the points and chips only for kept citations', () => {
        const html = renderToStaticMarkup(
            <InfographicViewer item={item} onOpenCitation={() => undefined} />,
        );

        expect(html).toContain('src="/notebooks/1/studio/5/image"');
        expect(html).toContain('href="/notebooks/1/studio/5/image?download=1"');
        expect(html).toContain('Launch');
        expect(html.match(/data-testid="citation"/g)).toHaveLength(1);
    });

    it('says so while the picture is drawn and shows the server’s message when it failed', () => {
        const running = renderToStaticMarkup(
            <InfographicViewer
                item={{ ...item, status: 'running', image_url: null }}
                onOpenCitation={() => undefined}
            />,
        );
        const failed = renderToStaticMarkup(
            <InfographicViewer
                item={{
                    ...item,
                    status: 'failed',
                    image_url: null,
                    error: 'No budget left.',
                }}
                onOpenCitation={() => undefined}
            />,
        );

        expect(running).toContain('still being drawn');
        expect(failed).toContain('No budget left.');
    });

    it('finds a citation by its P-number', () => {
        expect(citationOf('P12', [citation(12)])?.index).toBe(0);
        expect(citationOf('P7', [citation(12)])).toBeNull();
        expect(citationOf('P7', null)).toBeNull();
    });

    it('shows the problems the check found, a muted line when it did not answer, and nothing when it passed (D-057)', () => {
        const withReview = (review: unknown) =>
            renderToStaticMarkup(
                <InfographicViewer
                    item={
                        {
                            ...item,
                            settings: { review },
                        } as unknown as StudioItem
                    }
                    onOpenCitation={() => undefined}
                />,
            );

        const bad = withReview({
            verdict: 'bad',
            attempts: 2,
            problems: ['A number is wrong.'],
        });

        expect(bad).toContain('The check found problems in this picture:');
        expect(bad).toContain('A number is wrong.');
        expect(withReview({ verdict: 'unavailable', attempts: 1 })).toContain(
            'Not checked: the review didn’t answer',
        );

        const good = withReview({ verdict: 'good', attempts: 1 });

        expect(good).not.toContain('infographic-review');
    });

    it('is a showcase: a big picture that opens full size, the text collapsed, the points numbered with the figure highlighted', () => {
        const html = renderToStaticMarkup(
            <InfographicViewer item={item} onOpenCitation={() => undefined} />,
        );

        expect(html).toContain('max-h-[78vh] w-full');
        expect(html).toContain('data-testid="infographic-open"');
        expect(html).toContain('target="_blank"');
        expect(html).toContain('Open full size');
        // closed by default: the details element carries no `open` attribute
        expect(html).toMatch(
            /<details class="[^"]*" data-testid="infographic-text">/,
        );
        expect(html).toContain('Text and sources');
        expect(html).toContain('<ol');
        expect(html).toContain('data-testid="infographic-figure"');
    });
});
