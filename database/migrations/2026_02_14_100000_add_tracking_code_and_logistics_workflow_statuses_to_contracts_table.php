<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $baseStatuses = [
        'active',
        'waiting_review',
        'payment_pending',
        'payment_failed',
        'payment_available',
        'payment_withdrawn',
        'terminated',
    ];

    /**
     * @var array<int, string>
     */
    private array $logisticsStatuses = [
        'alignment_preparation',
        'material_sent',
        'product_sent',
        'product_received',
        'production_started',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'tracking_code')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->string('tracking_code', 120)->nullable()->after('workflow_status');
            });
        }

        $this->applyWorkflowStatusConstraint(array_merge($this->baseStatuses, $this->logisticsStatuses));
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'tracking_code')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->dropColumn('tracking_code');
            });
        }

        DB::table('contracts')
            ->whereIn('workflow_status', $this->logisticsStatuses)
            ->update(['workflow_status' => 'active']);

        $this->applyWorkflowStatusConstraint($this->baseStatuses);
    }

    /**
     * @param array<int, string> $allowedStatuses
     */
    private function applyWorkflowStatusConstraint(array $allowedStatuses): void
    {
        $driver = DB::connection()->getDriverName();

        if ('sqlite' === $driver) {
            return;
        }

        $quotedStatuses = implode(
            ',',
            array_map(
                static fn (string $status): string => "'" . str_replace("'", "''", $status) . "'",
                $allowedStatuses
            )
        );

        if ('mysql' === $driver) {
            DB::statement("ALTER TABLE contracts MODIFY COLUMN workflow_status ENUM($quotedStatuses) NOT NULL DEFAULT 'active'");

            return;
        }

        if ('pgsql' === $driver) {
            DB::statement('ALTER TABLE contracts ALTER COLUMN workflow_status TYPE VARCHAR(64) USING workflow_status::text');
            DB::statement("ALTER TABLE contracts ALTER COLUMN workflow_status SET DEFAULT 'active'");
            DB::statement(<<<'SQL'
DO $$
DECLARE constraint_record record;
BEGIN
    FOR constraint_record IN
        SELECT conname
        FROM pg_constraint
        WHERE conrelid = 'contracts'::regclass
          AND contype = 'c'
          AND pg_get_constraintdef(oid) ILIKE '%workflow_status%'
    LOOP
        EXECUTE format('ALTER TABLE contracts DROP CONSTRAINT %I', constraint_record.conname);
    END LOOP;
END $$;
SQL);
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_workflow_status_check CHECK (workflow_status IN ($quotedStatuses))");
        }
    }
};
