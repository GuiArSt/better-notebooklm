import { createInertiaApp, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { DemoLayer } from '@/demo/demo-layer';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';
import FrameLayout from '@/layouts/frame-layout';
import PublicLayout from '@/layouts/public-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { writeErrorMessage } from '@/lib/write-errors';
import { initSentry } from '@/sentry/init';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name === 'privacy':
            case name === 'terms':
                return PublicLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [FrameLayout, SettingsLayout];
            default:
                return FrameLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
                <DemoLayer page={page} />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#38bdf8',
    },
});

// A note or source write the server refuses would otherwise fail without a word.
router.on('error', (event) => {
    const message = writeErrorMessage(
        new URL(event.detail.page?.url ?? '/', window.location.origin),
        event.detail.errors,
    );

    if (message) {
        toast.error(message);
    }
});

// Error monitoring (D-043): on only when the server hands over a DSN, and started once.
router.on('navigate', (event) => {
    const sentry = event.detail.page.props.sentry as
        | {
              dsn: string | null;
              environment: string | null;
              release: string | null;
          }
        | undefined;

    initSentry(sentry?.dsn, sentry?.environment, sentry?.release);
});

// This will set light / dark mode on load...
initializeTheme();
