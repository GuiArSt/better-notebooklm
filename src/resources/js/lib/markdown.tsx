import type { ReactNode } from 'react';
import { CITATION_GROUP, citationIds } from '@/lib/citations';

// Bold, code, italic or a citation marker (the same pattern as the server's, in lib/citations.ts).
const INLINE = new RegExp(
    `(\\*\\*[^*]+\\*\\*|\`[^\`]+\`|\\*[^*\\s][^*]*\\*|${CITATION_GROUP.source})`,
    'g',
);

/** Turns the text of one line into inline pieces: **bold**, *italic*, `code`, and [P12] citation markers. */
function inline(
    text: string,
    cite: (passageId: number, key: string) => ReactNode,
    keyPrefix: string,
): ReactNode[] {
    const parts = text.split(INLINE);

    return parts.map((part, i) => {
        const key = `${keyPrefix}-${i}`;
        // One marker, or a group like [P260, P261]: one chip per id; an id the server did not validate draws nothing.
        if (new RegExp(`^${CITATION_GROUP.source}$`).test(part)) {
            return (
                <span key={key}>
                    {citationIds(part).map((id) => cite(id, `${key}-${id}`))}
                </span>
            );
        }

        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
            return <strong key={key}>{part.slice(2, -2)}</strong>;
        }

        if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
            return (
                <code key={key} className="rounded bg-muted px-1 text-[0.9em]">
                    {part.slice(1, -1)}
                </code>
            );
        }

        if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
            return <em key={key}>{part.slice(1, -1)}</em>;
        }

        return <span key={key}>{part}</span>;
    });
}

/**
 * A small Markdown reader for model output: headings, bullet and numbered lists, paragraphs, bold, italic, code.
 * Citation markers like [P12] are handed to `cite`, which draws them (or draws nothing, to drop them).
 */
export function Markdown({
    text,
    cite,
    className,
}: {
    text: string;
    cite: (passageId: number, key: string) => ReactNode;
    className?: string;
}) {
    const blocks: ReactNode[] = [];
    let list: { ordered: boolean; items: string[] } | null = null;
    let paragraph: string[] = [];

    const flushParagraph = () => {
        if (paragraph.length) {
            const key = `p${blocks.length}`;
            blocks.push(
                <p key={key} className="leading-relaxed">
                    {inline(paragraph.join(' '), cite, key)}
                </p>,
            );
            paragraph = [];
        }
    };

    const flushList = () => {
        if (list) {
            const key = `l${blocks.length}`;
            const Tag = list.ordered ? 'ol' : 'ul';
            blocks.push(
                <Tag
                    key={key}
                    className={
                        list.ordered
                            ? 'ml-5 list-decimal space-y-1'
                            : 'ml-5 list-disc space-y-1'
                    }
                >
                    {list.items.map((item, i) => (
                        <li key={i}>{inline(item, cite, `${key}-${i}`)}</li>
                    ))}
                </Tag>,
            );
            list = null;
        }
    };

    for (const line of text.split('\n')) {
        // A horizontal rule: a line of three or more dashes, asterisks or underscores.
        if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
            flushParagraph();
            flushList();
            blocks.push(
                <hr
                    key={`r${blocks.length}`}
                    className="my-2 border-tile-border"
                />,
            );
            continue;
        }

        const heading = line.match(/^(#{1,4})\s+(.*)$/);
        const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);

        if (heading) {
            flushParagraph();
            flushList();
            const key = `h${blocks.length}`;
            const size =
                heading[1].length <= 1
                    ? 'text-xl'
                    : heading[1].length === 2
                      ? 'text-lg'
                      : 'text-base';
            blocks.push(
                <h3 key={key} className={`${size} mt-2 font-semibold`}>
                    {inline(heading[2], cite, key)}
                </h3>,
            );
        } else if (bullet || numbered) {
            flushParagraph();
            const ordered = !bullet;

            if (list && list.ordered !== ordered) {
                flushList();
            }

            list ??= { ordered, items: [] };
            list.items.push((bullet ?? numbered)![1]);
        } else if (line.trim() === '') {
            flushParagraph();
            flushList();
        } else {
            flushList();
            paragraph.push(line.trim());
        }
    }

    flushParagraph();
    flushList();

    return <div className={`space-y-3 ${className ?? ''}`}>{blocks}</div>;
}
