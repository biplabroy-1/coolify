<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The port the database proxy publishes for the database. A database that shares the port of
     * its type has no port of its own.
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
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'proxy_port')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedInteger('proxy_port')->nullable()->after('domain');
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
            'service_databases',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'proxy_port')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('proxy_port');
                });
            }
        }
    }
};
