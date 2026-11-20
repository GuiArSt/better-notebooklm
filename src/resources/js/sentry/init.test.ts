import * as Sentry from '@sentry/react';
import type { ErrorEvent } from '@sentry/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initSentry, scrub } from '@/sentry/init';

vi.mock('@sentry/react', async (original) => ({
    ...(await original<typeof import('@sentry/react')>()),
    init: vi.fn(),
}));

describe('sentry', () => {
    afterEach(() => vi.mocked(Sentry.init).mockClear());

    it('stays off without a DSN', () => {
        const init = vi.mocked(Sentry.init);

        expect(initSentry(null)).toBe(false);
        expect(initSentry('')).toBe(false);
        expect(initSentry(undefined)).toBe(false);
        expect(init).not.toHaveBeenCalled();
    });

    it('turns on errors only with a DSN: no tracing, no breadcrumbs', () => {
        const init = vi.mocked(Sentry.init);

        expect(
            initSentry('https://k@o0.ingest.de.sentry.io/1', 'dev', 'abc123'),
        ).toBe(true);
        const options = init.mock.calls[0][0]!;
        expect(options).toMatchObject({
            tracesSampleRate: 0,
            environment: 'dev',
            release: 'abc123',
        });
        expect(
            options.beforeBreadcrumb!({ message: 'typed text' }, undefined),
        ).toBeNull();
    });

    it('scrubs text, query strings and breadcrumbs, keeping the class and the user id', () => {
        const event = {
            type: undefined,
            message: 'a fake prompt',
            user: { id: 7, email: 'jane@example.com', ip_address: '1.2.3.4' },
            request: {
                url: 'https://app.test/notebooks/3?q=a fake prompt#x',
                headers: { cookie: 'secret' },
            },
            breadcrumbs: [{ message: 'a fake prompt' }],
            extra: { title: 'private' },
            exception: {
                values: [
                    {
                        type: 'TypeError',
                        value: 'a fake prompt jane@example.com',
                    },
                ],
            },
        } as unknown as ErrorEvent;

        const out = JSON.stringify(scrub(event));

        expect(out).toContain('TypeError');
        expect(out).toContain('"id":7');
        expect(out).toContain('/notebooks/3"');
        for (const leak of [
            'fake prompt',
            'jane',
            'secret',
            '1.2.3.4',
            'private',
            '?q',
        ]) {
            expect(out).not.toContain(leak);
        }
    });
});
