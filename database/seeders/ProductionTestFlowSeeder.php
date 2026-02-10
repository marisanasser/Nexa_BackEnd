<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contract\Services\ContractStateMachineService;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignApplication;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Illuminate\Database\Seeder;

class ProductionTestFlowSeeder extends Seeder
{
    public function run(): void
    {
        $brand = User::where('email', 'brand.teste@nexacreators.com.br')->first();
        $creator = User::where('email', 'creator.premium@nexacreators.com.br')->first();

        if (!$brand || !$creator) {
            $this->command->error('Brand ou Creator não encontrados. Rode ProductionTestUsersSeeder primeiro.');

            return;
        }

        $campaign = Campaign::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'title' => 'Campanha Fluxo Novo Premium',
            ],
            [
                'description' => 'Campanha criada para testar chat, oferta e contrato.',
                'budget' => 4500,
                'location' => 'São Paulo',
                'requirements' => 'Briefing inicial no chat',
                'target_states' => ['SP'],
                'category' => 'Tecnologia',
                'campaign_type' => 'instagram',
                'status' => 'approved',
                'deadline' => now()->addDays(30),
                'max_bids' => 10,
                'is_active' => true,
            ]
        );

        CampaignApplication::updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'creator_id' => $creator->id,
            ],
            [
                'status' => 'approved',
                'workflow_status' => 'agreement_in_progress',
                'reviewed_by' => $brand->id,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'first_contact_at' => now(),
                'proposal' => 'Aplicação de teste para fluxo novo',
                'portfolio_links' => [],
                'estimated_delivery_days' => 15,
                'proposed_budget' => 4500,
            ]
        );

        $chatRoom = ChatRoom::findOrCreateRoom($campaign->id, $brand->id, $creator->id);

        if ($chatRoom->messages()->count() === 0) {
            Message::create([
                'chat_room_id' => $chatRoom->id,
                'sender_id' => $brand->id,
                'message' => 'Olá! Vamos iniciar o briefing da campanha.',
                'message_type' => 'text',
            ]);

            Message::create([
                'chat_room_id' => $chatRoom->id,
                'sender_id' => $creator->id,
                'message' => 'Perfeito, aguardando os detalhes do briefing.',
                'message_type' => 'text',
            ]);
        }

        if (!$chatRoom->messages()->where('message', 'like', 'Briefing:%')->exists()) {
            Message::create([
                'chat_room_id' => $chatRoom->id,
                'sender_id' => $brand->id,
                'message' => 'Briefing: Conteúdo sobre lançamento do produto, foco em tecnologia e lifestyle.',
                'message_type' => 'text',
            ]);
        }

        $offerData = [
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'chat_room_id' => $chatRoom->id,
            'campaign_id' => $campaign->id,
            'title' => 'Oferta Fluxo Novo',
            'description' => 'Oferta para testar geração de contrato e milestones.',
            'budget' => 4500,
            'estimated_days' => 15,
            'requirements' => ['Briefing inicial enviado no chat'],
            'status' => 'pending',
            'expires_at' => now()->addDays(5),
            'accepted_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ];

        $offer = Offer::where('brand_id', $brand->id)
            ->where('creator_id', $creator->id)
            ->where('status', 'pending')
            ->first();

        if ($offer) {
            $offer->update($offerData);
            $offer->refresh();
        } else {
            $offer = Offer::create($offerData);
        }

        $this->ensurePendingOfferMessage($chatRoom, $offer, $brand);

        if ($offer->status !== 'accepted') {
            $offer->accept();
            $offer->refresh();
        }

        $contract = $offer->contract;

        if ($contract) {
            $this->ensureAcceptedOfferMessage($chatRoom, $offer, $creator, $contract);

            $service = app(ContractStateMachineService::class);

            if ($contract->milestones()->count() === 0) {
                $service->initialize($contract);
            }

            $this->submitAndApproveMilestones($service, $contract, $brand, $creator);
        }

        $contract = $contract?->fresh(['milestones']);

        $this->command->info("Chat Room: {$chatRoom->room_id} (ID: {$chatRoom->id})");
        $this->command->info("Offer ID: {$offer->id} | Status: {$offer->status}");
        $this->command->info('Contract ID: '.($contract?->id ?? 'null'));

        if ($contract) {
            foreach ($contract->milestones()->orderBy('order')->get() as $milestone) {
                $this->command->info("Milestone {$milestone->order}: {$milestone->title} - {$milestone->status}");
            }
        }
    }

    private function ensurePendingOfferMessage(ChatRoom $chatRoom, Offer $offer, User $brand): void
    {
        $existing = Message::where('chat_room_id', $chatRoom->id)
            ->where('message_type', 'offer')
            ->get()
            ->first(function (Message $message) use ($offer) {
                $data = $message->offer_data;

                return is_array($data)
                    && isset($data['offer_id'])
                    && (int) $data['offer_id'] === (int) $offer->id
                    && ($data['status'] ?? null) === 'pending';
            })
        ;

        if ($existing) {
            return;
        }

        Message::create([
            'chat_room_id' => $chatRoom->id,
            'sender_id' => $brand->id,
            'message' => "Oferta enviada: {$offer->formatted_budget}",
            'message_type' => 'offer',
            'offer_data' => [
                'offer_id' => $offer->id,
                'title' => $offer->title,
                'description' => $offer->description,
                'budget' => $offer->budget,
                'formatted_budget' => $offer->formatted_budget,
                'estimated_days' => $offer->estimated_days,
                'status' => 'pending',
                'expires_at' => $offer->expires_at?->format('Y-m-d H:i:s'),
                'days_until_expiry' => $offer->days_until_expiry,
                'is_expiring_soon' => $offer->is_expiring_soon,
                'created_at' => $offer->created_at?->toISOString(),
                'is_barter' => false,
                'barter_description' => null,
                'can_be_accepted' => true,
                'can_be_rejected' => true,
                'can_be_cancelled' => true,
                'sender' => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'avatar_url' => $brand->avatar_url,
                ],
            ],
        ]);
    }

    private function ensureAcceptedOfferMessage(ChatRoom $chatRoom, Offer $offer, User $creator, $contract): void
    {
        $existing = Message::where('chat_room_id', $chatRoom->id)
            ->where('message_type', 'offer')
            ->get()
            ->first(function (Message $message) use ($offer) {
                $data = $message->offer_data;

                return is_array($data)
                    && isset($data['offer_id'])
                    && (int) $data['offer_id'] === (int) $offer->id
                    && ($data['status'] ?? null) === 'accepted';
            })
        ;

        if ($existing) {
            return;
        }

        Message::create([
            'chat_room_id' => $chatRoom->id,
            'sender_id' => $creator->id,
            'message' => 'Oferta aceita! Contrato criado.',
            'message_type' => 'offer',
            'offer_data' => [
                'offer_id' => $offer->id,
                'title' => $offer->title,
                'description' => $offer->description,
                'budget' => $offer->budget,
                'formatted_budget' => $offer->formatted_budget,
                'estimated_days' => $offer->estimated_days,
                'status' => $offer->status,
                'contract_id' => $contract->id,
                'contract_status' => $contract->status,
                'can_be_completed' => $contract->canBeCompleted(),
                'sender' => [
                    'id' => $creator->id,
                    'name' => $creator->name,
                    'avatar_url' => $creator->avatar_url,
                ],
            ],
        ]);

        Message::create([
            'chat_room_id' => $chatRoom->id,
            'sender_id' => null,
            'message' => 'A criadora aceitou a oferta.',
            'message_type' => 'system',
            'is_system_message' => true,
        ]);
    }

    private function submitAndApproveMilestones(
        ContractStateMachineService $service,
        $contract,
        User $brand,
        User $creator
    ): void {
        $first = $contract->milestones()->orderBy('order')->first();

        if ($first && in_array($first->status, ['pending', 'in_progress', 'changes_requested'], true)) {
            if ($first->status !== 'submitted') {
                $service->submitMilestone($first, [
                    'briefing' => 'Briefing enviado pela marca e confirmado pelo creator.',
                    'assets' => [
                        'link' => 'https://example.com/briefing',
                    ],
                ], $creator);
            }

            $first = $first->fresh();

            if ($first->status === 'submitted') {
                $service->approveMilestone($first, $brand);
            }
        }

        $next = $contract->milestones()->where('status', 'in_progress')->orderBy('order')->first();

        if ($next) {
            $service->submitMilestone($next, [
                'roteiro' => 'Roteiro enviado para aprovação.',
                'arquivo' => 'https://example.com/roteiro',
            ], $creator);

            $next = $next->fresh();

            if ($next->status === 'submitted') {
                $service->approveMilestone($next, $brand);
            }
        }
    }
}
