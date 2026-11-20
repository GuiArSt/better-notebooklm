import { usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useState } from 'react';
import { KindBadge } from '@/components/notebook/kind-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    FORMS,
    LANGUAGES,
    buildRequest,
    choicesFor,
    choose,
    defaultOptions,
    estimateQuery,
    usageBar,
} from '@/lib/customize';
import type { CustomizeRequest, Estimate, Offers } from '@/lib/customize';
import { HttpError, getJson } from '@/lib/http';
import { swatchFor } from '@/lib/style-swatches';
import { usd } from '@/lib/money';
import { TILE_OF_KIND } from '@/lib/studio-tints';
import { cn } from '@/lib/utils';
import type { Source, Usage } from '@/types/app';
import type { StudioKind } from '@/types/studio';

/**
 * The step before a generation (D-035): language, which sources, what to focus on, the kind's own choice, and what it will
 * cost against the budget. `estimateUsd` comes from the backend's price config; null until it is known.
 */
export function CustomizeDialog({
    kind,
    sources,
    notebookId,
    offers,
    onGenerate,
    onClose,
}: {
    kind: StudioKind;
    sources: Source[];
    notebookId: number;
    /** What the server offers this user: podcast formats and personas, the infographic's choices. */
    offers: Offers;
    /** Resolves to the server's validation messages when it refuses, or null when the item was started. */
    onGenerate: (request: CustomizeRequest) => Promise<string[] | null>;
    onClose: () => void;
}) {
    const usage = usePage<{ usage?: Usage }>().props.usage;
    const form = FORMS[kind];
    const usable = sources.filter((s) => s.status === 'ready');
    const [language, setLanguage] = useState('auto');
    const [sourceIds, setSourceIds] = useState<number[]>(
        usable.filter((s) => s.checked).map((s) => s.id),
    );
    const [pickSources, setPickSources] = useState(false);
    const [focus, setFocus] = useState('');
    const [options, setOptions] = useState<Record<string, string>>(() =>
        defaultOptions(kind, offers),
    );
    // Which choices the user has made themselves, so a Brief can start on its own length until they pick one.
    const [touched, setTouched] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const estimateUsd = estimate?.estimate_usd ?? null;
    const bar = usage
        ? usageBar(usage.spent_usd, usage.cap_usd, estimateUsd)
        : null;
    const choiceKey = JSON.stringify([language, sourceIds, options]);

    // The estimate is the server's upper bound for these choices, asked again (after a short pause) whenever they change.
    useEffect(() => {
        if (sourceIds.length === 0) {
            setEstimate(null);

            return;
        }

        let live = true;
        const timer = setTimeout(() => {
            const request = buildRequest(
                kind,
                {
                    language,
                    sourceIds,
                    focus: '',
                    options,
                },
                offers,
            );

            getJson<Estimate>(
                `/notebooks/${notebookId}/studio/estimate?${estimateQuery(request)}`,
            )
                .then((e) => live && setEstimate(e))
                .catch((e: unknown) => {
                    if (live) {
                        setEstimate(null);

                        if (e instanceof HttpError && e.status === 422) {
                            setErrors(e.messages());
                        }
                    }
                });
        }, 250);

        return () => {
            live = false;
            clearTimeout(timer);
        };
        // choiceKey stands for language, sources and options.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kind, notebookId, choiceKey]);

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[92vh] gap-4 overflow-y-auto rounded-panel border-0 bg-panel p-6 sm:max-w-2xl"
                data-testid="customize"
            >
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <KindBadge
                            tile={TILE_OF_KIND[kind] ?? 'reports'}
                            size="md"
                        />
                        <DialogTitle className="text-title font-normal">
                            {form.title}
                        </DialogTitle>
                    </div>
                    <DialogDescription className="sr-only">
                        Choose how this should be made, then generate it.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="grid gap-1.5 text-body">
                        <span className="font-medium">Language</span>
                        <select
                            value={language}
                            onChange={(e) => setLanguage(e.target.value)}
                            className="h-11 rounded-xl border bg-background px-3"
                            data-testid="customize-language"
                        >
                            {LANGUAGES.map((l) => (
                                <option key={l.value} value={l.value}>
                                    {l.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <div className="grid content-start gap-1.5 text-body">
                        <span className="font-medium">Sources</span>
                        <button
                            type="button"
                            onClick={() => setPickSources((p) => !p)}
                            aria-expanded={pickSources}
                            className="flex h-11 w-fit items-center gap-2 rounded-full border px-4"
                            data-testid="customize-sources-toggle"
                        >
                            {sourceIds.length}{' '}
                            {sourceIds.length === 1 ? 'source' : 'sources'}
                            <ChevronDown className="size-4" />
                        </button>
                    </div>
                </div>

                {pickSources && (
                    <ul
                        className="max-h-40 space-y-1 overflow-y-auto rounded-2xl bg-card-soft p-2"
                        data-testid="customize-sources"
                    >
                        {usable.map((s) => (
                            <li key={s.id}>
                                <label className="flex items-center gap-3 rounded-xl p-2 text-body hover:bg-bubble">
                                    <Checkbox
                                        checked={sourceIds.includes(s.id)}
                                        onCheckedChange={(v) =>
                                            setSourceIds((ids) =>
                                                v === true
                                                    ? [...ids, s.id]
                                                    : ids.filter(
                                                          (i) => i !== s.id,
                                                      ),
                                            )
                                        }
                                        aria-label={`Use ${s.title}`}
                                    />
                                    <span className="min-w-0 truncate">
                                        {s.title}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                )}

                {choicesFor(kind, offers).map((choice) => (
                    <div
                        key={choice.id}
                        className="grid gap-1.5 text-body"
                        data-testid={`customize-choice-${choice.id}`}
                    >
                        <span className="font-medium">{choice.label}</span>
                        <div
                            role="radiogroup"
                            aria-label={choice.label}
                            className={
                                choice.tiles
                                    ? 'grid grid-cols-2 gap-2 sm:grid-cols-4'
                                    : 'grid grid-cols-2 gap-1 rounded-2xl bg-card-soft p-1 sm:flex sm:w-fit sm:flex-wrap sm:rounded-full'
                            }
                        >
                            {choice.options.map((o) => (
                                <button
                                    key={o.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={
                                        options[choice.id] === o.value
                                    }
                                    onClick={() => {
                                        const next = choose(
                                            kind,
                                            { options, touched },
                                            choice.id,
                                            o.value,
                                            offers,
                                        );

                                        setOptions(next.options);
                                        setTouched(next.touched);
                                    }}
                                    className={cn(
                                        choice.tiles
                                            ? 'flex flex-col gap-2 rounded-2xl border p-2 text-center text-caption'
                                            : 'rounded-full px-4 py-1.5 text-center',
                                        choice.tiles &&
                                            (options[choice.id] === o.value
                                                ? 'border-foreground/50 bg-card-soft ring-1 ring-foreground/20'
                                                : o.value === choice.default
                                                  ? 'border-dashed border-foreground/30 hover:bg-card-soft'
                                                  : 'border-transparent bg-card-soft/50 hover:bg-card-soft'),
                                        !choice.tiles &&
                                            (options[choice.id] === o.value
                                                ? 'bg-panel shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'),
                                    )}
                                    data-testid={
                                        choice.tiles
                                            ? `customize-tile-${o.value}`
                                            : undefined
                                    }
                                >
                                    {choice.tiles && (
                                        <span
                                            className="block h-10 w-full rounded-lg border border-black/5"
                                            style={{
                                                background: swatchFor(o.value),
                                            }}
                                            aria-hidden="true"
                                        />
                                    )}
                                    {o.label}
                                </button>
                            ))}
                        </div>
                        {choice.options.find(
                            (o) => o.value === options[choice.id],
                        )?.hint && (
                            <p className="text-caption text-muted-foreground">
                                {
                                    choice.options.find(
                                        (o) => o.value === options[choice.id],
                                    )?.hint
                                }
                            </p>
                        )}
                    </div>
                ))}

                {kind === 'audio' &&
                    (offers.podcast?.personas.length ?? 0) > 0 && (
                        <div
                            className="grid gap-1.5 text-body"
                            data-testid="customize-persona"
                        >
                            <span className="font-medium">Host</span>
                            <div
                                role="radiogroup"
                                aria-label="Host"
                                className="grid gap-1 sm:grid-cols-2"
                            >
                                {[
                                    {
                                        value: '',
                                        name: 'Standard host',
                                        role: '',
                                        line: 'The two default voices.',
                                    },
                                    ...(offers.podcast?.personas ?? []),
                                ].map((p) => (
                                    <button
                                        key={p.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={
                                            (options.persona ?? '') === p.value
                                        }
                                        onClick={() =>
                                            setOptions(
                                                ({ persona, ...rest }) => {
                                                    void persona;

                                                    return p.value
                                                        ? {
                                                              ...rest,
                                                              persona: p.value,
                                                          }
                                                        : rest;
                                                },
                                            )
                                        }
                                        className={cn(
                                            'rounded-2xl border p-3 text-left',
                                            (options.persona ?? '') === p.value
                                                ? 'bg-card-soft ring-2 ring-foreground/30'
                                                : 'hover:bg-bubble',
                                        )}
                                        data-testid={`customize-persona-${p.value || 'standard'}`}
                                    >
                                        <span className="block font-medium">
                                            {p.name}
                                            {p.role && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' · '}
                                                    {p.role}
                                                </span>
                                            )}
                                        </span>
                                        <span className="block text-caption text-muted-foreground">
                                            {p.line}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                <label className="grid gap-1.5 text-body">
                    <span className="font-medium">{form.focusLabel}</span>
                    <textarea
                        value={focus}
                        onChange={(e) => setFocus(e.target.value)}
                        maxLength={500}
                        rows={4}
                        data-testid="customize-focus"
                        placeholder={`Things to try\n${form.focusExamples.map((e) => `• ${e}`).join('\n')}`}
                        className="min-h-28 resize-y rounded-2xl border bg-background p-3 text-body"
                    />
                </label>

                <DialogFooter className="sticky -bottom-6 z-10 -mx-6 -mb-6 items-center gap-4 border-t bg-panel px-6 pt-4 pb-6 sm:justify-between">
                    <div
                        className="min-w-0 flex-1 space-y-1.5"
                        data-testid="customize-usage"
                    >
                        <p className="text-caption font-medium text-muted-foreground">
                            AI usage
                        </p>
                        {errors.length > 0 && (
                            <ul
                                className="space-y-0.5 text-caption text-destructive"
                                role="alert"
                                data-testid="customize-errors"
                            >
                                {errors.map((m) => (
                                    <li key={m}>{m}</li>
                                ))}
                            </ul>
                        )}
                        {bar && usage && (
                            <>
                                <div
                                    className="flex h-2 w-full max-w-64 overflow-hidden rounded-full bg-bubble"
                                    role="img"
                                    aria-label="Budget used and estimated"
                                >
                                    <span
                                        className="h-full bg-blue-500"
                                        style={{ width: `${bar.used}%` }}
                                    />
                                    <span
                                        className={cn(
                                            'h-full',
                                            bar.over
                                                ? 'bg-destructive'
                                                : 'bg-blue-800 dark:bg-blue-300',
                                        )}
                                        style={{ width: `${bar.added}%` }}
                                    />
                                </div>
                                <p
                                    className="text-caption text-muted-foreground"
                                    data-testid="customize-estimate"
                                >
                                    Used {usd(usage.spent_usd)} of{' '}
                                    {usd(usage.cap_usd)}
                                    {estimateUsd === null
                                        ? '. Working out the estimate…'
                                        : `. Estimated use: up to ${usd(estimateUsd)}${estimate && !estimate.within_budget ? ' (more than you have left)' : ''}. The real cost is usually lower.`}
                                </p>
                            </>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="ghost"
                            className="rounded-full"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="rounded-full px-6"
                            disabled={busy || sourceIds.length === 0}
                            onClick={async () => {
                                setBusy(true);
                                setErrors([]);

                                const refused = await onGenerate(
                                    buildRequest(
                                        kind,
                                        {
                                            language,
                                            sourceIds,
                                            focus,
                                            options,
                                        },
                                        offers,
                                    ),
                                );

                                setBusy(false);

                                if (refused) {
                                    setErrors(refused);
                                }
                            }}
                            data-testid="customize-generate"
                        >
                            Generate
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
