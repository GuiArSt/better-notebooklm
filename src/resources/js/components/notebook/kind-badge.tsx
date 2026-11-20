import type { ComponentType, SVGAttributes } from 'react';
import {
    AudioIcon,
    FlashcardsIcon,
    InfographicIcon,
    MindmapIcon,
    QuizIcon,
    ReportsIcon,
    SlidesIcon,
    TableIcon,
    VideoIcon,
} from '@/components/studio-icons';
import { TILE_TINTS } from '@/lib/studio-tints';
import type { TileKey } from '@/lib/studio-tints';
import { cn } from '@/lib/utils';

export const TILE_ICONS: Record<
    TileKey,
    ComponentType<SVGAttributes<SVGElement>>
> = {
    audio: AudioIcon,
    video: VideoIcon,
    mindmap: MindmapIcon,
    reports: ReportsIcon,
    flashcards: FlashcardsIcon,
    quiz: QuizIcon,
    infographic: InfographicIcon,
    slides: SlidesIcon,
    table: TableIcon,
};

/** The identity of a Studio tile as a tinted rounded square: its icon in its colour, so it reads before the title does. */
export function KindBadge({
    tile,
    size = 'md',
    className,
}: {
    tile: TileKey;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}) {
    const Icon = TILE_ICONS[tile];
    const tint = TILE_TINTS[tile];

    return (
        <span
            data-testid="kind-badge"
            data-tile={tile}
            className={cn(
                'flex shrink-0 items-center justify-center',
                size === 'sm' && 'size-8 rounded-lg',
                size === 'md' && 'size-10 rounded-xl',
                size === 'lg' && 'size-12 rounded-2xl',
                tint.soft,
                tint.text,
                className,
            )}
        >
            <Icon
                className={
                    size === 'sm'
                        ? 'size-4'
                        : size === 'md'
                          ? 'size-5'
                          : 'size-6'
                }
            />
        </span>
    );
}
