<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bedrock_models') && ! Schema::hasColumn('bedrock_models', 'input_modalities')) {
            Schema::table('bedrock_models', function (Blueprint $table) {
                $table->json('input_modalities')->nullable()->after('capabilities');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bedrock_models') && Schema::hasColumn('bedrock_models', 'input_modalities')) {
            Schema::table('bedrock_models', function (Blueprint $table) {
                $table->dropColumn('input_modalities');
            });
        }
    }
};
