import type { TileKey } from '@/lib/studio-tints';
import type { StudioItem, StudioKind } from '@/types/studio';

/** What each kind is called and which Studio tile makes it: the tile's icon and tint are its identity everywhere. */
export const KINDS: Record<StudioKind, { label: string; tile: TileKey }> = {
    audio: { label: 'Audio overview', tile: 'audio' },
    briefing: { label: 'Briefing', tile: 'reports' },
    study_guide: { label: 'Study guide', tile: 'reports' },
    faq: { label: 'FAQ', tile: 'reports' },
    mindmap: { label: 'Mind map', tile: 'mindmap' },
    quiz: { label: 'Quiz', tile: 'quiz' },
    slides: { label: 'Slide deck', tile: 'slides' },
    infographic: { label: 'Infographic', tile: 'infographic' },
};

/** "Today 17:42", "Yesterday 09:05" or "Nov 17, 16:30". */
export function whenLabel(iso: string, now: Date = new Date()): string {
    const d = new Date(iso);
    const time = d.toLocaleTimeString('en', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    const day = (x: Date) =>
        `${x.getFullYear()}-${x.getMonth()}-${x.getDate()}`;
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (day(d) === day(now)) {
        return `Today ${time}`;
    }

    if (day(d) === day(yesterday)) {
        return `Yesterday ${time}`;
    }

    return `${d.toLocaleDateString('en', { month: 'short', day: 'numeric' })}, ${time}`;
}

/** How many different sources an item's citations point to (0 when it has none to count). */
export const citedSourceCount = (item: StudioItem) =>
    new Set((item.citations ?? []).map((c) => c.source_id)).size;

/** "3:05" from a number of seconds (whole seconds, never "1:60"; 0 for a missing or negative value). */
export const clock = (seconds: number) => {
    const s = Math.max(0, Math.floor(seconds || 0));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
};
