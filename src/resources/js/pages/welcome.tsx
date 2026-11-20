import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { SiteFooter } from '@/components/site-footer';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

/** The public landing page. Sign-up is by invitation only, so there is only a sign-in button. */
export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-background p-6 text-center">
                <span className="flex size-14 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                    <AppLogoIcon className="size-8 fill-current" />
                </span>
                <h1 className="text-4xl font-semibold tracking-tight">
                    {name}
                </h1>
                <p className="max-w-md text-muted-foreground">
                    A working replica of NotebookLM, built as a demo for a job
                    test. Access is by invitation.
                </p>
                <Button asChild size="lg">
                    <Link href={auth.user ? dashboard() : login()}>
                        {auth.user ? 'Open my notebooks' : 'Sign in'}
                    </Link>
                </Button>
                <SiteFooter className="fixed inset-x-0 bottom-0 border-t-0" />
            </div>
        </>
    );
}
