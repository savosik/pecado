<?php

namespace App\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class RabbitMQCheck extends Check
{
    protected ?string $name = 'RabbitMQ';

    protected ?string $label = 'RabbitMQ';

    protected string $host = 'rabbitmq';

    protected int $port = 5672;

    protected int $timeoutSeconds = 5;

    public function host(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function port(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function run(): Result
    {
        $connection = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeoutSeconds);

        if (! $connection) {
            return Result::make()
                ->failed()
                ->shortSummary('Недоступен')
                ->notificationMessage("RabbitMQ ({$this->host}:{$this->port}) недоступен: {$errstr} ({$errno})");
        }

        fclose($connection);

        return Result::make()->ok()->shortSummary('Connected');
    }
}
