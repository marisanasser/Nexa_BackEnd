<?php

namespace App\Mail;

use App\Models\CampaignApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $application;
    public $campaign;
    public $creator;
    public $brand;

    
    public function __construct(CampaignApplication $application)
    {
        $this->application = $application;
        $this->campaign = $application->campaign;
        $this->creator = $application->creator;
        $this->brand = $this->campaign->brand;
    }

    
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nova Candidatura Recebida - ' . $this->campaign->title,
        );
    }

    
    public function content(): Content
    {
        return new Content(
            view: 'emails.application-received',
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

