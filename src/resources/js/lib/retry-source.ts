import { router } from '@inertiajs/react';

/** The route that sets a failed source back to pending and reads it again (Argus gap 5). */
export const retryUrl = (notebookId: number, sourceId: number): string =>
    `/notebooks/${notebookId}/sources/${sourceId}/retry`;

/** Asks the server to read a failed source again; a refusal arrives through the global error toast. */
export function retrySource(
    notebookId: number,
    sourceId: number,
    onSuccess?: () => void,
): void {
    router.post(
        retryUrl(notebookId, sourceId),
        {},
        { preserveScroll: true, onSuccess },
    );
}
