import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
    new URL('./audio-player.tsx', import.meta.url),
    'utf8',
);

describe('the audio player says when it cannot play (HF-7)', () => {
    it('catches a refused play() and a failed load, and shows a red line', () => {
        expect(source).not.toContain('void el.play()');
        expect(source).toContain('el.play().catch(() => setFailed(true))');
        expect(source).toContain('onError={() => setFailed(true)}');
        expect(source).toContain('data-testid="audio-failed"');
    });
});
