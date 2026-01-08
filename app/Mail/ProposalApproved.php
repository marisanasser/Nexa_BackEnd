<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Campaign\CampaignApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalApproved extends Mailable
{
    use Queueable;
    use SerializesModels;

    public $application;

    public $campaign;

    public $creator;

    public $brand;

    public function __construct(CampaignApplication $application)
    {
        $this->application = $application;
        $this->campaign = $application->campaign;
        $this->creator = $application->creator;
        $this->brand = $application->campaign->brand;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '💖 Parabéns! Seu perfil foi selecionado! - Nexa Platform',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proposal-approved',
            with: [
                'application' => $this->application,
                'campaign' => $this->campaign,
                'creator' => $this->creator,
                'brand' => $this->brand,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
