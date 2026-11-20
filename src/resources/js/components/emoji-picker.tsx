import { NotebookGlyph, notebookGlyph } from '@/icons/notebook/notebook-glyph';
import { cn } from '@/lib/utils';

const EMOJIS = [
    '📓',
    '📚',
    '🔬',
    '🧪',
    '💡',
    '🎓',
    '📰',
    '🌍',
    '⚖️',
    '💼',
    '🧠',
    '🎨',
    '🎵',
    '🏛️',
    '🌱',
    '🚀',
];

/** A small fixed palette of drawn icons (D-059), one per notebook; the stored value stays the emoji each one stands for. */
export function EmojiPicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (emoji: string) => void;
}) {
    return (
        <div
            className="grid grid-cols-8 gap-1"
            role="radiogroup"
            aria-label="Icon"
        >
            {EMOJIS.map((emoji) => (
                <button
                    key={emoji}
                    type="button"
                    role="radio"
                    aria-checked={value === emoji}
                    aria-label={notebookGlyph(emoji).label}
                    onClick={() => onChange(emoji)}
                    className={cn(
                        'flex size-9 items-center justify-center rounded-md text-xl hover:bg-accent',
                        value === emoji && 'bg-accent ring-2 ring-primary',
                    )}
                >
                    <NotebookGlyph emoji={emoji} className="size-5" />
                </button>
            ))}
        </div>
    );
}
