<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('company_name')->nullable();
            $table->boolean('student_verified')->default(false);
            $table->timestamp('student_expires_at')->nullable();
            $table->string('gender')->nullable();
            $table->string('state')->nullable();
            $table->string('language')->nullable();
            $table->string('premium_status')->nullable()->default('free');
            $table->timestamp('premium_expires_at')->nullable();
            $table->timestamp('free_trial_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'role',
                'whatsapp',
                'avatar_url',
                'bio',
                'company_name',
                'student_verified',
                'student_expires_at',
                'gender',
                'state',
                'language',
                'premium_status',
                'premium_expires_at',
                'free_trial_expires_at',
            ]);
        });
    }
};
