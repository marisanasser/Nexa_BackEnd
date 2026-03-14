<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentVerificationRejected extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $user, public array $rejectionData = [])
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Atualizacao sobre sua verificacao estudantil - Nexa',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-verification-rejected',
            with: [
                'user' => $this->user,
                'rejectionData' => $this->rejectionData,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
