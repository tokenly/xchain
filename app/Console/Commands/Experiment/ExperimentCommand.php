<?php

namespace App\Console\Commands\Experiment;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ExperimentCommand extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'dev-exp:expirement';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Used for experiments';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->comment("Begin experiment");

        // run your experiment here
        $route = new \Illuminate\Routing\Route(['GET'], '/foo/{bar}', []);
        $request = \Illuminate\Http\Request::create('https://foo.local/foo/bar', 'GET');
        echo "matches: ".json_encode($route->matches($request), 192)."\n";


        // $result = app('Nbobtc\Bitcoind\Bitcoind')->getrawtransaction('d0010d7ddb1662e381520d29177ea83f81f87428879b57735a894cad8dcae2a2', true);
        // echo "\$result: ".json_encode($result, 192)."\n";

        // $result = app('Nbobtc\Bitcoind\Bitcoind')->getblock('00000000000000000d45b7b575aada5c6e7f45d33455683d9e37292fa916b27c', true);
        // echo "\$result: ".json_encode($result, 192)."\n";

        // $index = app('Nbobtc\Bitcoind\Bitcoind')->getblockcount();
        // $hash = app('Nbobtc\Bitcoind\Bitcoind')->getblockhash($index);
        // echo "\$index: ".json_encode($index, 192)."\n";
        // echo "\$hash: ".json_encode($hash, 192)."\n";

        // $result = app('App\Handlers\XChain\Network\Bitcoin\EnhancedBitcoindTransactionBuilder')->buildTransactionData('1f79eda7fecb98d518d9483c7bd8a49d99afa2338bd6bd0fbfb9a3f8f7d61483');
        // echo "\$result: ".json_encode($result, 192)."\n";

        $this->comment("End experiment");
    }

}
