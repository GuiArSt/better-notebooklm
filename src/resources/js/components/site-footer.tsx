import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

type Legal = { impressum_url: string; datenschutz_url: string };

/** On every page: the Impressum (the owner's own page, one config value) and our privacy page. */
export function SiteFooter({ className }: { className?: string }) {
    const legal = usePage<{ legal?: Legal }>().props.legal;

    return (
        <footer
            className={cn(
                'flex shrink-0 items-center justify-center gap-4 px-4 py-2 text-caption text-muted-foreground',
                className,
            )}
            data-testid="site-footer"
        >
            <a
                href={legal?.impressum_url ?? '#'}
                target="_blank"
                rel="noopener noreferrer"
                className="hover:text-foreground hover:underline"
            >
                Impressum
            </a>
            <Link
                href="/about"
                className="hover:text-foreground hover:underline"
            >
                About
            </Link>
            <Link
                href="/privacy"
                className="hover:text-foreground hover:underline"
            >
                Privacy
            </Link>
            <Link
                href="/terms"
                className="hover:text-foreground hover:underline"
            >
                Terms
            </Link>
        </footer>
    );
}
