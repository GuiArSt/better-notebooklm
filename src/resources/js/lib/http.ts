/** The CSRF token Laravel keeps in a cookie, for requests made with fetch instead of Inertia. */
export function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new HttpError(
            response.status,
            await response.json().catch(() => null),
        );
    }

    return (await response.json()) as T;
}

/** A failed request: its status and the server's JSON body, so a form can show Laravel's validation messages. */
export class HttpError extends Error {
    constructor(
        public status: number,
        public body: {
            message?: string;
            errors?: Record<string, string[]>;
        } | null,
    ) {
        super(`The server answered ${status}.`);
    }

    /** Every message the server gave, one per line: the validation errors, else its message. */
    messages(): string[] {
        const errors = Object.values(this.body?.errors ?? {}).flat();

        return errors.length > 0
            ? errors
            : this.body?.message
              ? [this.body.message]
              : [this.message];
    }
}

export async function sendJson<T>(
    url: string,
    method: 'POST' | 'DELETE' | 'PATCH',
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        throw new HttpError(
            response.status,
            await response.json().catch(() => null),
        );
    }

    return (await response.json().catch(() => ({}))) as T;
}
