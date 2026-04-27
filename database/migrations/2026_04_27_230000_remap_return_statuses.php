<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('returns')->where('status', 'approved')->update(['status' => 'confirmed']);
            DB::table('returns')->where('status', 'rejected')->update(['status' => 'cancelled']);
            DB::table('returns')->where('status', 'completed')->update(['status' => 'closed']);

            DB::table('returns')
                ->whereNotIn('status', ['pending', 'confirmed', 'ready_to_ship', 'closed', 'cancelled'])
                ->update(['status' => 'pending']);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('returns')->where('status', 'confirmed')->update(['status' => 'approved']);
            DB::table('returns')->where('status', 'cancelled')->update(['status' => 'rejected']);
            DB::table('returns')->where('status', 'closed')->update(['status' => 'completed']);
            DB::table('returns')->where('status', 'ready_to_ship')->update(['status' => 'approved']);
        });
    }
};
