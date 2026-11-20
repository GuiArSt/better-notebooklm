import { describe, expect, it } from 'vitest';
import { failureMessage, readChatEvents } from '@/lib/chat-stream';
import type { ChatEvent } from '@/lib/chat-stream';

const bodyOf = (chunks: string[]) =>
    new ReadableStream<Uint8Array>({
        start(controller) {
            for (const chunk of chunks) {
                controller.enqueue(new TextEncoder().encode(chunk));
            }

            controller.close();
        },
    });

const read = async (chunks: string[]) => {
    const events: ChatEvent[] = [];
    const finished = await readChatEvents(bodyOf(chunks), (e) =>
        events.push(e),
    );

    return { events, finished };
};

describe('readChatEvents', () => {
    it('reads the events our server sends and sees done', async () => {
        const { events, finished } = await read([
            'data: {"type":"text","delta":"Hi"}\n\ndata: {"type":"done"}\n\n',
        ]);

        expect(events).toEqual([
            { type: 'text', delta: 'Hi' },
            { type: 'done' },
        ]);
        expect(finished).toBe(true);
    });

    it('reads CRLF line endings and data without a space', async () => {
        const { events, finished } = await read([
            'data:{"type":"text","delta":"a"}\r\n\r\ndata: {"type":"done"}\r\n\r\n',
        ]);

        expect(events).toHaveLength(2);
        expect(finished).toBe(true);
    });

    it('survives a proxy that splits the stream in the middle of an event', async () => {
        const whole =
            'data: {"type":"text","delta":"Héllo"}\n\ndata: {"type":"done"}\n\n';
        const bytes = new TextEncoder().encode(whole);
        const cut = 30;
        // Split inside the multi-byte "é" too: the decoder must carry it over.
        const at = bytes.indexOf(0xc3) + 1;
        const body = new ReadableStream<Uint8Array>({
            start(controller) {
                controller.enqueue(bytes.slice(0, cut));
                controller.enqueue(bytes.slice(cut, at));
                controller.enqueue(bytes.slice(at));
                controller.close();
            },
        });
        const events: ChatEvent[] = [];
        const finished = await readChatEvents(body, (e) => events.push(e));

        expect(events[0]).toEqual({ type: 'text', delta: 'Héllo' });
        expect(finished).toBe(true);
    });

    it('passes the server’s error event on, after the partial answer and before done (a cut answer, Argus gap 3)', async () => {
        const { events, finished } = await read([
            'data: {"type":"text","delta":"Half"}\n\n',
            'data: {"type":"error","message":"The answer hit its length limit."}\n\ndata: {"type":"done"}\n\n',
        ]);

        expect(events.map((e) => e.type)).toEqual(['text', 'error', 'done']);
        expect(events[1]).toEqual({
            type: 'error',
            message: 'The answer hit its length limit.',
        });
        expect(finished).toBe(true);
    });

    it('ignores comments and keep-alives', async () => {
        const { events } = await read([
            ': keep-alive\n\ndata: {"type":"done"}\n\n',
        ]);

        expect(events).toEqual([{ type: 'done' }]);
    });

    it('reports a stream that ended without done', async () => {
        const { events, finished } = await read([
            'data: {"type":"text","delta":"Half an ans',
            'wer"}\n\n',
        ]);

        expect(events).toEqual([{ type: 'text', delta: 'Half an answer' }]);
        expect(finished).toBe(false);
    });
});

describe('failureMessage', () => {
    it('tells an expired session to reload', () => {
        expect(failureMessage(419)).toBe(
            'Your session expired. Reload the page.',
        );
    });

    it('names any other status', () => {
        expect(failureMessage(500)).toBe('The server answered 500.');
    });
});
