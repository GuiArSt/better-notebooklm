import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(new URL('./show.tsx', import.meta.url), 'utf8');

describe('the notebook page polls through Inertia (HL-6)', () => {
    it('uses usePoll, started only while a source is pending, not its own setInterval', () => {
        expect(source).not.toContain('setInterval');
        expect(source).toContain('usePoll(');
        expect(source).toContain('autoStart: false');
        expect(source).toContain("s.status === 'pending'");
    });
});
