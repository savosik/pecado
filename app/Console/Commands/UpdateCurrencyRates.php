<?php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateCurrencyRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'currency:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update currency exchange rates from the Central Bank of Russia';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting currency rates update...');

        try {
            $response = Http::get('https://www.cbr.ru/scripts/XML_daily.asp');

            if ($response->failed()) {
                $this->error('Failed to fetch data from CBR.');
                Log::error('Currency update failed: Could not fetch data from CBR.');
                return Command::FAILURE;
            }

            $xml = simplexml_load_string($response->body());
            if ($xml === false) {
                $this->error('Failed to parse XML response.');
                Log::error('Currency update failed: Invalid XML.');
                return Command::FAILURE;
            }

            $currencies = Currency::where('is_base', false)->get();
            $updatedCount = 0;

            foreach ($currencies as $currency) {
                // Find the currency in XML by CharCode
                $rateNode = null;
                foreach ($xml->Valute as $valute) {
                    if ((string)$valute->CharCode === $currency->code) {
                        $rateNode = $valute;
                        break;
                    }
                }

                if ($rateNode) {
                    $valueStr = (string)$rateNode->Value;
                    $nominal = (float)$rateNode->Nominal;
                    
                    // Replace comma with dot for float parsing
                    $valueStr = str_replace(',', '.', $valueStr);
                    $value = (float)$valueStr;

                    if ($nominal > 0) {
                        // CBR gives rate for Nominal amount (e.g., 100 KZT = 20 RUB)
                        // We need rate for 1 unit of foreign currency in RUB: 1 KZT = 0.2 RUB
                        // But wait, our base is RUB.
                        // If 1 KZT = 0.2 RUB, then our exchange_rate should probably be 0.2 
                        // if we convert FROM KZT TO RUB by multiplying?
                        // Let's assume exchange_rate is "How many Base Units (RUB) is 1 Foreign Unit".
                        
                        $rate = $value / $nominal;

                        $currency->update(['exchange_rate' => $rate]);
                        $this->info("Updated {$currency->code}: 1 {$currency->code} = {$rate} RUB");
                        $updatedCount++;
                    }
                } else {
                    $this->warn("Rate for {$currency->code} not found in CBR data.");
                }
            }

            $this->info("Currency rates updated successfully. Updated: {$updatedCount}");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            Log::error('Currency update exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
