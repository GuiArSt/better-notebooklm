import { Head } from '@inertiajs/react';
import { CthulhuCube } from '@/components/cthulhu-cube';
import poemsSource from '@/data/poems.md?raw';
import { Blocks, parseBlocks } from '@/lib/legal-markdown';
import { parsePoems } from '@/lib/poems';

const poems = parsePoems(poemsSource);
const SERIF = { fontFamily: 'Georgia, "Times New Roman", serif' };

function Poems() {
    return (
        <section
            data-testid="terms-poems"
            className="flex flex-col gap-4"
            style={SERIF}
        >
            <h3 className="text-2xl">Poems of the cube</h3>
            {poems.length === 0 ? (
                <p className="text-muted-foreground italic">
                    The cube is silent. For now.
                </p>
            ) : (
                poems.map((poem, i) => (
                    <article key={i} className="rounded-lg border p-4">
                        {poem.title && (
                            <h4 className="mb-2 font-semibold">{poem.title}</h4>
                        )}
                        <p className="whitespace-pre-line italic">
                            {poem.lines.join('\n')}
                        </p>
                    </article>
                ))
            )}
        </section>
    );
}

function Image() {
    return (
        <div
            data-testid="terms-image"
            className="flex flex-col items-center gap-2"
        >
            <CthulhuCube className="h-auto w-full max-w-md text-foreground" />
            <p className="text-xs text-muted-foreground">
                It sleeps beside the cube.
            </p>
        </div>
    );
}

/** The text is Themis's (docs/legal/terms.md): part 1 plain and first, part 2 the Covenant, part 3 the image and the poems. */
export default function Terms({ parts }: { title: string; parts: string[] }) {
    const [head, honest, covenant, cube] = parts;

    return (
        <>
            <Head title="Terms" />
            <div className="mx-auto flex max-w-2xl flex-col gap-10 px-4 py-8 text-sm">
                <section
                    data-testid="terms-serious"
                    className="flex flex-col gap-4"
                >
                    <Blocks blocks={parseBlocks(head ?? '')} />
                    <Blocks blocks={parseBlocks(honest ?? '')} />
                </section>

                <section
                    data-testid="terms-covenant"
                    className="flex flex-col gap-4 rounded-2xl border border-red-950 bg-zinc-950 p-6 text-base text-zinc-200 shadow-inner [&_h2]:text-center [&_h2]:text-2xl [&_h2]:tracking-[0.25em] [&_h2]:text-red-700 [&_h2]:uppercase [&_p:first-of-type]:text-center"
                    style={SERIF}
                >
                    <Blocks blocks={parseBlocks(covenant ?? '')} />
                </section>

                <section
                    data-testid="terms-cube"
                    className="flex flex-col gap-6"
                >
                    <Blocks
                        blocks={parseBlocks(cube ?? '')}
                        slots={{ image: <Image />, poems: <Poems /> }}
                    />
                </section>
            </div>
        </>
    );
}
