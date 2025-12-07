<?php

namespace App\Mail;

use App\Models\CampaignTimeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MilestoneDelayWarning extends Mailable
{
    use Queueable, SerializesModels;

    public $milestone;
    public $contract;
    public $creator;
    public $brand;

    
    public function __construct(CampaignTimeline $milestone)
    {
        $this->milestone = $milestone;
        $this->contract = $milestone->contract;
        $this->creator = $milestone->contract->creator;
        $this->brand = $milestone->contract->brand;
    }

    
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Aviso de Atraso - Milestone - Nexa Platform',
        );
    }

    
    public function content(): Content
    {
        return new Content(
            view: 'emails.milestone-delay-warning',
            with: [
                'milestone' => $this->milestone,
                'contract' => $this->contract,
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