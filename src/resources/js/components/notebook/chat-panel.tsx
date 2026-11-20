import { router } from '@inertiajs/react';
import { ArrowUp, Copy, Pin, SlidersHorizontal, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { NotebookIcon } from '@/components/notebook-icon';
import { CitedText } from '@/components/notebook/citation';
import { Button } from '@/components/ui/button';
import { CHAT_KEY_PREFIX } from '@/lib/chat-storage';
import {
    CUT_OFF,
    MAX_QUESTION,
    failureMessage,
    readChatEvents,
} from '@/lib/chat-stream';
import type { ChatEvent } from '@/lib/chat-stream';
import { headerBefore } from '@/lib/chat-days';
import { csrfToken, getJson, sendJson } from '@/lib/http';
import { overviewView } from '@/lib/overview-state';
import type { Overview } from '@/lib/overview-state';
import { readingHint } from '@/lib/source-readiness';
import { stripCitations } from '@/lib/citations';
import type { Passage, Source, Turn } from '@/types/app';

const storageKey = (notebookId: number) => `${CHAT_KEY_PREFIX}${notebookId}`;

function loadTurns(notebookId: number): Turn[] {
    try {
        const saved = localStorage.getItem(storageKey(notebookId));

        return saved ? (JSON.parse(saved) as Turn[]) : [];
    } catch {
        return [];
    }
}

function saveTurns(notebookId: number, turns: Turn[]) {
    try {
        localStorage.setItem(
            storageKey(notebookId),
            JSON.stringify(turns.filter((t) => t.citations !== null)),
        );
    } catch {
        // Private windows and full storage: the chat simply is not remembered.
    }
}

/** Asks one question and calls `on` for each event of the answer. True when the server finished it (sent `done`). */
async function streamAnswer(
    notebookId: number,
    question: string,
    on: (event: ChatEvent) => void,
): Promise<boolean> {
    const response = await fetch(`/notebooks/${notebookId}/chat`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ question }),
    });

    if (!response.ok || !response.body) {
        throw new Error(failureMessage(response.status));
    }

    return readChatEvents(response.body, on);
}

