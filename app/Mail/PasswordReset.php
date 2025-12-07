<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $email;

    
    public function __construct($token, $email)
    {
        $this->token = $token;
        $this->email = $email;
    }

    
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Redefinir senha - Nexa Platform',
        );
    }

    
    public function content(): Content
    {
        
        
        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . urlencode($this->token) . '&email=' . urlencode($this->email);
        
        return new Content(
            view: 'emails.password-reset',
            with: [
                'resetUrl' => $resetUrl,
                'email' => $this->email,
            ],
        );
    }

    
    public function attachments(): array
    {
        return [];
    }
}

