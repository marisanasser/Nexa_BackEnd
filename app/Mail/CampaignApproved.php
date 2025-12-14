<?php

namespace App\Mail;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $campaign;

    public $brand;

    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;
        $this->brand = $campaign->brand;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Parabéns! Sua campanha foi aprovada - Nexa Platform',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign-approved',
            with: [
                'campaign' => $this->campaign,
                'brand' => $this->brand,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
