import { Head, router } from '@inertiajs/react';
import { Check, CreditCard } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { money, usd } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { Usage } from '@/types/app';

type ModelRate = {
    model: string;
    cost_in: number;
    cost_out: number;
    pro_in: number;
    pro_out: number;
};

type Speech = {
    tier: string;
    voice: string;
    cost_per_1k_chars: number;
    cost_per_episode: number;
    pro_per_1k_chars: number;
    pro_per_episode: number;
};

type Props = {
    speech: Speech[];
    episode_chars: number;
    usage: Usage;
    markup: number;
    caps: { free: number; pro: number };
    models: ModelRate[];
    tier: string;
    pro_until: string | null;
    checkout: 'success' | 'cancelled' | 'unavailable' | null;
    stripe_ready: boolean;
    end_date: string;
    plans: {
        free: { chat_model: string; voice: string; cap: number };
        pro: {
            chat_model: string;
            voice: string;
            cap: number;
            price: number;
            currency: string;
            days: number;
        };
    };
    example_chat: {
        questions: number;
        free_usd: number;
        pro_usd: number;
        pro_list_usd: number;
    };
};

// Stripe's own published test card: it only works in test mode, which is why it is shown here.
const TEST_CARD = '4242 4242 4242 4242';

export default function Pricing(props: Props) {
    const { usage, markup, plans, tier, checkout, end_date } = props;
    const [busy, setBusy] = useState(false);

    const upgrade = () => {
        setBusy(true);
        router.post(
            '/billing/checkout',
            {},
            { onFinish: () => setBusy(false) },
        );
    };

    const onPro = tier === 'pro';
    // A reviewer or the owner has a budget of their own (and ElevenLabs), whatever their tier says.
    const special = usage.label === 'reviewer' || usage.label === 'owner';

    return (
        <>
            <Head title="Pricing" />
            <div
                className="bg-amber-100 px-4 py-2 text-center text-sm font-medium text-amber-950 dark:bg-amber-900/30 dark:text-amber-200"
                data-testid="demo-banner"
            >
                Demo: no real payments. Nothing on this page takes money.
            </div>

            <div className="mx-auto flex max-w-6xl flex-col gap-12 px-4 py-10">
                <header className="space-y-3 text-center">
                    <h1 className="text-3xl font-normal sm:text-display">
                        Pricing
                    </h1>
                    <p className="mx-auto max-w-2xl text-body text-muted-foreground">
                        Three ways to use Better NotebookLM. Your{' '}
                        <strong className="text-foreground capitalize">
                            {usage.label}
                        </strong>{' '}
                        budget: {usd(usage.spent_usd)} of {usd(usage.cap_usd)}{' '}
                        used this month.
                    </p>
                </header>

                {checkout && (
                    <div
                        role="status"
                        data-testid={`checkout-${checkout}`}
                        className={cn(
                            'rounded-3xl px-6 py-4 text-body',
                            checkout === 'success' &&
                                'bg-emerald-100 text-emerald-950 dark:bg-emerald-900/30 dark:text-emerald-100',
                            checkout === 'cancelled' && 'bg-card-soft',
                            checkout === 'unavailable' &&
                                'bg-amber-100 text-amber-950 dark:bg-amber-900/30 dark:text-amber-100',
                        )}
                    >
                        {checkout === 'success' &&
                            'Test payment received. No money moved. Your Pro allowance appears in a moment: reload if the meter still shows your old budget.'}
                        {checkout === 'cancelled' &&
                            'Checkout cancelled. Nothing was charged and your plan is unchanged.'}
                        {checkout === 'unavailable' &&
                            'The test checkout is not available right now. Nothing was charged.'}
                    </div>
                )}

                {special && (
                    <div
                        className="rounded-3xl bg-card-soft px-6 py-4 text-body"
                        data-testid="special-account"
                    >
                        Your{' '}
                        <strong className="capitalize">{usage.label}</strong>{' '}
                        account already includes {money(usage.cap_usd)} of model
                        use a month and ElevenLabs&apos; podcast voice. The
                        plans below are what everyone else gets.
                    </div>
                )}

                <section
                    className="grid items-stretch gap-5 lg:grid-cols-3"
                    data-testid="plans"
                >
                    <Card
                        name="Free"
                        line="Try everything, on Mistral, up to a monthly budget."
                    >
                        <Price big="$0" small="no card needed" />
                        <p className="text-body font-medium">
                            {money(plans.free.cap)} of model use a month, then
                            it stops.
                        </p>
                        <Features
                            items={[
                                `Chat on ${plans.free.chat_model}, with citations`,
                                `${plans.free.voice}'s voice for the podcast`,
                                'Sources, notes, reports and a mind map',
                            ]}
                        />
                        <Button
                            variant="secondary"
                            disabled
                            className="mt-auto rounded-full"
                        >
                            {tier === 'free' && !special ? 'Your plan' : 'Free'}
                        </Button>
                    </Card>

                    <Card
                        name="Pro"
                        line="Claude for chat and ElevenLabs for the podcast."
                        highlight
                    >
                        <Price
                            big={money(plans.pro.price)}
                            small={`test purchase · ${plans.pro.days} days of Pro`}
                        />
                        <p className="text-body font-medium">
                            {money(plans.pro.cap)} of model use a month while
                            Pro is active ({plans.pro.days} days per test
                            purchase).
                        </p>
                        <p
                            className="text-caption text-muted-foreground"
                            data-testid="list-price-note"
                        >
                            List price: our cost × {markup}, what a real product
                            would charge. Here it is illustration only: your{' '}
                            {money(plans.pro.cap)} a month is counted at our raw
                            cost, and no money moves.
                        </p>
                        <p className="text-caption font-medium text-muted-foreground">
                            Everything in Free, plus:
                        </p>
                        <Features
                            items={[
                                `Chat on ${plans.pro.chat_model}`,
                                `${plans.pro.voice}, two hosts, for the podcast`,
                                `A larger budget (${money(plans.pro.cap)} a month while Pro is active)`,
                            ]}
                        />
                        <div className="mt-auto space-y-3">
                            {onPro && props.pro_until && (
                                <p className="text-caption text-muted-foreground">
                                    You are on Pro until{' '}
                                    {new Date(
                                        props.pro_until,
                                    ).toLocaleDateString('en', {
                                        month: 'long',
                                        day: 'numeric',
                                        year: 'numeric',
                                    })}
                                    . Buying again does not stack.
                                </p>
                            )}
                            <Button
                                className="w-full rounded-full"
                                onClick={upgrade}
                                disabled={busy || !props.stripe_ready}
                                data-testid="upgrade"
                            >
                                <CreditCard />{' '}
                                {onPro
                                    ? 'Renew Pro (test mode)'
                                    : 'Upgrade to Pro (test mode)'}
                            </Button>
                            {!props.stripe_ready && (
                                <p className="text-caption text-muted-foreground">
                                    The test checkout is not set up on this
                                    server.
                                </p>
                            )}
                            <div
                                className="rounded-2xl bg-card-soft p-3 text-caption"
                                data-testid="test-card"
                            >
                                <p className="font-medium text-foreground">
                                    Pay with Stripe&apos;s test card
                                </p>
                                <p className="mt-1 font-mono text-body text-foreground">
                                    {TEST_CARD}
                                </p>
                                <p className="text-muted-foreground">
                                    any future date, any CVC, any name. Do not
                                    use a real card.
                                </p>
                            </div>
                        </div>
                    </Card>

                    <Card
                        name="Own key"
                        line="Bring your own API key; we add nothing."
                        muted
                    >
                        <Price
                            big="$0"
                            small="from us; your provider bills you"
                        />
                        <p className="text-body font-medium">
                            Unlimited on your key.
                        </p>
                        <Features
                            items={[
                                'The model your key opens',
                                'No limits from us',
                            ]}
                        />
                        <p className="text-caption">not in this demo</p>
                        <Button
                            variant="secondary"
                            disabled
                            className="mt-auto rounded-full"
                        >
                            Add your key
                        </Button>
                    </Card>
                </section>

                <ul
                    className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-caption text-muted-foreground"
                    data-testid="trust"
                >
                    {[
                        'No card for Free',
                        'Test mode: no money moves',
                        'Nothing renews',
                        `All demo data is deleted on ${end_date}`,
                    ].map((t) => (
                        <li key={t} className="flex items-center gap-1.5">
                            <Check className="size-3.5 text-emerald-600" /> {t}
                        </li>
                    ))}
                </ul>

                <Comparison {...props} />

                <Costs {...props} />

                <Faq {...props} />
            </div>
        </>
    );
}

