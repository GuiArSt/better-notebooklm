import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('Privacy and Terms keep the app nav when signed in (visual pass 6)', () => {
    it('wear the frame layout for a signed-in user and the slim bar for a visitor', () => {
        const source = readFileSync(
            new URL('./public-layout.tsx', import.meta.url),
            'utf8',
        );

        expect(source).toContain('if (auth.user) {');
        expect(source).toContain('<FrameLayout>{children}</FrameLayout>');
        expect(source).toContain('<SiteFooter />');
    });
});
