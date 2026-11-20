import { Head } from '@inertiajs/react';
import { Blocks, parseBlocks } from '@/lib/legal-markdown';

/** The text is the legal keeper's (docs/legal/privacy-notice.md); the server fills in the placeholders. */
export default function Privacy({ markdown }: { markdown: string }) {
    return (
        <>
            <Head title="Privacy" />
            <div
                className="mx-auto flex max-w-2xl flex-col gap-4 px-4 py-8 text-sm"
                data-testid="privacy"
            >
                <Blocks blocks={parseBlocks(markdown)} />
            </div>
        </>
    );
}
