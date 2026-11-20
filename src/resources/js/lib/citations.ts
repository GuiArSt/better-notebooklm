/**
 * A citation marker in model text: one passage, `[P12]`, or several in one bracket, `[P260, P261]`, `[P253; 273]`; spaces inside the
 * brackets are allowed (`[ P12 ]`). It is the same pattern as the server's CitationValidator::BRACKET.
 * The server validates the single form; a group is split here and each id is checked against the validated set.
 */
export const CITATION_GROUP = /\[\s*P\d+(?:\s*[,;]\s*P?\d+)*\s*\]/;

export const CITATION_GROUP_GLOBAL = /\s*\[\s*P\d+(?:\s*[,;]\s*P?\d+)*\s*\]/g;

/** The passage ids named by one marker, in order and without repeats: "[P260, P261]" gives [260, 261]. */
export function citationIds(marker: string): number[] {
    const ids = (marker.match(/\d+/g) ?? []).map(Number);

    return ids.filter((id, i) => ids.indexOf(id) === i);
}

/** Text with every citation marker removed, for copying. */
export function stripCitations(text: string): string {
    return text.replace(CITATION_GROUP_GLOBAL, '');
}
