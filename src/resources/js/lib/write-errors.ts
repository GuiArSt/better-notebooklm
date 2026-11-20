/** The message to toast for a refused write inside a notebook (a note too long, too many citations); null for anything else. */
export function writeErrorMessage(
    url: URL,
    errors: Record<string, string>,
): string | null {
    if (!url.pathname.startsWith('/notebooks/')) {
        return null;
    }

    return Object.values(errors)[0] ?? null;
}
