<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Campaign\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignCreated extends Mailable
{
    use Queueable;
    use SerializesModels;

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
            subject: 'Campanha Criada com Sucesso - Nexa Platform',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign-created',
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
