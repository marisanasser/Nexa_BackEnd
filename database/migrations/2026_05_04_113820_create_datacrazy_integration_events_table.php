<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('datacrazy_integration_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('event_type')->default('checkout_requested');
            $table->string('external_id')->nullable();
            $table->string('business_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('plan_code')->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('status')->default('received')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['external_id', 'created_at']);
            $table->index(['business_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datacrazy_integration_events');
    }
};
