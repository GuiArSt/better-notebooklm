import { modelBadges } from '@/lib/model-badge';
import type { StudioItem } from '@/types/studio';

/** Which models (and which plan) made an item, as small quiet labels; nothing for an item with no recorded settings. */
export function ModelBadges({
    item,
    className = '',
}: {
    item: Pick<StudioItem, 'settings'>;
    className?: string;
}) {
    const badges = modelBadges(item);

    if (badges.length === 0) {
        return null;
    }

    return (
        <span
            className={`flex flex-wrap gap-1 ${className}`}
            data-testid="model-badges"
        >
            {badges.map((badge) => (
                <span
                    key={badge}
                    className="rounded-full bg-bubble px-2 py-0.5 text-[10px] leading-none text-muted-foreground"
                >
                    {badge}
                </span>
            ))}
        </span>
    );
}
