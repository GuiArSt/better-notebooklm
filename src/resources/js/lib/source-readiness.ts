import type { Source } from '@/types/app';

/** Sources still being read: they will be ready soon, so "check a ready source" would be the wrong thing to say. */
export const readingCount = (sources: Source[]): number =>
    sources.filter((s) => s.status === 'pending').length;

/** What to say when nothing can be used yet: reading, with the progress, while any source is still being read; null when something is ready. */
export function readingHint(sources: Source[]): string | null {
    const reading = readingCount(sources);

    if (reading === 0) {
        return null;
    }

    const ready = sources.filter((s) => s.status === 'ready').length;

    return `Reading your sources… ${ready} of ${ready + reading} ready.`;
}
