import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

/** A small reader for the legal texts: headings, paragraphs, nested lists, tables, links, bold, italic, code and slots. */

type ListItem = { text: string; children: ListItem[]; ordered: boolean };

export type Block =
    | { type: 'heading'; level: number; text: string }
    | { type: 'paragraph'; text: string }
    | { type: 'list'; items: ListItem[]; ordered: boolean }
    | { type: 'table'; head: string[]; rows: string[][] }
    | { type: 'slot'; name: string };

const MARKER = /^(\s*)([-*]|\d+\.)\s+(.*)$/;

function cells(line: string): string[] {
    return line
        .trim()
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .split('|')
        .map((c) => c.trim());
}

export function parseBlocks(source: string): Block[] {
    const lines = source.split('\n');
    const blocks: Block[] = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];
        const trimmed = line.trim();

        if (trimmed === '' || /^---+$/.test(trimmed)) {
            i++;
            continue;
        }

        const slot = trimmed.match(/^<!--\s*(image|poems)\b.*-->$/);

        if (slot) {
            blocks.push({ type: 'slot', name: slot[1] });
            i++;
            continue;
        }

        if (trimmed.startsWith('<!--')) {
            while (i < lines.length && !lines[i].includes('-->')) {
                i++;
            }

            i++;
            continue;
        }

        const heading = trimmed.match(/^(#{1,4})\s+(.*)$/);

        if (heading) {
            blocks.push({
                type: 'heading',
                level: heading[1].length,
                text: heading[2],
            });
            i++;
            continue;
        }

        if (trimmed.startsWith('|')) {
            const rows: string[][] = [];

            while (i < lines.length && lines[i].trim().startsWith('|')) {
                rows.push(cells(lines[i]));
                i++;
            }

            const [head, , ...body] = rows;
            blocks.push({ type: 'table', head, rows: body });
            continue;
        }

        if (MARKER.test(line)) {
            const items: { indent: number; item: ListItem }[] = [];

            while (i < lines.length && lines[i].trim() !== '') {
                const m = lines[i].match(MARKER);

                if (m) {
                    items.push({
                        indent: m[1].length,
                        item: {
                            text: m[3],
                            children: [],
                            ordered: /^\d/.test(m[2]),
                        },
                    });
                } else if (items.length > 0 && /^\s+\S/.test(lines[i])) {
                    items[items.length - 1].item.text += ' ' + lines[i].trim();
                } else {
                    break;
                }

                i++;
            }

            const root: ListItem[] = [];
            const stack: { indent: number; item: ListItem }[] = [];

            for (const entry of items) {
                while (
                    stack.length &&
                    stack[stack.length - 1].indent >= entry.indent
                ) {
                    stack.pop();
                }

                (stack.length
                    ? stack[stack.length - 1].item.children
                    : root
                ).push(entry.item);
                stack.push(entry);
            }

            blocks.push({
                type: 'list',
                items: root,
                ordered: root[0]?.ordered ?? false,
            });
            continue;
        }

        const para: string[] = [];

        while (
            i < lines.length &&
            lines[i].trim() !== '' &&
            !/^#{1,4}\s/.test(lines[i].trim()) &&
            !lines[i].trim().startsWith('|') &&
            !lines[i].trim().startsWith('<!--') &&
            !/^---+$/.test(lines[i].trim()) &&
            !(para.length > 0 && MARKER.test(lines[i]))
        ) {
            para.push(lines[i].trim());
            i++;
        }

        blocks.push({ type: 'paragraph', text: para.join(' ') });
    }

    return blocks;
}

/** Links are only ever http(s) addresses or paths of this site: anything else is shown as plain text. */
function safeHref(href: string): string | null {
    return /^https?:\/\//i.test(href) || /^\/(?!\/)/.test(href) ? href : null;
}

