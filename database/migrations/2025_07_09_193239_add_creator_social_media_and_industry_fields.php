<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->string('instagram_handle')->nullable()->after('creator_type');
            $table->string('tiktok_handle')->nullable()->after('instagram_handle');
            $table->string('youtube_channel')->nullable()->after('tiktok_handle');
            $table->string('facebook_page')->nullable()->after('youtube_channel');
            $table->string('twitter_handle')->nullable()->after('facebook_page');

            $table->string('industry')->nullable()->after('twitter_handle');
        });

        DB::statement("UPDATE users SET birth_date = '1990-01-01' WHERE birth_date IS NULL");
        DB::statement("UPDATE users SET gender = 'other' WHERE gender IS NULL");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE users ALTER COLUMN birth_date SET NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN gender SET NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'instagram_handle',
                'tiktok_handle',
                'youtube_channel',
                'facebook_page',
                'twitter_handle',
                'industry',
            ]);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE users ALTER COLUMN birth_date DROP NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN gender DROP NOT NULL');
        }
    }
};
