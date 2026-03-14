<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Campaign\CampaignTextSuggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignTextSuggestionRequested extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public CampaignTextSuggestion $suggestion)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ajustes sugeridos no texto da sua campanha - Nexa',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign-text-suggestion-requested',
            with: [
                'suggestion' => $this->suggestion,
                'campaign' => $this->suggestion->campaign,
                'brand' => $this->suggestion->campaign->brand,
                'admin' => $this->suggestion->admin,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
