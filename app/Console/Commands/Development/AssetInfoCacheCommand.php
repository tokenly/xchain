<?php

namespace App\Console\Commands\Development;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Tokenly\LaravelEventLog\Facade\EventLog;

class AssetInfoCacheCommand extends Command {

    use DispatchesJobs;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-xchain:asset-info-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show or clear the asset info cache';



    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['asset', InputArgument::REQUIRED, 'Asset name',],
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
            ['reset', 'r', InputOption::VALUE_NONE, 'Clear and rebuild the asset info cache.'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $asset = $this->argument('asset');
        $reset = !!$this->option('reset');
        $asset_info_cache = app('Tokenly\CounterpartyAssetInfoCache\Cache');

        if ($reset) {
            $this->info("rebuilding $asset");
            $asset_info_cache->forget($asset);
            $asset_info_cache->get($asset);
        }

        $this->info("Loading $asset from cache");
        $info = $asset_info_cache->getFromCache($asset);

        $this->line(json_encode($info, 192));
        $this->comment('done');
    }


}