function Card({
    name,
    line,
    highlight,
    muted,
    children,
}: {
    name: string;
    line: string;
    highlight?: boolean;
    muted?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'relative flex flex-col gap-4 rounded-card bg-panel p-6',
                highlight && 'shadow-lg ring-2 ring-primary lg:-my-3 lg:py-9',
                muted &&
                    'border-2 border-dashed border-tile-border bg-transparent text-muted-foreground opacity-70',
            )}
            aria-disabled={muted ? 'true' : undefined}
        >
            {highlight && (
                <span className="absolute -top-3 left-6 rounded-full bg-primary px-3 py-1 text-caption font-medium text-primary-foreground">
                    Recommended
                </span>
            )}
            <div>
                <h2 className="text-title font-medium text-foreground">
                    {name}
                </h2>
                <p className="text-body">{line}</p>
            </div>
            {children}
        </div>
    );
}

function Price({ big, small }: { big: string; small: string }) {
    return (
        <p className="flex flex-wrap items-baseline gap-x-2">
            <span className="text-4xl font-normal text-foreground">{big}</span>
            <span className="text-caption">{small}</span>
        </p>
    );
}

function Features({ items }: { items: string[] }) {
    return (
        <ul className="space-y-2 text-body">
            {items.map((item) => (
                <li key={item} className="flex gap-2">
                    <Check className="mt-1 size-4 shrink-0 text-emerald-600" />
                    <span>{item}</span>
                </li>
            ))}
        </ul>
    );
}

