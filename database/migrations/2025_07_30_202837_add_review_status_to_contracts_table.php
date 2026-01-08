<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->boolean('has_brand_review')->default(false)->after('workflow_status');
            $table->boolean('has_creator_review')->default(false)->after('has_brand_review');
            $table->boolean('has_both_reviews')->default(false)->after('has_creator_review');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn(['has_brand_review', 'has_creator_review', 'has_both_reviews']);
        });
    }
};
