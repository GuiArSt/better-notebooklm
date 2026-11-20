import { describe, expect, it } from 'vitest';
import {
    FORMS,
    buildRequest,
    choicesFor,
    choose,
    defaultOptions,
    estimateQuery,
    usageBar,
} from '@/lib/customize';
import type { Podcast } from '@/lib/customize';

const PODCAST: Podcast = {
    formats: [
        { value: 'deep_dive', label: 'Deep Dive' },
        { value: 'brief', label: 'Brief' },
    ],
    default_format: 'deep_dive',
    brief_length: 'short',
    personas: [],
};

describe('the Customize step', () => {
    it('offers each live kind its own choice and example prompts', () => {
        expect(FORMS.audio.choices.map((c) => c.id)).toEqual(['length']);
        expect(
            choicesFor('audio', { podcast: PODCAST }).map((c) => c.id),
        ).toEqual(['format', 'length']);
        expect(FORMS.quiz.choices.map((c) => c.id)).toEqual([
            'questions',
            'difficulty',
        ]);
        expect(FORMS.slides.choices.map((c) => c.id)).toEqual(['slides']);
        expect(FORMS.mindmap.choices).toEqual([]);

        for (const form of Object.values(FORMS)) {
            expect(form.focusExamples.length).toBeGreaterThanOrEqual(3);
        }

        expect(FORMS.audio.choices[0].options.map((o) => o.label)).toEqual([
            'Short',
            'Default',
            'Long',
        ]);
    });

    it('starts from each kind’s defaults and builds the request with sorted sources and a trimmed focus', () => {
        expect(defaultOptions('quiz')).toEqual({
            questions: '6',
            difficulty: 'medium',
        });

        const request = buildRequest('quiz', {
            language: 'fr',
            sourceIds: [9, 2, 5],
            focus: '  only dates  ',
            options: { difficulty: 'hard' },
        });

        expect(request).toEqual({
            kind: 'quiz',
            language: 'fr',
            sources: [2, 5, 9],
            focus: 'only dates',
            options: { questions: '6', difficulty: 'hard' },
        });
    });

    it('caps the focus at 500 characters', () => {
        expect(
            buildRequest('faq', {
                language: 'auto',
                sourceIds: [1],
                focus: 'x'.repeat(900),
                options: {},
            }).focus,
        ).toHaveLength(500);
    });

    it('draws the bar: already used, then the estimate, flagged when it passes the budget', () => {
        expect(usageBar(1, 5, null)).toEqual({
            used: 20,
            added: 0,
            over: false,
        });
        expect(usageBar(1, 5, 0.5)).toEqual({
            used: 20,
            added: 10,
            over: false,
        });
        expect(usageBar(4.9, 5, 0.5).over).toBe(true);
        expect(
            usageBar(4.9, 5, 0.5).used + usageBar(4.9, 5, 0.5).added,
        ).toBeLessThanOrEqual(100);
    });

    it('asks for the estimate with the same choices as the request, as the server names them', () => {
        const q = estimateQuery(
            buildRequest('slides', {
                language: 'de',
                sourceIds: [3, 1],
                focus: 'x',
                options: { slides: '10' },
            }),
        );
        const params = new URLSearchParams(q);

        expect(params.get('kind')).toBe('slides');
        expect(params.get('language')).toBe('de');
        expect(params.getAll('sources[]')).toEqual(['1', '3']);
        expect(params.get('options[slides]')).toBe('10');
        expect(params.has('focus')).toBe(false);
    });

    it('offers only the options the server accepts for each kind', () => {
        expect(FORMS.audio.choices[0].options.map((o) => o.value)).toEqual([
            'short',
            'default',
            'long',
        ]);
        expect(FORMS.quiz.choices[0].options.map((o) => o.value)).toEqual([
            '5',
            '6',
            '7',
            '8',
        ]);
        expect(FORMS.slides.choices[0].options.map((o) => o.value)).toEqual([
            '6',
            '8',
            '10',
            '12',
        ]);
    });

    it('asks for the estimate with the same choices as the request, as the server names them', () => {
        const q = estimateQuery(
            buildRequest('slides', {
                language: 'de',
                sourceIds: [3, 1],
                focus: 'x',
                options: { slides: '10' },
            }),
        );
        const params = new URLSearchParams(q);

        expect(params.get('kind')).toBe('slides');
        expect(params.get('language')).toBe('de');
        expect(params.getAll('sources[]')).toEqual(['1', '3']);
        expect(params.get('options[slides]')).toBe('10');
        expect(params.has('focus')).toBe(false);
    });

    it('offers only the options the server accepts for each kind', () => {
        expect(FORMS.audio.choices[0].options.map((o) => o.value)).toEqual([
            'short',
            'default',
            'long',
        ]);
        expect(FORMS.quiz.choices[0].options.map((o) => o.value)).toEqual([
            '5',
            '6',
            '7',
            '8',
        ]);
        expect(FORMS.slides.choices[0].options.map((o) => o.value)).toEqual([
            '6',
            '8',
            '10',
            '12',
        ]);
    });

    it('takes the podcast formats from the server, first, with Deep Dive as the default (D-039)', () => {
        const format = choicesFor('audio', { podcast: PODCAST })[0];

        expect(format.options.map((o) => [o.value, o.label])).toEqual([
            ['deep_dive', 'Deep Dive'],
            ['brief', 'Brief'],
        ]);
        expect(format.options[0].hint).toBeTruthy();
        expect(defaultOptions('audio', { podcast: PODCAST })).toEqual({
            format: 'deep_dive',
            length: 'default',
        });
        expect(choicesFor('quiz', { podcast: PODCAST })).toBe(
            FORMS.quiz.choices,
        );
    });

    it('sends format and persona in the estimate query, a persona only when picked', () => {
        const base = {
            language: 'en',
            sourceIds: [1],
            focus: '',
        };
        const plain = estimateQuery(
            buildRequest(
                'audio',
                { ...base, options: {} },
                { podcast: PODCAST },
            ),
        );
        const persona = estimateQuery(
            buildRequest('audio', {
                ...base,
                options: { format: 'debate', persona: 'rosa' },
            }),
        );

        expect(decodeURIComponent(plain)).toContain(
            'options[format]=deep_dive',
        );
        expect(plain).not.toContain('persona');
        expect(decodeURIComponent(persona)).toContain('options[format]=debate');
        expect(decodeURIComponent(persona)).toContain('options[persona]=rosa');
    });

    it('starts a Brief on its own length unless the user chose a length (Argus: Brief never started on Short)', () => {
        const start = {
            options: defaultOptions('audio', { podcast: PODCAST }),
            touched: [],
        };
        const brief = choose('audio', start, 'format', 'brief', {
            podcast: PODCAST,
        });

        expect(brief.options).toEqual({ format: 'brief', length: 'short' });

        const back = choose('audio', brief, 'format', 'deep_dive', {
            podcast: PODCAST,
        });

        expect(back.options).toEqual({
            format: 'deep_dive',
            length: 'default',
        });

        const chosen = choose('audio', start, 'length', 'long', {
            podcast: PODCAST,
        });
        const keepsLong = choose('audio', chosen, 'format', 'brief', {
            podcast: PODCAST,
        });

        expect(keepsLong.options).toEqual({ format: 'brief', length: 'long' });
    });

    it('offers an infographic the server’s orientation, style and detail, and a description box', () => {
        const infographic = {
            available: true,
            orientations: [{ value: 'landscape', label: 'Landscape' }],
            styles: [{ value: 'auto', label: 'Auto-select' }],
            details: [{ value: 'standard', label: 'Standard' }],
            default_orientation: 'landscape',
            default_style: 'auto',
            default_detail: 'standard',
        };

        expect(
            choicesFor('infographic', { infographic }).map((c) => c.id),
        ).toEqual(['orientation', 'style', 'detail']);
        expect(defaultOptions('infographic', { infographic })).toEqual({
            orientation: 'landscape',
            style: 'auto',
            detail: 'standard',
        });
        expect(FORMS.infographic.focusLabel).toBe(
            'Describe the infographic you want',
        );
        expect(
            estimateQuery(
                buildRequest(
                    'infographic',
                    { language: 'en', sourceIds: [1], focus: '', options: {} },
                    { infographic },
                ),
            ),
        ).toContain('options%5Bstyle%5D=auto');
    });

    it('draws the style row as tiles and keeps the short rows as pills', () => {
        const infographic = {
            available: true,
            orientations: [{ value: 'landscape', label: 'Landscape' }],
            styles: [{ value: 'auto', label: 'Auto-select' }],
            details: [{ value: 'standard', label: 'Standard' }],
            default_orientation: 'landscape',
            default_style: 'auto',
            default_detail: 'standard',
        };
        const rows = choicesFor('infographic', { infographic });

        expect(rows.find((c) => c.id === 'style')?.tiles).toBe(true);
        expect(rows.filter((c) => c.tiles)).toHaveLength(1);
    });
});
