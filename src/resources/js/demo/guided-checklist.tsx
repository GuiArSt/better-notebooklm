import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useDemo } from '@/demo/use-demo';
import { CHECKLIST, TOGGLE } from '@/demo/strings';
import type { Lang, StepId } from '@/demo/strings';

// The server opens the reviewer's own featured notebook (the showcase copy their invitation made), or makes one. The home page hides the
// featured card once a copy exists, so clicking that card would do nothing for an invited reviewer.
function startHere() {
    router.post('/notebooks/featured');
}

export function ChecklistBody({
    lang,
    ticked,
    hidden,
    onTick,
    onUntick,
    onHidden,
    onToggleLang,
    onStart = startHere,
}: {
    lang: Lang;
    ticked: StepId[];
    hidden: boolean;
    onTick: (id: StepId) => void;
    onUntick: (id: StepId) => void;
    onHidden: (hidden: boolean) => void;
    onToggleLang: () => void;
    onStart?: () => void;
}) {
    const steps = CHECKLIST.steps;
    const done = steps.every((s) => ticked.includes(s.id));

    if (hidden) {
        return (
            <div data-testid="demo-checklist">
                <button
                    type="button"
                    onClick={() => onHidden(false)}
                    className="rounded-full bg-bubble px-4 py-2 text-caption font-medium"
                >
                    {CHECKLIST.show[lang]}
                </button>
            </div>
        );
    }

    if (done) {
        return (
            <section
                className="rounded-card bg-card-soft p-5"
                data-testid="demo-checklist"
            >
                <h2 className="text-title font-normal">
                    {CHECKLIST.doneTitle[lang]}
                </h2>
                <p className="mt-1 text-body text-muted-foreground">
                    {CHECKLIST.doneBody[lang]}
                </p>
            </section>
        );
    }

    return (
        <section
            className="rounded-card bg-card-soft p-5"
            data-testid="demo-checklist"
        >
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-title font-normal">
                    {CHECKLIST.title[lang]}
                </h2>
                <div className="flex items-center gap-3 text-caption text-muted-foreground">
                    <span>
                        {ticked.length}/{steps.length}
                    </span>
                    <button
                        type="button"
                        onClick={onToggleLang}
                        className="underline hover:text-foreground"
                    >
                        {TOGGLE[lang]}
                    </button>
                    <button
                        type="button"
                        onClick={() => onHidden(true)}
                        className="underline hover:text-foreground"
                    >
                        {CHECKLIST.hide[lang]}
                    </button>
                </div>
            </div>
            <ol className="mt-3 flex flex-col gap-2">
                {steps.map((step, i) => {
                    const on = ticked.includes(step.id);

                    return (
                        <li
                            key={step.id}
                            className="flex items-start gap-3"
                            data-testid="demo-step"
                        >
                            <button
                                type="button"
                                role="checkbox"
                                aria-checked={on}
                                aria-label={
                                    on
                                        ? CHECKLIST.untick[lang]
                                        : CHECKLIST.tick[lang]
                                }
                                onClick={() =>
                                    on ? onUntick(step.id) : onTick(step.id)
                                }
                                className={`mt-0.5 flex size-5 shrink-0 items-center justify-center rounded border ${on ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-muted-foreground/60'}`}
                            >
                                {on && <Check className="size-3.5" />}
                            </button>
                            <span
                                className={`text-body ${on ? 'text-muted-foreground line-through' : ''}`}
                            >
                                {step[lang]}
                            </span>
                            {i === 0 && !on && (
                                <button
                                    type="button"
                                    onClick={onStart}
                                    className="ml-auto shrink-0 rounded-full bg-cta px-4 py-1 text-caption text-cta-foreground hover:bg-cta/80"
                                >
                                    {CHECKLIST.start[lang]}
                                </button>
                            )}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

/** For a reviewer only: five steps, ticked by hand or by the demo layer, never blocking anything. */
export function GuidedChecklist() {
    const demo = useDemo();

    if (!demo.guest) {
        return null;
    }

    return (
        <ChecklistBody
            lang={demo.lang}
            ticked={demo.ticked}
            hidden={demo.hidden}
            onTick={demo.tick}
            onUntick={demo.untick}
            onHidden={demo.setHidden}
            onToggleLang={() => demo.setLang(demo.lang === 'de' ? 'en' : 'de')}
        />
    );
}
