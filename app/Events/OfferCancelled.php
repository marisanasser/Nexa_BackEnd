<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\Offer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OfferCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $offer;

    public $chatRoom;

    public $senderId;

    /**
     * Create a new event instance.
     */
    public function __construct(Offer $offer, ChatRoom $chatRoom, int $senderId)
    {
        $this->offer = $offer;
        $this->chatRoom = $chatRoom;
        $this->senderId = $senderId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->chatRoom->room_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'offer_cancelled';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'roomId' => $this->chatRoom->room_id,
            'offerData' => [
                'id' => $this->offer->id,
                'title' => $this->offer->title,
                'description' => $this->offer->description,
                'budget' => $this->offer->budget,
                'formatted_budget' => $this->offer->formatted_budget,
                'estimated_days' => $this->offer->estimated_days,
                'status' => $this->offer->status,
                'brand_id' => $this->offer->brand_id,
                'creator_id' => $this->offer->creator_id,
                'chat_room_id' => $this->chatRoom->room_id,
            ],
            'senderId' => $this->senderId,
        ];
    }
}
