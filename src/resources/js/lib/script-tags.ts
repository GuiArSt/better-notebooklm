/** A piece of a spoken line: plain words, or an audio tag such as [whispers] (a delivery cue for the voice, not words to read). */
export type ScriptPart = { text: string; tag: boolean };

/** Splits a script line at its [tags], so the view can show them as small muted chips instead of raw brackets. */
export function scriptParts(line: string): ScriptPart[] {
    return line
        .split(/\[([^\]\n]{1,30})\]/)
        .map((text, i) => ({ text: text.trim(), tag: i % 2 === 1 }))
        .filter((part) => part.text !== '');
}