export function ChatPanel({
    notebook,
    notebookId,
    sources,
    onOpenCitation,
    request,
}: {
    notebook: {
        id: number;
        title: string;
        emoji: string;
        icon_status?: string | null;
    };
    notebookId: number;
    sources: Source[];
    onOpenCitation: (passage: Passage) => void;
    /** A question asked from elsewhere (a mind map node); `id` makes the same text count again. */
    request: { id: number; text: string } | null;
}) {
    const [turns, setTurns] = useState<Turn[]>(() => loadTurns(notebookId));
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [overview, setOverview] = useState<Overview | null>(null);
    const [overviewFailed, setOverviewFailed] = useState(false);
    // Bumped to fetch the overview again: while it is being written, and from the retry button.
    const [overviewTick, setOverviewTick] = useState(0);
    const bottom = useRef<HTMLDivElement>(null);

    const checked = sources.filter((s) => s.checked && s.status === 'ready');
    const readyKey = checked.map((s) => s.id).join(',');

    // The notebook's opening summary and three questions, rebuilt when the checked sources change.
    useEffect(() => {
        if (!readyKey) {
            return;
        }

        let live = true;
        let timer: ReturnType<typeof setTimeout> | undefined;

        getJson<Overview>(`/notebooks/${notebookId}/overview`)
            .then((o) => {
                if (!live) {
                    return;
                }

                setOverview(o);
                setOverviewFailed(false);

                if (o.status === 'building') {
                    timer = setTimeout(
                        () => setOverviewTick((t) => t + 1),
                        3000,
                    );
                }
            })
            .catch(() => live && setOverviewFailed(true));

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [notebookId, readyKey, overviewTick]);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [turns]);

    const ask = async (text: string) => {
        const q = text.trim();

        if (!q || busy) {
            return;
        }

        setQuestion('');
        setBusy(true);
        setTurns((t) => [
            ...t,
            {
                question: q,
                answer: '',
                citations: null,
                error: null,
                asked_at: new Date().toISOString(),
            },
        ]);

        const update = (patch: (t: Turn) => Turn) =>
            setTurns((all) =>
                all.map((t, i) => (i === all.length - 1 ? patch(t) : t)),
            );

        try {
            const finished = await streamAnswer(notebookId, q, (event) => {
                if (event.type === 'text') {
                    update((t) => ({
                        ...t,
                        answer: t.answer + (event.delta ?? ''),
                    }));
                } else if (event.type === 'citations') {
                    update((t) => ({ ...t, citations: event.citations ?? [] }));
                } else if (event.type === 'error') {
                    update((t) => ({
                        ...t,
                        error: event.message ?? 'Something went wrong.',
                        citations: t.citations ?? [],
                    }));
                }
            });

            if (!finished) {
                update((t) => ({
                    ...t,
                    error: t.error ?? CUT_OFF,
                    citations: t.citations ?? [],
                }));
            }
        } catch (err) {
            update((t) => ({
                ...t,
                error:
                    err instanceof Error
                        ? err.message
                        : 'Something went wrong.',
                citations: [],
            }));
        } finally {
            setTurns((all) => {
                const done = all.map((t, i) =>
                    i === all.length - 1
                        ? { ...t, citations: t.citations ?? [] }
                        : t,
                );
                saveTurns(notebookId, done);

                return done;
            });
            setBusy(false);
            router.reload({ only: ['usage'] });
        }
    };

    const lastRequest = useRef(0);

    useEffect(() => {
        if (request && request.id !== lastRequest.current) {
            lastRequest.current = request.id;
            void ask(request.text);
        }
        // `ask` changes every render; the request id is what should trigger this.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [request]);

    const saveToNote = (index: number) => {
        const turn = turns[index];

        router.post(
            `/notebooks/${notebookId}/notes`,
            {
                title: turn.question.slice(0, 80),
                content: turn.answer,
                citations: turn.citations ?? [],
                from_chat: true,
            },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setTurns((all) => {
                        const next = all.map((t, i) =>
                            i === index ? { ...t, saved: true } : t,
                        );
                        saveTurns(notebookId, next);

                        return next;
                    }),
            },
        );
    };

    const clear = () => {
        setTurns([]);
        saveTurns(notebookId, []);
    };

    // Asks the server to write the overview again; until it answers, the chat says it is being written.
    const refreshOverview = () => {
        sendJson(`/notebooks/${notebookId}/overview`, 'POST')
            .then(() => {
                setOverviewFailed(false);
                setOverview((o) => ({
                    summary: o?.summary ?? null,
                    questions: o?.questions ?? [],
                    status: 'building',
                }));
                setOverviewTick((t) => t + 1);
            })
            .catch(() => setOverviewFailed(true));
    };

    const nothingReady = checked.length === 0;
    const view = overviewView(overview, overviewFailed);
    const copyAnswer = (text: string) =>
        void navigator.clipboard?.writeText(stripCitations(text));

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div
                className="flex-1 overflow-y-auto px-2 sm:px-6"
                data-testid="chat"
            >
                <div className="mx-auto w-full max-w-3xl space-y-6 pb-4">
                    <header className="space-y-1 pt-6" data-testid="chat-hero">
                        <NotebookIcon
                            id={notebook.id}
                            emoji={notebook.emoji}
                            status={notebook.icon_status}
                            size="xl"
                        />
                        <h2 className="pt-4 text-3xl leading-tight font-normal sm:text-display">
                            {notebook.title}
                        </h2>
                        <p className="text-body text-muted-foreground">
                            {sources.length}{' '}
                            {sources.length === 1 ? 'source' : 'sources'}
                        </p>
                    </header>

                    {turns.length === 0 && (
                        <div className="space-y-4 pt-4">
                            {nothingReady ? (
                                <div className="rounded-3xl bg-card-soft p-6 text-center text-body text-muted-foreground">
                                    {sources.length === 0
                                        ? 'Add a source (a PDF, a web page or some pasted text) and ask questions about it here.'
                                        : (readingHint(sources) ??
                                          'Check at least one ready source in the Sources panel to chat with it.')}
                                </div>
                            ) : (
                                <>
                                    <div
                                        className="rounded-3xl bg-card-soft p-5"
                                        data-testid="overview"
                                    >
                                        <h3 className="mb-2 flex items-center gap-2 text-body font-medium">
                                            <Sparkles className="size-4" />{' '}
                                            Overview
                                        </h3>
                                        <p
                                            className="text-body leading-relaxed"
                                            data-testid="overview-text"
                                        >
                                            {view.text}
                                        </p>
                                        {view.state === 'failed' && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="mt-3 rounded-full"
                                                onClick={refreshOverview}
                                                data-testid="overview-retry"
                                            >
                                                Try again
                                            </Button>
                                        )}
                                    </div>
                                    {overview &&
                                        overview.questions.length > 0 && (
                                            <div
                                                className="flex flex-col gap-2"
                                                data-testid="suggested"
                                            >
                                                {overview.questions.map((q) => (
                                                    <button
                                                        key={q}
                                                        type="button"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void ask(q)
                                                        }
                                                        className="rounded-full bg-panel px-5 py-3 text-left text-body transition hover:bg-bubble"
                                                    >
                                                        {q}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                </>
                            )}
                        </div>
                    )}

                    {turns.map((t, i) => (
                        <div key={i} className="space-y-3" data-testid="turn">
                            {headerBefore(turns, i) && (
                                <p
                                    className="pt-4 text-center text-caption text-muted-foreground"
                                    data-testid="chat-day"
                                >
                                    {headerBefore(turns, i)}
                                </p>
                            )}
                            <p className="ml-auto w-fit max-w-[85%] rounded-3xl bg-bubble px-6 py-4 text-body text-foreground">
                                {t.question}
                            </p>
                            <div className="text-body">
                                <CitedText
                                    text={t.answer}
                                    citations={t.citations}
                                    onOpen={onOpenCitation}
                                />
                                {t.citations === null && t.answer === '' && (
                                    <p className="text-muted-foreground">
                                        Reading your sources…
                                    </p>
                                )}
                            </div>
                            {t.error && (
                                <p className="text-sm text-destructive">
                                    {t.error}
                                </p>
                            )}
                            {t.citations !== null && t.answer !== '' && (
                                <div className="flex items-center gap-1 text-muted-foreground">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="rounded-full"
                                        disabled={t.saved}
                                        onClick={() => saveToNote(i)}
                                        data-testid="save-to-note"
                                    >
                                        <Pin />{' '}
                                        {t.saved
                                            ? 'Saved to notes'
                                            : 'Save to note'}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 rounded-full"
                                        aria-label="Copy answer"
                                        onClick={() => copyAnswer(t.answer)}
                                    >
                                        <Copy />
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                    <div ref={bottom} />
                </div>
            </div>
            <div className="px-2 pt-2 pb-3 sm:px-6">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void ask(question);
                    }}
                    className="mx-auto flex w-full max-w-3xl items-center gap-3 rounded-full bg-panel py-2 pr-2 pl-5 shadow-sm focus-within:ring-2 focus-within:ring-ring/50"
                >
                    <span
                        title="Chat settings: not in this demo"
                        className="text-muted-foreground opacity-60"
                    >
                        <SlidersHorizontal className="size-5" />
                    </span>
                    <input
                        className="min-w-0 flex-1 bg-transparent py-2 text-body outline-none"
                        placeholder={
                            nothingReady
                                ? 'Check a source to start'
                                : 'Ask about your sources'
                        }
                        aria-label="Question"
                        maxLength={MAX_QUESTION}
                        value={question}
                        onChange={(e) => setQuestion(e.target.value)}
                        disabled={busy}
                    />
                    <span className="shrink-0 text-caption text-muted-foreground">
                        {checked.length}{' '}
                        {checked.length === 1 ? 'source' : 'sources'}
                    </span>
                    <Button
                        type="submit"
                        size="icon"
                        className="size-10 shrink-0 rounded-full bg-bubble text-foreground hover:bg-primary hover:text-primary-foreground"
                        disabled={busy || !question.trim() || nothingReady}
                        aria-label="Ask"
                    >
                        <ArrowUp />
                    </Button>
                </form>
                <p className="mt-2 text-center text-caption text-muted-foreground">
                    Answers can be wrong. Check the sources.
                    {turns.length > 0 && (
                        <>
                            {' · '}
                            <button
                                type="button"
                                onClick={clear}
                                className="hover:underline"
                            >
                                Clear this chat
                            </button>
                        </>
                    )}
                </p>
            </div>
        </div>
    );
}
