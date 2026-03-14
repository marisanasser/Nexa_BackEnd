<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProfileApproved extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $user, public array $approvalData = [])
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seu perfil foi aprovado - Nexa',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.profile-approved',
            with: [
                'user' => $this->user,
                'approvalData' => $this->approvalData,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
