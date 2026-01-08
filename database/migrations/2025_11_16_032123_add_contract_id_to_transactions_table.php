<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (!Schema::hasColumn('transactions', 'contract_id')) {
                $table->unsignedBigInteger('contract_id')->nullable()->after('user_id');

                if ('sqlite' !== DB::getDriverName()) {
                    $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('set null');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if ('sqlite' !== DB::getDriverName()) {
                $table->dropForeign(['contract_id']);
            }
            $table->dropColumn('contract_id');
        });
    }
};
