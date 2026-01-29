<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessErpUserUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!isset($this->payload['user']['id']) || !isset($this->payload['erp_id'])) {
            return;
        }

        $user = \App\Models\User::find($this->payload['user']['id']);

        if ($user) {
            $user->update([
                'erp_id' => $this->payload['erp_id'],
                'status' => $this->payload['status'] ?? $user->status,
            ]);
        }
    }
}