function Section({
    title,
    intro,
    children,
    ...rest
}: {
    title: string;
    intro?: string;
    children: React.ReactNode;
} & React.HTMLAttributes<HTMLElement>) {
    return (
        <section className="space-y-3" {...rest}>
            <h2 className="text-title font-normal">{title}</h2>
            {intro && (
                <p className="text-body text-muted-foreground">{intro}</p>
            )}
            {children}
        </section>
    );
}

function Comparison({ plans, end_date }: Props) {
    const groups: {
        title: string;
        rows: [string, string, string, string][];
    }[] = [
        {
            title: 'Sources and chat',
            rows: [
                [
                    'Chat model',
                    plans.free.chat_model,
                    plans.pro.chat_model,
                    'the model your key opens',
                ],
                ['Answers with citations', 'Yes', 'Yes', 'Yes'],
            ],
        },
        {
            title: 'Studio',
            rows: [
                [
                    'Podcast voice',
                    plans.free.voice,
                    `${plans.pro.voice}, two hosts`,
                    'not in this demo',
                ],
                ['Reports and mind map', 'Yes', 'Yes', 'Yes'],
            ],
        },
        {
            title: 'Limits',
            rows: [
                [
                    'Model use',
                    `${money(plans.free.cap)} a month`,
                    `${money(plans.pro.cap)} a month while Pro is active`,
                    'none from us',
                ],
                [
                    'When it runs out',
                    'It stops and says so',
                    'It stops; buy again in test mode',
                    "your provider's limits",
                ],
            ],
        },
        {
            title: 'Data',
            rows: [
                ['Deleted on', end_date, end_date, end_date],
                [
                    'Where it is kept',
                    'Our server (see Privacy)',
                    'Our server (see Privacy)',
                    'Our server (see Privacy)',
                ],
            ],
        },
    ];

    return (
        <Section title="Compare the plans" data-testid="comparison">
            <div className="overflow-x-auto rounded-3xl bg-panel">
                <table className="w-full min-w-[34rem] text-body">
                    <thead className="text-left">
                        <tr>
                            <th className="p-4" />
                            <th className="p-4 font-medium">Free</th>
                            <th className="p-4 font-medium">Pro</th>
                            <th className="p-4 font-medium text-muted-foreground">
                                Own key
                            </th>
                        </tr>
                    </thead>
                    {groups.map((g) => (
                        <tbody key={g.title}>
                            <tr>
                                <th
                                    colSpan={4}
                                    className="bg-card-soft px-4 py-2 text-left text-caption font-medium text-muted-foreground uppercase"
                                >
                                    {g.title}
                                </th>
                            </tr>
                            {g.rows.map(([label, a, b, c]) => (
                                <tr
                                    key={label}
                                    className="border-t border-tile-border align-top"
                                >
                                    <th className="p-4 text-left font-normal">
                                        {label}
                                    </th>
                                    <td className="p-4">{a}</td>
                                    <td className="p-4">{b}</td>
                                    <td className="p-4 text-muted-foreground">
                                        {c}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    ))}
                </table>
            </div>
        </Section>
    );
}

function Costs({ models, speech, episode_chars, markup, example_chat }: Props) {
    return (
        <>
            <Section
                title="What things cost"
                intro={`Our own numbers, in US dollars per million tokens, from the providers' price lists. The list price is our cost times ${markup}: what a real product would charge. Here it is illustration only, and the ledger counts the raw cost.`}
            >
                <div className="overflow-x-auto rounded-3xl bg-panel">
                    <table className="w-full min-w-[28rem] text-body">
                        <thead className="text-left">
                            <tr>
                                <th className="p-4">Model</th>
                                <th className="p-4">Cost in / out</th>
                                <th className="p-4">List price in / out</th>
                            </tr>
                        </thead>
                        <tbody>
                            {models.map((m) => (
                                <tr
                                    key={m.model}
                                    className="border-t border-tile-border"
                                >
                                    <td className="p-4 font-mono text-caption">
                                        {m.model}
                                    </td>
                                    <td className="p-4 tabular-nums">
                                        {usd(m.cost_in)} / {usd(m.cost_out)}
                                    </td>
                                    <td className="p-4 tabular-nums">
                                        {usd(m.pro_in)} / {usd(m.pro_out)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p
                    className="text-body text-muted-foreground"
                    data-testid="example-chat"
                >
                    For example, a {example_chat.questions}-question chat costs
                    about {usd(example_chat.free_usd)} on Free and{' '}
                    {usd(example_chat.pro_usd)} on Pro at our raw cost, which is
                    what it uses (list price {usd(example_chat.pro_list_usd)};
                    an estimate of about 3,000 tokens of your sources in and 400
                    out per question).
                </p>
            </Section>

            <Section
                title="What a podcast costs"
                intro={`Speech is the dearest thing you can do here. An audio overview is at most ${episode_chars.toLocaleString()} characters. Free members hear Mistral's voice; pro members and reviewers hear ElevenLabs.`}
                data-testid="speech-prices"
            >
                <div className="overflow-x-auto rounded-3xl bg-panel">
                    <table className="w-full min-w-[34rem] text-body">
                        <thead className="text-left">
                            <tr>
                                <th className="p-4">Voice</th>
                                <th className="p-4">
                                    Cost per 1,000 characters
                                </th>
                                <th className="p-4">Cost per episode</th>
                                <th className="p-4">
                                    List price (× {markup}) per 1,000 / episode
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {speech.map((s) => (
                                <tr
                                    key={s.tier}
                                    className="border-t border-tile-border"
                                >
                                    <td className="p-4">
                                        {s.voice}{' '}
                                        <span className="text-caption text-muted-foreground">
                                            ({s.tier})
                                        </span>
                                    </td>
                                    <td className="p-4 tabular-nums">
                                        {usd(s.cost_per_1k_chars)}
                                    </td>
                                    <td className="p-4 tabular-nums">
                                        up to {usd(s.cost_per_episode)}
                                    </td>
                                    <td className="p-4 tabular-nums">
                                        {usd(s.pro_per_1k_chars)} /{' '}
                                        {usd(s.pro_per_episode)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Section>
        </>
    );
}

function Faq({ plans, end_date, markup }: Props) {
    const items: [string, string][] = [
        [
            'What do I get on Free?',
            `Everything in the demo: sources, cited chat, notes, reports, a mind map and a podcast, on ${plans.free.chat_model} and ${plans.free.voice}'s voice, up to ${money(plans.free.cap)} of model use a month.`,
        ],
        [
            'What happens when I hit the limit?',
            'The demo says so and stops, instead of charging anyone. Your notebooks stay as they are. The budget starts again on the first of the month.',
        ],
        [
            'How does the test purchase work, and is any money taken?',
            `The button opens Stripe's own checkout in test mode. Pay with the test card ${TEST_CARD}, any future date and any CVC. No money moves, nothing renews, and there is nothing to cancel. A completed test purchase gives you ${plans.pro.days} days of Pro, with ${money(plans.pro.cap)} of model use a month while Pro is active, counted at our raw cost. Two purchases in one month still give ${money(plans.pro.cap)}. Our list price, cost × ${markup}, is what a real product would charge; here it is illustration only. Buying again renews the ${plans.pro.days} days and does not stack.`,
        ],
        [
            'What does "own key" mean?',
            'You would bring your own API key and pay your provider directly, with no limit from us. It is not in this demo: the key would have to be stored encrypted and never logged, and that was left for after the demo.',
        ],
        [
            'When is my data deleted?',
            `You can delete your account yourself in Settings, which removes your notebooks, sources, notes and generated items at once. Whatever remains is deleted on ${end_date}, when the demo ends. The Privacy page has the details.`,
        ],
    ];

    return (
        <Section title="Questions" data-testid="faq">
            <div className="divide-y divide-tile-border rounded-3xl bg-panel">
                {items.map(([q, a]) => (
                    <details key={q} className="group p-5">
                        <summary className="cursor-pointer list-none text-body font-medium marker:hidden">
                            {q}
                        </summary>
                        <p className="mt-2 text-body text-muted-foreground">
                            {a}
                        </p>
                    </details>
                ))}
            </div>
        </Section>
    );
}
