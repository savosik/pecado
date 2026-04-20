<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_tasks', function (Blueprint $table) {
            $table->string('scope')->nullable()->after('user_name');
            $table->string('type')->nullable()->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_tasks', function (Blueprint $table) {
            $table->dropColumn(['scope', 'type']);
        });
    }
};
