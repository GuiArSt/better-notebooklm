import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import PublicLayout from '@/layouts/public-layout';
import {
    credit,
    him,
    poemByline,
    poemLines,
    poemTitle,
    story,
} from './content';

/** About (D-059): Ouroboros Creative Collective and its founder, the logo, the story of the build, and the poem. */
export default function About() {
    return (
        <>
            <Head title="About" />
            <div className="mx-auto flex max-w-2xl flex-col gap-12 px-4 py-10">
                <section
                    data-testid="about-who"
                    className="flex flex-col items-center gap-4 text-center"
                >
                    <span
                        data-testid="about-logo"
                        className="flex size-20 items-center justify-center rounded-card bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon className="size-12 fill-current" />
                    </span>
                    <h1 className="text-display">About</h1>
                    <p className="text-body text-muted-foreground">{credit}</p>
                </section>

                <section
                    data-testid="about-him"
                    className="flex flex-col gap-3"
                >
                    <h2 className="text-title">{him.title}</h2>
                    <p className="text-body leading-relaxed">{him.text}</p>
                    <p className="text-body text-muted-foreground">
                        Ouroboros Creative Collective: {him.collective}
                    </p>
                    <a
                        href={him.linkUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-body font-medium underline underline-offset-4"
                    >
                        {him.linkLabel}
                    </a>
                </section>

                <section
                    data-testid="about-story"
                    className="flex flex-col gap-4"
                >
                    <h2 className="text-title">How it was built</h2>
                    {story.map((paragraph) => (
                        <p
                            key={paragraph}
                            className="text-body leading-relaxed"
                        >
                            {paragraph}
                        </p>
                    ))}
                </section>

                <section
                    data-testid="about-poem"
                    className="flex flex-col gap-3 rounded-card bg-card-soft p-6"
                >
                    <h2 className="text-title">{poemTitle}</h2>
                    <p className="text-caption text-muted-foreground">
                        {poemByline}. Published here at the author&rsquo;s
                        request.
                    </p>
                    <p
                        className="font-serif text-body leading-relaxed italic"
                        style={{ whiteSpace: 'pre-line' }}
                    >
                        {poemLines.join('\n')}
                    </p>
                </section>
            </div>
        </>
    );
}

About.layout = (page: ReactNode) => <PublicLayout>{page}</PublicLayout>;
