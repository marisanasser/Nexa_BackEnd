<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Campaign\DeliveryMaterial;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeliveryMaterialApproved extends Mailable
{
    use Queueable;
    use SerializesModels;

    public $material;

    public $contract;

    public $creator;

    public $brand;

    public function __construct(DeliveryMaterial $material)
    {
        $this->material = $material;
        $this->contract = $material->contract;
        $this->creator = $material->creator;
        $this->brand = $material->brand;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Material Aprovado - Nexa Platform',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.delivery-material-approved',
            with: [
                'material' => $this->material,
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
