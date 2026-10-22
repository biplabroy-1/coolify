<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The domain a service database is reached with through the database proxy.
     */
    public function up(): void
    {
        if (Schema::hasTable('service_databases') && ! Schema::hasColumn('service_databases', 'domain')) {
            Schema::table('service_databases', function (Blueprint $table) {
                $table->string('domain')->nullable()->after('public_port');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_databases') && Schema::hasColumn('service_databases', 'domain')) {
            Schema::table('service_databases', function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }
    }
};
