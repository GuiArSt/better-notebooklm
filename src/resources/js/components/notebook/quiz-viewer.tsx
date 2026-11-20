import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { CitationChip } from '@/components/notebook/citation';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Passage } from '@/types/app';
import type { QuizQuestion, StudioItem } from '@/types/studio';

const LETTERS = ['A', 'B', 'C', 'D'];

/** The right option as an index, whether the server sent 2 or "C". */
export function answerIndex(q: QuizQuestion): number {
    return typeof q.answer === 'number'
        ? q.answer
        : Math.max(0, LETTERS.indexOf(String(q.answer).toUpperCase()));
}

/** A quiz one question at a time: pick an option, see whether it was right, read why (with its source), then Next; a score at the end. */
export function QuizViewer({
    item,
    onOpenCitation,
}: {
    item: StudioItem;
    onOpenCitation: (passage: Passage) => void;
}) {
    const questions = item.quiz ?? [];
    const [index, setIndex] = useState(0);
    const [picks, setPicks] = useState<(number | null)[]>(() =>
        questions.map(() => null),
    );
    const [hint, setHint] = useState(false);
    const [done, setDone] = useState(false);

    if (questions.length === 0) {
        return (
            <p className="text-body text-muted-foreground">
                This quiz has no questions.
            </p>
        );
    }

    const score = picks.filter(
        (p, i) => p !== null && p === answerIndex(questions[i]),
    ).length;

    if (done) {
        const missed = questions
            .map((q, i) => ({ q, i }))
            .filter(({ q, i }) => picks[i] !== answerIndex(q));

        return (
            <div className="space-y-5" data-testid="quiz-score">
                <div className="rounded-3xl bg-card-soft p-6 text-center">
                    <p className="text-caption text-muted-foreground">
                        Your score
                    </p>
                    <p
                        className="text-display font-normal"
                        data-testid="quiz-result"
                    >
                        {score} / {questions.length}
                    </p>
                </div>
                {missed.length > 0 && (
                    <div className="space-y-2">
                        <h4 className="text-body font-medium">
                            To look at again
                        </h4>
                        <ul className="space-y-2 text-body">
                            {missed.map(({ q, i }) => (
                                <li
                                    key={i}
                                    className="rounded-2xl bg-card-soft p-3"
                                >
                                    <p>{q.question}</p>
                                    <p className="text-muted-foreground">
                                        Right answer: {LETTERS[answerIndex(q)]}.{' '}
                                        {q.options[answerIndex(q)]}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <Button
                    className="rounded-full"
                    onClick={() => {
                        setPicks(questions.map(() => null));
                        setIndex(0);
                        setHint(false);
                        setDone(false);
                    }}
                    data-testid="quiz-retake"
                >
                    <RotateCcw /> Retake
                </Button>
            </div>
        );
    }

    const q = questions[index];
    const right = answerIndex(q);
    const picked = picks[index];
    const answered = picked !== null;
    const last = index === questions.length - 1;
    const chips = (q.refs ?? []).flatMap((id) => {
        const at = item.citations?.findIndex((c) => c.id === id) ?? -1;

        return at < 0 ? [] : [{ id, at }];
    });

    return (
        <div className="space-y-5" data-testid="quiz">
            <div className="flex items-center justify-between text-caption text-muted-foreground">
                <span data-testid="quiz-progress">
                    {index + 1} / {questions.length}
                </span>
                <span>{score} right so far</span>
            </div>

            <h3
                className="text-title leading-snug font-normal"
                data-testid="quiz-question"
            >
                {q.question}
            </h3>

            <ul className="space-y-2" role="radiogroup" aria-label="Answers">
                {q.options.map((option, i) => {
                    const isPick = picked === i;
                    const isRight = answered && i === right;
                    const isWrong = answered && isPick && i !== right;

                    return (
                        <li key={i}>
                            <button
                                type="button"
                                role="radio"
                                aria-checked={isPick}
                                disabled={answered}
                                data-testid="quiz-option"
                                data-state={
                                    isRight
                                        ? 'right'
                                        : isWrong
                                          ? 'wrong'
                                          : 'idle'
                                }
                                onClick={() =>
                                    setPicks((all) =>
                                        all.map((p, k) =>
                                            k === index ? i : p,
                                        ),
                                    )
                                }
                                className={cn(
                                    'flex w-full items-start gap-3 rounded-2xl bg-card-soft px-4 py-3 text-left text-body transition',
                                    !answered && 'hover:bg-bubble',
                                    isRight &&
                                        'bg-emerald-100 text-emerald-950 dark:bg-emerald-500/20 dark:text-emerald-100',
                                    isWrong &&
                                        'bg-red-100 text-red-950 dark:bg-red-500/20 dark:text-red-100',
                                )}
                            >
                                <span className="font-medium">
                                    {LETTERS[i]}
                                </span>
                                <span>{option}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>

            {!answered && q.hint && (
                <div>
                    <button
                        type="button"
                        onClick={() => setHint((h) => !h)}
                        aria-expanded={hint}
                        className="text-body text-muted-foreground underline"
                        data-testid="quiz-hint-toggle"
                    >
                        Hint {hint ? '▴' : '▾'}
                    </button>
                    {hint && (
                        <p
                            className="mt-2 rounded-2xl bg-card-soft p-3 text-body"
                            data-testid="quiz-hint"
                        >
                            {q.hint}
                        </p>
                    )}
                </div>
            )}

            {answered && (
                <div
                    className="space-y-2 rounded-2xl bg-card-soft p-4 text-body"
                    data-testid="quiz-feedback"
                    data-correct={picked === right}
                >
                    <p className="font-medium">
                        {picked === right
                            ? 'Correct.'
                            : `Not quite. The answer is ${LETTERS[right]}.`}
                    </p>
                    {picked !== right && q.explanation && (
                        <p data-testid="quiz-explanation">
                            {q.explanation}
                            {chips.map(({ id, at }) => (
                                <CitationChip
                                    key={id}
                                    index={at}
                                    citation={item.citations![at]}
                                    onOpen={onOpenCitation}
                                />
                            ))}
                        </p>
                    )}
                </div>
            )}

            <div className="flex justify-end">
                <Button
                    className="rounded-full bg-cta px-6 text-cta-foreground hover:bg-cta/80"
                    disabled={!answered}
                    onClick={() => {
                        if (last) {
                            setDone(true);
                        } else {
                            setIndex(index + 1);
                            setHint(false);
                        }
                    }}
                    data-testid="quiz-next"
                >
                    {last ? 'See score' : 'Next'}
                </Button>
            </div>
        </div>
    );
}
