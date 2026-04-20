<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE kanban_tasks MODIFY COLUMN status ENUM('backlog','todo','in_progress','testing','done','reopen') NOT NULL DEFAULT 'todo'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE kanban_tasks MODIFY COLUMN status ENUM('todo','in_progress','testing','done','reopen') NOT NULL DEFAULT 'todo'");
        }
    }
};
