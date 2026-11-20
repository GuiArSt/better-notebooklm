import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { SiteFooter } from '@/components/site-footer';
import FrameLayout from '@/layouts/frame-layout';

/** For pages anyone may read, signed in or not: a slim bar and the footer. Signed in, the whole app frame, so the way around is still there. */
export default function PublicLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { name, auth } = usePage().props;

    if (auth.user) {
        return <FrameLayout>{children}</FrameLayout>;
    }

    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
                <Link href="/" className="flex items-center gap-2">
                    <span className="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                        <AppLogoIcon className="size-5 fill-current" />
                    </span>
                    <span className="font-semibold">{name}</span>
                </Link>
            </header>
            <main className="flex-1">{children}</main>
            <SiteFooter />
        </div>
    );
}
