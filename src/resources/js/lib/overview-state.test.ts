import { describe, expect, it } from 'vitest';
import { NO_OVERVIEW, overviewView } from '@/lib/overview-state';

describe('overviewView', () => {
    it('says the overview is being written while it is building, even with an old summary', () => {
        expect(
            overviewView({ summary: 'old', questions: [], status: 'building' }),
        ).toEqual({ state: 'building', text: 'Writing the overview…' });
    });

    it('says it could not be made when it failed or could not be loaded', () => {
        expect(
            overviewView({ summary: null, questions: [], status: 'failed' })
                .state,
        ).toBe('failed');
        expect(overviewView(null, true).state).toBe('failed');
    });

    it('shows the summary when there is one', () => {
        expect(
            overviewView({
                summary: 'Orion flew.',
                questions: [],
                status: 'ready',
            }),
        ).toEqual({ state: 'ready', text: 'Orion flew.' });
    });

    it('keeps the plain line only when there is truly no overview and nothing is building', () => {
        expect(overviewView(null)).toEqual({
            state: 'none',
            text: NO_OVERVIEW,
        });
        expect(overviewView({ summary: null, questions: [] })).toEqual({
            state: 'none',
            text: NO_OVERVIEW,
        });
    });

    it('treats status null (never asked) and ready-without-summary as no overview', () => {
        expect(
            overviewView({ summary: null, questions: [], status: null }).state,
        ).toBe('none');
        expect(
            overviewView({ summary: null, questions: [], status: 'ready' })
                .state,
        ).toBe('none');
    });
});
