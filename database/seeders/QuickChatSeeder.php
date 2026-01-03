<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class QuickChatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Find or create the Creator (user's account)
        $creator = User::where('email', 'creator.teste@nexacreators.com.br')->first();
        
        if (!$creator) {
            $this->command->info('Creator creator.teste@nexacreators.com.br not found. Creating it...');
            $creator = User::create([
                'name' => 'Creator Teste',
                'email' => 'creator.teste@nexacreators.com.br',
                'password' => Hash::make('password'),
                'role' => 'creator',
                'email_verified_at' => now(),
            ]);
        }

        // 2. Find or create a Brand to talk to
        $brandEmail = 'brand.test@nexacreators.com.br';
        $brand = User::where('email', $brandEmail)->first();
        
        if (!$brand) {
            $this->command->info("Brand $brandEmail not found. Creating it...");
            $brand = User::create([
                'name' => 'Nexa Brand Test',
                'email' => $brandEmail,
                'password' => Hash::make('password'),
                'role' => 'brand',
                'email_verified_at' => now(),
            ]);
        }

        // 3. Create a Campaign for context
        $campaign = Campaign::firstOrCreate(
            [
                'brand_id' => $brand->id,
                'title' => 'Campanha de Teste Rápido',
            ],
            [
                'description' => 'Uma campanha criada para testar o sistema de chat rapidamente.',
                'budget' => 500.00,
                'location' => 'São Paulo, SP',
                'requirements' => 'Apenas para fins de teste.',
                'status' => 'approved',
                'is_active' => true,
                'deadline' => now()->addDays(30),
            ]
        );

        // 4. Create the Chat Room
        $this->command->info('Creating Chat Room...');
        $chatRoom = ChatRoom::findOrCreateRoom($campaign->id, $brand->id, $creator->id);

        // 5. Add some messages
        $messages = [
            ['sender' => $brand, 'text' => 'Olá! Vi seu perfil e gostei muito.'],
            ['sender' => $creator, 'text' => 'Muito obrigado! Estava aguardando seu contato.'],
            ['sender' => $brand, 'text' => 'Você teria disponibilidade para entregar em 15 dias?'],
            ['sender' => $creator, 'text' => 'Sim, perfeitamente.'],
        ];

        foreach ($messages as $msg) {
            Message::create([
                'chat_room_id' => $chatRoom->id,
                'sender_id' => $msg['sender']->id,
                'message' => $msg['text'],
                'message_type' => 'text',
            ]);
        }

        $this->command->info('Test chat data created successfully!');
        $this->command->info("Chat ID: {$chatRoom->id}");
        $this->command->info("Brand: {$brand->email}");
        $this->command->info("Creator: {$creator->email}");
    }
}
