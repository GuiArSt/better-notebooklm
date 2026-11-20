import { createParser } from 'eventsource-parser';
import type { Citation } from '@/types/app';

/** One event of a chat answer: text, the validated citations, an error, or `done` (always the last one the server sends). */
export type ChatEvent =
    | { type: 'text'; delta?: string }
    | { type: 'citations'; citations?: Citation[] }
    | { type: 'error'; message?: string }
    | { type: 'done' };

/** The most the server accepts in one question (ChatController's rule). */
export const MAX_QUESTION = 2000;

export const CUT_OFF = 'The answer was cut off. Ask again.';

/** Feeds a response body to the SSE parser and calls `on` for each event. True when the server's `done` arrived. */
export async function readChatEvents(
    body: ReadableStream<Uint8Array>,
    on: (event: ChatEvent) => void,
): Promise<boolean> {
    let finished = false;
    const parser = createParser({
        onEvent: (message) => {
            const event = JSON.parse(message.data) as ChatEvent;

            finished ||= event.type === 'done';
            on(event);
        },
    });
    const reader = body.getReader();
    const decoder = new TextDecoder();

    for (;;) {
        const { value, done } = await reader.read();

        if (done) {
            return finished;
        }

        parser.feed(decoder.decode(value, { stream: true }));
    }
}

/** What to tell the user when the chat route itself answers with an error status. */
export function failureMessage(status: number): string {
    return status === 419
        ? 'Your session expired. Reload the page.'
        : `The server answered ${status}.`;
}
