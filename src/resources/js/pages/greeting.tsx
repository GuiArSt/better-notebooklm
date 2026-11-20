import { Head, Link, router } from '@inertiajs/react';
import { Check, FlaskConical, Gauge, Telescope, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { WelcomeCard } from '@/demo/welcome-card';
import { SCRAPING_NOT_IN_DEMO } from '@/lib/web-source-note';
import type { Usage } from '@/types/app';

const WORKS = [
    'Sources from PDFs, web pages and pasted text',
    'Chat that answers only from your checked sources, with citations you can hover and click',
    'Notes, and saving an answer to a note',
    'An audio overview with two hosts, in four formats (and four host personas for pro members), written reports and a mind map',
    'Quizzes and slide decks, each with citations',
    'A Customize step before every Studio item: language, sources, a focus and the item’s own choice',
    'A usage meter with a hard monthly budget',
];

const INFOGRAPHICS_WORK =
    'Infographics for pro members: a cited picture drawn from your sources';

const NOT_IN_DEMO = [
    'Video overviews, flashcards, data tables, sites',
    'YouTube, audio, image and Google Drive sources',
    SCRAPING_NOT_IN_DEMO,
    'Sharing a notebook with other people',
    'Real payments: the pricing page is a demo',
];

const INFOGRAPHICS_NOT = 'Infographics';

export default function Greeting({
    usage,
    greeted,
    podcast_usd,
    infographics,
}: {
    usage: Usage;
    greeted: boolean;
    podcast_usd: number;
    /** Infographics are switched on here (the server's INFOGRAPHICS_ENABLED). */
    infographics: boolean;
}) {
    const works = infographics ? [...WORKS, INFOGRAPHICS_WORK] : WORKS;
    const notInDemo = infographics
        ? NOT_IN_DEMO
        : [INFOGRAPHICS_NOT, ...NOT_IN_DEMO];

    const [busy, setBusy] = useState(false);

    const proceed = () => {
        setBusy(true);
        router.post('/greeting', {}, { onFinish: () => setBusy(false) });
    };

    return (
        <>
            <Head title="Welcome" />
            <div className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8 sm:py-12">
                <WelcomeCard capUsd={usage.cap_usd} />
                <div className="flex flex-col gap-3">
                    <span className="inline-flex w-fit items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                        <FlaskConical className="size-3.5" /> A demo built for a
                        job test
                    </span>
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                        Hello, and thank you for testing this.
                    </h1>
                    <p className="text-muted-foreground">
                        This is Better NotebookLM: a working replica of
                        Google&apos;s NotebookLM, built in three days for a job
                        application test. It is a demo, not a product. Here is
                        what is real and what is not, so you can judge it
                        fairly.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 flex items-center gap-2 font-semibold">
                            <Check className="size-4 text-emerald-600" /> What
                            truly works
                        </h2>
                        <ul className="space-y-2 text-sm">
                            {works.map((item) => (
                                <li key={item} className="flex gap-2">
                                    <Check className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 flex items-center gap-2 font-semibold">
                            <X className="size-4 text-muted-foreground" /> Not
                            in this demo
                        </h2>
                        <ul className="space-y-2 text-sm text-muted-foreground">
                            {notInDemo.map((item) => (
                                <li key={item} className="flex gap-2">
                                    <X className="mt-0.5 size-4 shrink-0" />
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-xs text-muted-foreground">
                            These appear greyed out and say &ldquo;not in this
                            demo&rdquo;.
                        </p>
                    </section>
                </div>

                <section className="flex items-start gap-3 rounded-xl border p-4">
                    <Gauge className="mt-0.5 size-5 shrink-0" />
                    <div className="text-sm">
                        <h2 className="font-semibold">Your budget</h2>
                        <p className="text-muted-foreground">
                            Every answer costs a little real money. You have{' '}
                            <strong className="text-foreground">
                                ${usage.cap_usd.toFixed(2)}
                            </strong>{' '}
                            to spend this month as{' '}
                            {usage.label === 'free' || usage.label === 'pro'
                                ? `a ${usage.label} member`
                                : `the ${usage.label}`}{' '}
                            ($
                            {usage.spent_usd.toFixed(3)} used). When it runs
                            out, the app says so and stops, instead of charging
                            anyone.
                        </p>
                        <p className="mt-2 text-muted-foreground">
                            One audio overview (the podcast) costs up to{' '}
                            <strong
                                className="text-foreground"
                                data-testid="podcast-cost"
                            >
                                ${podcast_usd.toFixed(2)}
                            </strong>
                            , about{' '}
                            {Math.max(
                                1,
                                Math.floor(
                                    usage.cap_usd / Math.max(podcast_usd, 0.01),
                                ),
                            )}{' '}
                            of them in a full budget. Chat answers cost a
                            fraction of a cent.
                        </p>
                    </div>
                </section>

                <section className="flex items-start gap-3 rounded-xl border p-4">
                    <Telescope className="mt-0.5 size-5 shrink-0" />
                    <div className="text-sm">
                        <h2 className="font-semibold">What is traced</h2>
                        <p className="text-muted-foreground">
                            Your sources and questions are sent to AI providers
                            and traced in Langfuse, so the person who built this
                            can fix the demo. Use public documents: do not
                            upload anything private or anything with other
                            people&apos;s personal data. Details are on the{' '}
                            <Link
                                href="/privacy"
                                className="text-primary underline"
                            >
                                privacy page
                            </Link>
                            .
                        </p>
                    </div>
                </section>

                <div className="flex justify-end">
                    <Button
                        size="lg"
                        onClick={proceed}
                        disabled={busy}
                        data-testid="greeting-continue"
                    >
                        {greeted
                            ? 'Back to my notebooks'
                            : 'I understand, take me in'}
                    </Button>
                </div>
            </div>
        </>
    );
}
