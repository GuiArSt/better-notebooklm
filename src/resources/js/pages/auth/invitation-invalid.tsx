import { Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { login } from '@/routes';

export default function InvitationInvalid() {
    return (
        <>
            <Head title="Link not valid" />
            <p className="text-center text-sm text-muted-foreground">
                This invitation link has expired, was already used, or was
                replaced by a newer one. Ask for a new invitation, or{' '}
                <TextLink href={login()}>log in</TextLink> if you already have
                an account.
            </p>
        </>
    );
}

InvitationInvalid.layout = {
    title: 'Link not valid',
    description: 'This invitation cannot be used',
};
