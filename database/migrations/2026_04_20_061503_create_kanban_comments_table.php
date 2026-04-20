<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kanban_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('kanban_comments')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_comments');
    }
};
