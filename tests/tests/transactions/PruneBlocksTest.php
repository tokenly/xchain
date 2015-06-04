<?php

use App\Commands\PruneBlocks;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Log;
use \PHPUnit_Framework_Assert as PHPUnit;

class PruneBlocksTest extends TestCase {

    protected $useDatabase = true;

    use DispatchesCommands;

    public function testPruneAllBlocks() {
        $block_helper = app('SampleBlockHelper');
        $created_blocks = [];
        for ($i=0; $i < 5; $i++) { 
            $created_blocks[] = $block_helper->createSampleBlock('default_parsed_block_01.json', ['hash' => str_repeat('0', 63).($i+1), 'height' => 10001+$i]);
        }

        // prune all
        $this->dispatch(new PruneBlocks(0));

        // check that all transactions were erased
        $block_repository = app('App\Repositories\BlockRepository');
        foreach($created_blocks as $created_block) {
            $loaded_block = $block_repository->findByHash($created_block['hash']);
            PHPUnit::assertNull($loaded_block);
        }
    }

    public function testPruneOldBlocks() {
        $block_helper = app('SampleBlockHelper');
        $created_blocks = [];
        for ($i=0; $i < 5; $i++) { 
            $created_blocks[] = $block_helper->createSampleBlock('default_parsed_block_01.json', ['hash' => str_repeat('0', 63).($i+1), 'height' => 10001+$i]);
        }

        // prune all
        $this->dispatch(new PruneBlocks(2));

        // check that all transactions were erased
        $block_repository = app('App\Repositories\BlockRepository');
        foreach($created_blocks as $offset => $created_block) {
            $loaded_block = $block_repository->findByHash($created_block['hash']);
            if ($offset < 2) {
                PHPUnit::assertNull($loaded_block, "found unexpected block: ".($loaded_block ? json_encode($loaded_block->toArray(), 192) : 'null'));
            } else {
                PHPUnit::assertNotNull($loaded_block, "missing block $offset");
                PHPUnit::assertEquals($created_block->toArray(), $loaded_block->toArray());
            }
        }
    }


}
