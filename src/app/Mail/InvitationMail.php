<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public function __construct(
        public Invitation $invitation,
        public string $link,
        public ?User $inviter = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation');
    }
}
