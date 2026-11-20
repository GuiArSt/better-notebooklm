import { FlaskConical } from 'lucide-react';
import { useDemo } from '@/demo/use-demo';
import { TOGGLE, WELCOME, fill, money } from '@/demo/strings';
import type { Lang } from '@/demo/strings';

export function WelcomeBody({
    name,
    capUsd,
    lang,
    onToggle,
}: {
    name: string;
    capUsd: number;
    lang: Lang;
    onToggle: () => void;
}) {
    const first = name.trim().split(/\s+/)[0] ?? '';
    const vars = { name: first, cap: money(capUsd, lang) };

    return (
        <section
            className="flex flex-col gap-3 rounded-xl border p-4"
            data-testid="demo-welcome"
        >
            <div className="flex items-center justify-between gap-2">
                <span className="inline-flex w-fit items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                    <FlaskConical className="size-3.5" /> {WELCOME.badge[lang]}
                </span>
                <button
                    type="button"
                    onClick={onToggle}
                    className="text-xs text-muted-foreground underline hover:text-foreground"
                >
                    {TOGGLE[lang]}
                </button>
            </div>
            <h2 className="text-2xl font-semibold tracking-tight">
                {fill(WELCOME.title[lang], vars)}
            </h2>
            {WELCOME.paragraphs.map((p) => (
                <p key={p.en} className="text-sm text-muted-foreground">
                    {fill(p[lang], vars)}
                </p>
            ))}
        </section>
    );
}

/** For a reviewer only: a German welcome from the owner, with an English toggle. Reads the name from the signed-in user. */
export function WelcomeCard({ capUsd }: { capUsd: number }) {
    const demo = useDemo();

    if (!demo.guest) {
        return null;
    }

    return (
        <WelcomeBody
            name={demo.name}
            capUsd={capUsd}
            lang={demo.lang}
            onToggle={() => demo.setLang(demo.lang === 'de' ? 'en' : 'de')}
        />
    );
}
