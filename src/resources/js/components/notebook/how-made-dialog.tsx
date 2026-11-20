import { KindBadge } from '@/components/notebook/kind-badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { KINDS, clock } from '@/lib/studio-kinds';
import type { StudioItem } from '@/types/studio';

/** The recorded settings as label/value rows; null for an item made before they were recorded. */
export function describeSettings(item: StudioItem): [string, string][] | null {
    const s = item.settings;

    if (!s) {
        return null;
    }

    const rows: [string, string][] = [
        ['Kind', KINDS[item.kind]?.label ?? item.kind],
        [
            'Sources',
            s.sources === 'all'
                ? 'All checked sources'
                : (s.sources ?? []).map((x) => x.title).join(', ') ||
                  'None recorded',
        ],
        [
            'Language',
            s.language === 'auto' || !s.language
                ? 'The language of the sources'
                : s.language,
        ],
        [
            'Instructions',
            (s.focus ?? s.instructions)?.trim()
                ? String(s.focus ?? s.instructions)
                : 'None',
        ],
        ['Plan', s.tier ?? 'unknown'],
    ];

    if (s.podcast) {
        rows.push(['Format', s.podcast.format]);

        if (s.podcast.persona) {
            rows.push([
                'Host',
                `${s.podcast.persona.name}, ${s.podcast.persona.role}`,
            ]);
        }
    }

    if (s.infographic) {
        rows.push([
            'Picture',
            `${s.infographic.orientation}, ${s.infographic.style}, ${s.infographic.detail} detail`,
        ]);
    }

    if (s.models?.image) {
        rows.push([
            'Drawing model',
            `${s.models.image.model} (${s.models.image.provider}), ${s.models.image.size}, ${s.models.image.quality} quality`,
        ]);
    }

    // format and persona are raw keys for code; the Format and Host rows above say them in words.
    const choices = Object.entries(s.options ?? {}).filter(
        ([k]) =>
            !(s.podcast && (k === 'format' || k === 'persona')) &&
            !(s.infographic && ['orientation', 'style', 'detail'].includes(k)),
    );

    if (choices.length > 0) {
        rows.push([
            'Choices',
            choices.map(([k, v]) => `${k}: ${v}`).join(', '),
        ]);
    }

    if (s.models?.text) {
        rows.push([
            'Writing model',
            `${s.models.text.model} (${s.models.text.provider})`,
        ]);
    }

    if (s.models?.speech) {
        const voices = Object.entries(s.models.speech.voices ?? {})
            .map(([host, id]) => `${host}: ${id}`)
            .join(', ');
        rows.push([
            'Voice',
            `${s.models.speech.model} (${s.models.speech.provider})${voices ? `, ${voices}` : ''}`,
        ]);
    }

    if (typeof item.duration_seconds === 'number') {
        rows.push(['Length', clock(item.duration_seconds)]);
    }

    if (typeof s.cost_micro_usd === 'number') {
        rows.push(['Cost', `$${(s.cost_micro_usd / 1_000_000).toFixed(4)}`]);
    }

    return rows;
}

export function HowMadeDialog({
    item,
    open,
    onOpenChange,
}: {
    item: StudioItem;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const rows = describeSettings(item);
    const kind = KINDS[item.kind];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="rounded-panel border-0 bg-panel">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        {kind && <KindBadge tile={kind.tile} size="lg" />}
                        <div className="min-w-0">
                            <p className="text-caption text-muted-foreground">
                                How this was made
                            </p>
                            <DialogTitle className="text-title font-normal">
                                {item.title}
                            </DialogTitle>
                        </div>
                    </div>
                    <DialogDescription className="sr-only">
                        The settings this item was made with. Use Make it again
                        to repeat them.
                    </DialogDescription>
                </DialogHeader>
                {rows ? (
                    <dl className="space-y-3 text-body" data-testid="how-made">
                        {rows.map(([label, value]) => (
                            <div
                                key={label}
                                className="grid grid-cols-[7rem_1fr] gap-3"
                            >
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="break-words">{value}</dd>
                            </div>
                        ))}
                    </dl>
                ) : (
                    <p
                        className="text-body text-muted-foreground"
                        data-testid="how-made-missing"
                    >
                        Not recorded for items made before today.
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}
