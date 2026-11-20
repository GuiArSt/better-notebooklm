const dayKey = (d: Date) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;

/** "Today", "Yesterday", or "Thursday, November 19": the day a message was asked, not the day it is read. */
export function dayHeader(iso: string, now: Date = new Date()): string {
    const d = new Date(iso);
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (dayKey(d) === dayKey(now)) {
        return 'Today';
    }

    if (dayKey(d) === dayKey(yesterday)) {
        return 'Yesterday';
    }

    return d.toLocaleDateString('en', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });
}

/** The header to show above turn `i`: its day, when it is the first turn or a different day than the one before; null for a turn with no recorded time. */
export function headerBefore(
    turns: { asked_at?: string }[],
    i: number,
    now: Date = new Date(),
): string | null {
    const at = turns[i]?.asked_at;

    if (!at) {
        return null;
    }

    const before = turns[i - 1]?.asked_at;

    return before && dayKey(new Date(before)) === dayKey(new Date(at))
        ? null
        : dayHeader(at, now);
}
