<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            if (!Schema::hasColumn('subscription_plans', 'code')) {
                $table->string('code')->nullable()->after('name');
            }
        });

        DB::table('subscription_plans')->where('name', 'Plano Mensal')->update(['code' => 'nexa_mensal']);
        DB::table('subscription_plans')->where('name', 'Plano Semestral')->update(['code' => 'nexa_semestral']);
        DB::table('subscription_plans')->where('name', 'Plano Anual')->update(['code' => 'nexa_anual']);

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            if (Schema::hasColumn('subscription_plans', 'code')) {
                $table->dropUnique(['code']);
                $table->dropColumn('code');
            }
        });
    }
};
