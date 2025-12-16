<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestChatDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CustomTestUsersSeeder::class);

        $brand1 = User::firstOrCreate(
            ['email' => 'brand1.test@nexa.local'],
            [
                'name' => 'Brand Test 1',
                'password' => Hash::make('password'),
                'role' => 'brand',
                'email_verified_at' => now(),
            ]
        );

        $brand2 = User::firstOrCreate(
            ['email' => 'brand2.test@nexa.local'],
            [
                'name' => 'Brand Test 2',
                'password' => Hash::make('password'),
                'role' => 'brand',
                'email_verified_at' => now(),
            ]
        );

        $creator1 = User::firstOrCreate(
            ['email' => 'creator1.test@nexa.local'],
            [
                'name' => 'Creator Test 1',
                'password' => Hash::make('password'),
                'role' => 'creator',
                'email_verified_at' => now(),
            ]
        );

        $creator2 = User::firstOrCreate(
            ['email' => 'creator2.test@nexa.local'],
            [
                'name' => 'Creator Test 2',
                'password' => Hash::make('password'),
                'role' => 'creator',
                'email_verified_at' => now(),
            ]
        );

        $campaign1 = Campaign::firstOrCreate(
            [
                'brand_id' => $brand1->id,
                'title' => 'Campaign Test 1',
            ],
            [
                'description' => 'Campanha de teste 1 para Brand Test 1',
                'budget' => 1000,
                'location' => 'São Paulo',
                'requirements' => 'Requisitos de teste 1',
                'target_states' => ['SP'],
                'category' => 'teste',
                'campaign_type' => 'instagram',
                'status' => 'approved',
                'deadline' => now()->addDays(30),
                'max_bids' => 10,
                'is_active' => true,
            ]
        );

        $campaign2 = Campaign::firstOrCreate(
            [
                'brand_id' => $brand2->id,
                'title' => 'Campaign Test 2',
            ],
            [
                'description' => 'Campanha de teste 2 para Brand Test 2',
                'budget' => 2000,
                'location' => 'Rio de Janeiro',
                'requirements' => 'Requisitos de teste 2',
                'target_states' => ['RJ'],
                'category' => 'teste',
                'campaign_type' => 'tiktok',
                'status' => 'approved',
                'deadline' => now()->addDays(45),
                'max_bids' => 15,
                'is_active' => true,
            ]
        );

        $chatRoom1 = ChatRoom::findOrCreateRoom($campaign1->id, $brand1->id, $creator1->id);
        $chatRoom2 = ChatRoom::findOrCreateRoom($campaign1->id, $brand1->id, $creator2->id);
        $chatRoom3 = ChatRoom::findOrCreateRoom($campaign2->id, $brand2->id, $creator1->id);
        $chatRoom4 = ChatRoom::findOrCreateRoom($campaign2->id, $brand2->id, $creator2->id);

        Message::create([
            'chat_room_id' => $chatRoom1->id,
            'sender_id' => $brand1->id,
            'message' => 'Olá Creator Test 1, aqui é a Brand Test 1.',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom1->id,
            'sender_id' => $creator1->id,
            'message' => 'Olá Brand Test 1, recebida a mensagem!',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom2->id,
            'sender_id' => $brand1->id,
            'message' => 'Olá Creator Test 2, aqui é a Brand Test 1.',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom2->id,
            'sender_id' => $creator2->id,
            'message' => 'Olá Brand Test 1, tudo certo por aqui!',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom3->id,
            'sender_id' => $brand2->id,
            'message' => 'Olá Creator Test 1, aqui é a Brand Test 2.',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom3->id,
            'sender_id' => $creator1->id,
            'message' => 'Olá Brand Test 2, vamos testar essa conversa.',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom4->id,
            'sender_id' => $brand2->id,
            'message' => 'Olá Creator Test 2, aqui é a Brand Test 2.',
            'message_type' => 'text',
        ]);

        Message::create([
            'chat_room_id' => $chatRoom4->id,
            'sender_id' => $creator2->id,
            'message' => 'Olá Brand Test 2, teste de chat funcionando.',
            'message_type' => 'text',
        ]);

        $brandTest = User::where('email', 'brand_test@nexa.com')->first();
        $creatorTest = User::where('email', 'creator_test@nexa.com')->first();

        if ($brandTest && $creatorTest) {
            $campaignCreatorTest = Campaign::firstOrCreate(
                [
                    'brand_id' => $brandTest->id,
                    'title' => 'Campaign Creator Test',
                ],
                [
                    'description' => 'Campanha de teste para Creator Test',
                    'budget' => 1500,
                    'location' => 'São Paulo',
                    'requirements' => 'Requisitos para Creator Test',
                    'target_states' => ['SP'],
                    'category' => 'teste',
                    'campaign_type' => 'instagram',
                    'status' => 'approved',
                    'deadline' => now()->addDays(30),
                    'max_bids' => 10,
                    'is_active' => true,
                ]
            );

            $chatRoomCreatorTest = ChatRoom::findOrCreateRoom(
                $campaignCreatorTest->id,
                $brandTest->id,
                $creatorTest->id
            );

            $offer = Offer::firstOrCreate(
                [
                    'brand_id' => $brandTest->id,
                    'creator_id' => $creatorTest->id,
                    'campaign_id' => $campaignCreatorTest->id,
                    'chat_room_id' => $chatRoomCreatorTest->id,
                    'title' => 'Oferta teste sem pagamento',
                ],
                [
                    'description' => 'Oferta criada em seeder para testes, sem pagamento automático.',
                    'budget' => 1000,
                    'estimated_days' => 30,
                    'requirements' => [],
                    'status' => 'pending',
                    'is_barter' => true,
                    'barter_description' => 'Permuta de teste para Creator Test',
                    'expires_at' => now()->addDays(30),
                ]
            );

            $existingOfferMessage = Message::where('chat_room_id', $chatRoomCreatorTest->id)
                ->where('message_type', 'offer')
                ->whereNotNull('offer_data')
                ->get()
                ->first(function (Message $message) use ($offer) {
                    $data = $message->offer_data;

                    return is_array($data)
                        && isset($data['offer_id'])
                        && (int) $data['offer_id'] === (int) $offer->id;
                });

            if (! $existingOfferMessage) {
                $offerData = [
                    'offer_id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'budget' => $offer->formatted_budget,
                    'formatted_budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'status' => $offer->status,
                    'expires_at' => $offer->expires_at?->toISOString(),
                    'days_until_expiry' => $offer->days_until_expiry,
                    'is_expiring_soon' => $offer->is_expiring_soon,
                    'created_at' => $offer->created_at->toISOString(),
                    'is_barter' => $offer->is_barter,
                    'barter_description' => $offer->barter_description,
                    'can_be_accepted' => true,
                    'can_be_rejected' => true,
                    'can_be_cancelled' => true,
                    'sender' => [
                        'id' => $brandTest->id,
                        'name' => $brandTest->name,
                        'avatar_url' => $brandTest->avatar_url,
                    ],
                ];

                Message::create([
                    'chat_room_id' => $chatRoomCreatorTest->id,
                    'sender_id' => $brandTest->id,
                    'message' => 'Oferta teste criada direto no banco (permuta, sem pagamento).',
                    'message_type' => 'offer',
                    'offer_data' => $offerData,
                ]);
            }
        }

        if ($brandTest) {
            $extraCreatorsForBrandTest = [
                $creator1,
                $creator2,
                $creatorTest,
            ];

            $index = 1;

            foreach ($extraCreatorsForBrandTest as $extraCreator) {
                if (! $extraCreator) {
                    continue;
                }

                $campaignExtra = Campaign::firstOrCreate(
                    [
                        'brand_id' => $brandTest->id,
                        'title' => 'Campaign Extra ' . $index . ' for Brand Test',
                    ],
                    [
                        'description' => 'Campanha extra de teste ' . $index . ' para Brand Test',
                        'budget' => 1000 + ($index * 100),
                        'location' => 'São Paulo',
                        'requirements' => 'Requisitos extras de teste ' . $index,
                        'target_states' => ['SP'],
                        'category' => 'teste',
                        'campaign_type' => 'instagram',
                        'status' => 'approved',
                        'deadline' => now()->addDays(30 + $index),
                        'max_bids' => 10 + $index,
                        'is_active' => true,
                    ]
                );

                $chatRoomExtra = ChatRoom::findOrCreateRoom(
                    $campaignExtra->id,
                    $brandTest->id,
                    $extraCreator->id
                );

                Message::create([
                    'chat_room_id' => $chatRoomExtra->id,
                    'sender_id' => $brandTest->id,
                    'message' => 'Olá ' . $extraCreator->name . ', esta é uma conversa extra de teste ' . $index . '.',
                    'message_type' => 'text',
                ]);

                Message::create([
                    'chat_room_id' => $chatRoomExtra->id,
                    'sender_id' => $extraCreator->id,
                    'message' => 'Olá ' . $brandTest->name . ', mensagem recebida na conversa extra de teste ' . $index . '.',
                    'message_type' => 'text',
                ]);

                $index++;
            }
        }

        if ($creatorTest) {
            $extraBrandsForCreatorTest = [
                $brand1,
                $brand2,
                $brandTest,
            ];

            $index = 1;

            foreach ($extraBrandsForCreatorTest as $extraBrand) {
                if (! $extraBrand) {
                    continue;
                }

                $campaignForCreatorTest = Campaign::firstOrCreate(
                    [
                        'brand_id' => $extraBrand->id,
                        'title' => 'Creator Test Extra Campaign ' . $index,
                    ],
                    [
                        'description' => 'Campanha extra de teste ' . $index . ' para Creator Test',
                        'budget' => 1200 + ($index * 100),
                        'location' => 'São Paulo',
                        'requirements' => 'Requisitos para Creator Test extra ' . $index,
                        'target_states' => ['SP'],
                        'category' => 'teste',
                        'campaign_type' => 'instagram',
                        'status' => 'approved',
                        'deadline' => now()->addDays(20 + $index),
                        'max_bids' => 5 + $index,
                        'is_active' => true,
                    ]
                );

                $chatRoomForCreatorTest = ChatRoom::findOrCreateRoom(
                    $campaignForCreatorTest->id,
                    $extraBrand->id,
                    $creatorTest->id
                );

                Message::create([
                    'chat_room_id' => $chatRoomForCreatorTest->id,
                    'sender_id' => $extraBrand->id,
                    'message' => 'Olá Creator Test, esta é uma conversa extra de teste ' . $index . ' com ' . $extraBrand->name . '.',
                    'message_type' => 'text',
                ]);

                Message::create([
                    'chat_room_id' => $chatRoomForCreatorTest->id,
                    'sender_id' => $creatorTest->id,
                    'message' => 'Olá ' . $extraBrand->name . ', Creator Test respondendo na conversa extra ' . $index . '.',
                    'message_type' => 'text',
                ]);

                $index++;
            }
        }

        $this->command?->info('TestChatDataSeeder executed.');
    }
}
