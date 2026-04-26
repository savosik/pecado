<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_bus_messages', function (Blueprint $table) {
            $table->index(['event', 'created_at'], 'erp_bus_messages_event_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('erp_bus_messages', function (Blueprint $table) {
            $table->dropIndex('erp_bus_messages_event_created_at_index');
        });
    }
};