export function Inline({ text }: { text: string }) {
    const parts = text.split(
        /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\)|\*[^*\s][^*]*\*)/g,
    );

    return (
        <>
            {parts.map((part, i): ReactNode => {
                const link = part.match(/^\[([^\]]+)\]\(([^)]+)\)$/);

                if (link) {
                    const href = safeHref(link[2]);

                    if (!href) {
                        return <span key={i}>{link[1]}</span>;
                    }

                    return href.startsWith('/') ? (
                        <Link
                            key={i}
                            href={href}
                            className="text-primary underline"
                        >
                            {link[1]}
                        </Link>
                    ) : (
                        <a
                            key={i}
                            href={href}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-primary underline"
                        >
                            {link[1]}
                        </a>
                    );
                }

                if (
                    part.startsWith('**') &&
                    part.endsWith('**') &&
                    part.length > 4
                ) {
                    return (
                        <strong key={i}>
                            <Inline text={part.slice(2, -2)} />
                        </strong>
                    );
                }

                if (
                    part.startsWith('`') &&
                    part.endsWith('`') &&
                    part.length > 2
                ) {
                    return (
                        <code
                            key={i}
                            className="rounded bg-muted px-1 text-[0.9em] text-foreground"
                        >
                            {part.slice(1, -1)}
                        </code>
                    );
                }

                if (
                    part.startsWith('*') &&
                    part.endsWith('*') &&
                    part.length > 2
                ) {
                    return <em key={i}>{part.slice(1, -1)}</em>;
                }

                return <span key={i}>{part}</span>;
            })}
        </>
    );
}

function List({ items, ordered }: { items: ListItem[]; ordered: boolean }) {
    const Tag = ordered ? 'ol' : 'ul';

    return (
        <Tag
            className={
                ordered
                    ? 'ml-5 list-decimal space-y-2'
                    : 'ml-5 list-disc space-y-2'
            }
        >
            {items.map((item, i) => (
                <li key={i}>
                    <Inline text={item.text} />
                    {item.children.length > 0 && (
                        <div className="mt-2">
                            <List
                                items={item.children}
                                ordered={item.children[0].ordered}
                            />
                        </div>
                    )}
                </li>
            ))}
        </Tag>
    );
}

const HEADINGS: Record<number, string> = {
    1: 'text-3xl font-semibold tracking-tight',
    2: 'text-xl font-semibold',
    3: 'text-lg font-semibold',
    4: 'font-semibold',
};

/** Renders blocks; a `slot` block is drawn by `slots[name]` (for the image and the poems of /terms). */
export function Blocks({
    blocks,
    slots = {},
}: {
    blocks: Block[];
    slots?: Record<string, ReactNode>;
}) {
    return (
        <>
            {blocks.map((block, i) => {
                switch (block.type) {
                    case 'heading': {
                        const Tag = `h${Math.min(block.level + 0, 4)}` as
                            | 'h1'
                            | 'h2'
                            | 'h3'
                            | 'h4';

                        return (
                            <Tag key={i} className={HEADINGS[block.level]}>
                                <Inline text={block.text} />
                            </Tag>
                        );
                    }
                    case 'paragraph':
                        return (
                            <p key={i} className="leading-relaxed">
                                <Inline text={block.text} />
                            </p>
                        );
                    case 'list':
                        return (
                            <List
                                key={i}
                                items={block.items}
                                ordered={block.ordered}
                            />
                        );
                    case 'table':
                        return (
                            <div
                                key={i}
                                className="overflow-x-auto rounded-lg border"
                            >
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            {block.head.map((h, j) => (
                                                <th key={j} className="p-2">
                                                    <Inline text={h} />
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {block.rows.map((row, r) => (
                                            <tr
                                                key={r}
                                                className="border-t align-top"
                                            >
                                                {row.map((cell, c) => (
                                                    <td key={c} className="p-2">
                                                        <Inline text={cell} />
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    case 'slot':
                        return <div key={i}>{slots[block.name] ?? null}</div>;
                }
            })}
        </>
    );
}
