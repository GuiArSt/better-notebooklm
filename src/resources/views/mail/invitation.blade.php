<x-mail::message>
# You are invited

{{ $inviter?->name ?? 'The owner' }} invited you to {{ config('app.name') }}.

<x-mail::button :url="$link">
Set your password
</x-mail::button>

The link works once and expires in 72 hours. If you did not expect this, ignore this mail.
</x-mail::message>
