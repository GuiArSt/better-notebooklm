import * as Sentry from '@sentry/react';
import type { ErrorEvent } from '@sentry/react';

/** The page's address without its query string or fragment: a query can carry what a user typed. */
const bare = (url?: string): string | undefined => url?.split(/[?#]/)[0];

/** What leaves for Sentry (D-043): the error class, the place in the code, the path and the user id. No text, no query, no breadcrumbs. */
export function scrub(event: ErrorEvent): ErrorEvent {
    const id = event.user?.id;
    event.user = id === undefined ? undefined : { id };
    event.request = event.request?.url
        ? { url: bare(event.request.url) }
        : undefined;
    event.breadcrumbs = [];
    event.extra = undefined;
    event.message = undefined;
    for (const exception of event.exception?.values ?? []) {
        exception.value = '';
    }

    return event;
}

/** A click or a console line can hold text: no breadcrumb at all. */
const noBreadcrumb = (): null => null;

let started = false;

/** On only when the server handed over a DSN. Returns whether it is on. Errors only: no replay, no tracing. */
export function initSentry(
    dsn: string | null | undefined,
    environment?: string | null,
    release?: string | null,
): boolean {
    if (!dsn) {
        return false;
    }

    if (started) {
        return true;
    }

    started = true;

    Sentry.init({
        dsn,
        environment: environment ?? undefined,
        release: release ?? undefined,
        tracesSampleRate: 0,
        // Only the handlers for uncaught errors: the defaults add breadcrumbs (clicks, console, fetch) we never send.
        integrations: [
            Sentry.globalHandlersIntegration(),
            Sentry.dedupeIntegration(),
        ],
        defaultIntegrations: false,
        beforeSend: scrub,
        beforeBreadcrumb: noBreadcrumb,
    });

    return true;
}
