import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
    KINDS,
    StudioItemRow,
    citedSourceCount,
    whenLabel,
} from '@/components/notebook/studio-item-row';
import { TILE_TINTS } from '@/lib/studio-tints';
import { describeSettings } from '@/components/notebook/how-made-dialog';
import type { StudioItem } from '@/types/studio';

const now = new Date(2026, 10, 18, 18, 0);
const base: StudioItem = {
    id: 1,
    notebook_id: 1,
    kind: 'briefing',
    title: 'W-9 briefing',
    status: 'done',
    step: null,
    step_n: null,
    step_total: null,
    tree: null,
    content: 'x',
    citations: [],
    script: null,
    audio_url: null,
    error: null,
    created_at: new Date(2026, 10, 18, 17, 42).toISOString(),
};

describe('Studio item rows', () => {
    it('labels the time as Today, Yesterday or a date', () => {
        expect(
            whenLabel(new Date(2026, 10, 18, 17, 42).toISOString(), now),
        ).toBe('Today 17:42');
        expect(whenLabel(new Date(2026, 10, 17, 9, 5).toISOString(), now)).toBe(
            'Yesterday 09:05',
        );
        expect(
            whenLabel(new Date(2026, 10, 3, 16, 30).toISOString(), now),
        ).toMatch(/^Nov 3, 16:30$/);
    });

    it('gives every kind the identity of the tile that makes it', () => {
        expect(Object.keys(KINDS).sort()).toEqual([
            'audio',
            'briefing',
            'faq',
            'infographic',
            'mindmap',
            'quiz',
            'slides',
            'study_guide',
        ]);
        expect(KINDS.audio.tile).toBe('audio');
        expect(KINDS.mindmap.tile).toBe('mindmap');
        expect(KINDS.infographic.tile).toBe('infographic');
        expect([
            KINDS.briefing.tile,
            KINDS.study_guide.tile,
            KINDS.faq.tile,
        ]).toEqual(['reports', 'reports', 'reports']);
        // nine tiles, nine tints
        expect(new Set(Object.values(TILE_TINTS).map((t) => t.text)).size).toBe(
            9,
        );
    });

    it('draws a tinted square with the tile icon in the row', () => {
        const noop = () => undefined;
        const row = renderToStaticMarkup(
            <StudioItemRow
                item={{ ...base, kind: 'audio' }}
                onOpen={noop}
                onDelete={noop}
                onRetry={noop}
            />,
        );

        expect(row).toContain('data-tile="audio"');
        expect(row).toContain(TILE_TINTS.audio.soft.split(' ')[0]);
    });

    it('counts distinct cited sources', () => {
        const c = (source_id: number) => ({
            id: source_id,
            source_id,
            source_title: 's',
            page: null,
            start: 0,
            end: 1,
            quote: 'q',
        });

        expect(
            citedSourceCount({ ...base, citations: [c(1), c(1), c(2)] }),
        ).toBe(2);
        expect(citedSourceCount(base)).toBe(0);
    });

    it('shows kind, status and a retry on a failed item, and a generating line with its step', () => {
        const noop = () => undefined;
        const done = renderToStaticMarkup(
            <StudioItemRow
                item={base}
                onOpen={noop}
                onDelete={noop}
                onRetry={noop}
            />,
        );
        const failed = renderToStaticMarkup(
            <StudioItemRow
                item={{ ...base, status: 'failed', error: 'It broke' }}
                onOpen={noop}
                onDelete={noop}
                onRetry={noop}
            />,
        );
        const running = renderToStaticMarkup(
            <StudioItemRow
                item={{
                    ...base,
                    status: 'running',
                    step: 'Writing',
                    step_n: 2,
                    step_total: 3,
                }}
                onOpen={noop}
                onDelete={noop}
                onRetry={noop}
            />,
        );

        expect(done).toContain('Briefing');
        expect(done).toContain('Ready. Click to open');
        expect(done).not.toContain('item-retry');
        expect(failed).toContain('It broke');
        expect(failed).toContain('item-retry');
        expect(running).toContain('Generating: Writing (2/3)');
    });

    it('says in the row when an infographic’s check found problems or did not answer (D-057)', () => {
        const noop = () => undefined;
        const row = (review: unknown) =>
            renderToStaticMarkup(
                <StudioItemRow
                    item={
                        {
                            ...base,
                            kind: 'infographic',
                            settings: { review },
                        } as never
                    }
                    onOpen={noop}
                    onDelete={noop}
                    onRetry={noop}
                />,
            );

        expect(row({ verdict: 'bad', attempts: 2, problems: ['x'] })).toContain(
            'The check found problems',
        );
        expect(row({ verdict: 'unavailable', attempts: 1 })).toContain(
            'Not checked',
        );
        expect(row({ verdict: 'good', attempts: 1 })).not.toContain(
            'studio-review',
        );
    });

    it('shows which model and plan made the item, quietly, in the row (release 3)', () => {
        const noop = () => undefined;
        const row = renderToStaticMarkup(
            <StudioItemRow
                item={
                    {
                        ...base,
                        settings: {
                            tier: 'pro',
                            models: {
                                text: {
                                    provider: 'anthropic',
                                    model: 'claude-sonnet-5-5',
                                },
                            },
                        },
                    } as never
                }
                onOpen={noop}
                onDelete={noop}
                onRetry={noop}
            />,
        );

        expect(row).toContain('data-testid="model-badges"');
        expect(row).toContain('Claude Sonnet · Pro');
    });

    it('shows the audio length and the recorded source count in the second line', () => {
        const noop = () => undefined;
        const row = renderToStaticMarkup(
            <StudioItemRow
                item={{
                    ...base,
                    kind: 'audio',
                    sources_count: 3,
                    duration_seconds: 185,
                }}
                onOpen={noop}
                onDelete={noop}
                onRetry={noop}
            />,
        );

        expect(row).toContain('3 sources');
        expect(row).toContain('3:05');
    });
});

