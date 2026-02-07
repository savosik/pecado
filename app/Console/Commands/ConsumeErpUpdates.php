<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConsumeErpUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:consume {--tries=3 : Number of times to attempt a job} {--sleep=3 : Seconds to sleep when no job is available}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume incoming ERP updates from RabbitMQ via Laravel Queue worker';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting ERP consumer via Laravel Queue worker...');
        $this->info('Listening on queue: erp_incoming (connection: rabbitmq-erp-incoming)');
        $this->info('Press CTRL+C to stop');

        return $this->call('queue:work', [
            'connection' => 'rabbitmq-erp-incoming',
            '--queue' => 'erp_incoming',
            '--tries' => $this->option('tries'),
            '--sleep' => $this->option('sleep'),
            '--max-time' => 3600,
        ]);
    }
}
