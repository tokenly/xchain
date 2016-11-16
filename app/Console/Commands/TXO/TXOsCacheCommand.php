<?php

namespace App\Console\Commands\TXO;

use App\Models\TXO;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\CurrencyLib\CurrencyUtil;

class TXOsCacheCommand extends Command {

    use DispatchesJobs;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'xchaintxo:txo-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Shows the TXO cache';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['address', InputArgument::REQUIRED, 'Bitcoin Address'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['spent', 's', InputOption::VALUE_NONE, 'Include spent TXOs'],
            ['clear-cache', null, InputOption::VALUE_NONE, 'Clear TXOs cache'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $address     = $this->input->getArgument('address');
        $show_spent  = !!$this->input->getOption('spent');
        $clear_cache = !!$this->input->getOption('clear-cache');

        if ($clear_cache) {
            $this->comment("Clearing UTXO cache for address $address");
            $result = DB::table('address_txos_cache')
                ->where('address_reference', '=', $address)
                ->delete();
            $this->comment("cleared: ".json_encode($result, 192));
        }

        $query = DB::table('address_txos_cache')
            ->where('address_reference', '=', $address)
            ->where('destination_address', '=', $address);

        if (!$show_spent) {
            $query->where('spent', '=', 0);
        }

        // 
        //   address_reference
        //   txid
        //   n
        //   confirmations
        //   script
        //   destination_address
        //   destination_value
        //   
        //   spent
        //   spent_confirmations
        //   
        //   last_update

        // build a table
        $bool = function($val) { return $val ? '<info>true</info>' : '<comment>false</comment>'; };
        $headers = ['txid', 'n', 'confirmations', 'value', 'destination', 'spent', 'spent confs', 'last update'];
        $rows = [];
        foreach ($query->get() as $utxo_cache_entry_obj) {
            $utxo_cache_entry = json_decode(json_encode($utxo_cache_entry_obj), true);
            $row = [
                $utxo_cache_entry['txid'],
                $utxo_cache_entry['n'],
                $utxo_cache_entry['confirmations'],
                CurrencyUtil::satoshisToFormattedString($utxo_cache_entry['destination_value']),
                $utxo_cache_entry['destination_address'],
                $bool($utxo_cache_entry['spent']),
                $utxo_cache_entry['spent_confirmations'],
                Carbon::parse($utxo_cache_entry['last_update'])
                    ->setTimezone('America/Chicago')->format("Y-m-d h:i:s A"),
            ];
            $rows[] = $row;
        }
        $this->table($headers, $rows);

        $this->info('done');

    }

}
