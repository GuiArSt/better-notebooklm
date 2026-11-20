import { Link, usePage } from '@inertiajs/react';
import { usd } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { Usage } from '@/types/app';

/** The month's spending against the user's cap, from the ledger. */
export function UsageMeter({ className }: { className?: string }) {
    const usage = usePage<{ usage?: Usage }>().props.usage;

    if (!usage) {
        return null;
    }

    const tone =
        usage.percent >= 90
            ? 'bg-destructive'
            : usage.percent >= 70
              ? 'bg-amber-500'
              : 'bg-emerald-500';

    return (
        <Link
            href="/pricing"
            title={`Your ${usage.label} budget this month. It resets on the 1st.`}
            className={cn('flex flex-col gap-1 text-xs', className)}
            data-testid="usage-meter"
        >
            <span className="flex justify-between gap-3">
                <span className="font-medium capitalize">
                    {usage.label} budget
                </span>
                <span className="text-muted-foreground tabular-nums">
                    {usd(usage.spent_usd)} / {usd(usage.cap_usd)}
                </span>
            </span>
            <span className="h-1.5 w-32 overflow-hidden rounded-full bg-muted">
                <span
                    className={cn('block h-full rounded-full', tone)}
                    style={{ width: `${Math.max(2, usage.percent)}%` }}
                />
            </span>
        </Link>
    );
}
