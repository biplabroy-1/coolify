<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The domain a database is reached with through the server proxy.
     */
    public function up(): void
    {
        $tables = [
            'standalone_postgresqls',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_clickhouses',
            'standalone_keydbs',
            'standalone_dragonflies',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'domain')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('domain')->nullable()->after('public_port');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'standalone_postgresqls',
            'standalone_mysqls',
            'standalone_mariadbs',
            'standalone_redis',
            'standalone_mongodbs',
            'standalone_clickhouses',
            'standalone_keydbs',
            'standalone_dragonflies',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'domain')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('domain');
                });
            }
        }
    }
};