describe('How this was made', () => {
    it('says the podcast format and host in words and keeps their raw keys out of Choices', () => {
        const rows = describeSettings({
            ...base,
            kind: 'audio',
            settings: {
                tier: 'pro',
                options: {
                    format: 'critique',
                    persona: 'rosa',
                    length: 'short',
                },
                podcast: {
                    format: 'Critique',
                    persona: {
                        name: 'Rosa Delgado',
                        role: 'The mother of five',
                    },
                },
            },
        });
        const get = (label: string) => rows?.find(([l]) => l === label)?.[1];

        expect(get('Format')).toBe('Critique');
        expect(get('Host')).toBe('Rosa Delgado, The mother of five');
        expect(get('Choices')).toBe('length: short');
    });

    it('says so plainly for an item made before settings were recorded', () => {
        expect(describeSettings({ ...base, settings: null })).toBeNull();
    });

    it('lists sources, language, models, voices, length and cost from the recorded settings', () => {
        const rows = describeSettings({
            ...base,
            kind: 'audio',
            duration_seconds: 185,
            settings: {
                sources: [
                    { id: 1, title: 'Doc A' },
                    { id: 2, title: 'Doc B' },
                ],
                language: 'auto',
                instructions: null,
                tier: 'pro',
                models: {
                    text: { provider: 'anthropic', model: 'claude-sonnet-5-5' },
                    speech: {
                        provider: 'elevenlabs',
                        model: 'eleven_v3',
                        voices: { A: 'v1', B: 'v2' },
                    },
                },
                cost_micro_usd: 123456,
            },
        });
        const map = Object.fromEntries(rows ?? []);

        expect(map.Sources).toBe('Doc A, Doc B');
        expect(map.Language).toBe('The language of the sources');
        expect(map.Instructions).toBe('None');
        expect(map['Writing model']).toContain('claude-sonnet-5-5');
        expect(map.Voice).toContain('A: v1, B: v2');
        expect(map.Length).toBe('3:05');
        expect(map.Cost).toBe('$0.1235');
    });
});
