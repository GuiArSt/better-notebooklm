import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { NotebookSkeleton } from '@/components/notebook/notebook-skeleton';
import { SiteFooter } from '@/components/site-footer';
import { UsageMeter } from '@/components/usage-meter';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { isNotebookVisit } from '@/lib/notebook-visit';

/** The frame around every signed-in page: a slim bar, then the page. The notebook fills the rest of the screen. */
export default function FrameLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { auth, name } = usePage().props;
    // The notebook fills the screen and scrolls inside its panels, so its footer stays pinned; every other page scrolls whole.
    const fillsScreen = usePage().component === 'notebooks/show';
    const getInitials = useInitials();
    // Opening a notebook can take seconds: show its skeleton at once instead of the page we are leaving.
    const [opening, setOpening] = useState(false);

    useEffect(() => {
        const start = router.on('start', (e) =>
            setOpening(isNotebookVisit(e.detail.visit.url.pathname)),
        );
        const finish = router.on('finish', () => setOpening(false));

        return () => {
            start();
            finish();
        };
    }, []);

    const body = opening ? <NotebookSkeleton /> : children;

    return (
        <div className="flex h-dvh flex-col bg-canvas">
            <header className="flex h-14 shrink-0 items-center gap-3 px-3 sm:px-4">
                <Link href="/notebooks" className="flex items-center gap-2">
                    <span className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                        <AppLogoIcon className="size-5 fill-current" />
                    </span>
                    <span className="hidden font-semibold sm:inline">
                        {name}
                    </span>
                </Link>
                <nav className="ml-2 flex items-center gap-1 text-sm">
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/notebooks">Notebooks</Link>
                    </Button>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/pricing">Pricing</Link>
                    </Button>
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="hidden sm:inline-flex"
                    >
                        <Link href="/settings/profile">Settings</Link>
                    </Button>
                    {auth.user?.role === 'owner' && (
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="hidden sm:inline-flex"
                        >
                            <Link href="/admin/users">Users</Link>
                        </Button>
                    )}
                </nav>
                <div className="ml-auto flex items-center gap-3">
                    <UsageMeter className="hidden sm:flex" />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                className="size-10 rounded-full p-1"
                                aria-label="Account"
                            >
                                <Avatar className="size-8 overflow-hidden rounded-full">
                                    <AvatarImage
                                        src={auth.user?.avatar}
                                        alt={auth.user?.name}
                                    />
                                    <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                        {getInitials(auth.user?.name ?? '')}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="w-56" align="end">
                            {auth.user && <UserMenuContent user={auth.user} />}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>
            {fillsScreen ? (
                <>
                    <main className="min-h-0 flex-1 overflow-auto">{body}</main>
                    <SiteFooter />
                </>
            ) : (
                // A long page scrolls with its footer at the end, so nothing is cut off above a pinned strip.
                <main className="min-h-0 flex-1 overflow-auto">
                    <div className="flex min-h-full flex-col">
                        <div className="flex-1">{body}</div>
                        <SiteFooter className="py-4" />
                    </div>
                </main>
            )}
        </div>
    );
}
