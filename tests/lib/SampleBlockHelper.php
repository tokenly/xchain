<?php

use App\Repositories\BlockRepository;

/**
*  SampleBlockHelper
*/
class SampleBlockHelper
{

    public function __construct(BlockRepository $block_repository) {
        $this->block_repository = $block_repository;
    }

    public function loadSampleBlock($filename) {
        $data = json_decode(file_get_contents(base_path().'/tests/fixtures/blocks/'.$filename), true);
        if ($data === null) { throw new Exception("file not found: $filename", 1); }
        return $data;
    }

    public function fillFakeBlockData($data) {
        if (!isset($data['hash'])) { $data['hash'] = str_pad($data['height'], 64, '0', STR_PAD_LEFT); }
        if (!isset($data['previousblockhash'])) { $data['previousblockhash'] = str_pad($data['height'] - 1, 64, '0', STR_PAD_LEFT); }
        if (!isset($data['time'])) { $data['time'] = time(); }
        return $data;
    }

    public function createSampleBlock($filename='default_parsed_block_01.json', $parsed_block_overrides=[]) {
        $parsed_block = $this->loadSampleBlock($filename);

        $parsed_block = array_replace_recursive($parsed_block, $parsed_block_overrides);

        $block_model = $this->block_repository->create([
            'hash'         => $parsed_block['hash'],
            'height'       => $parsed_block['height'],
            'parsed_block' => $parsed_block
        ]);
        return $block_model;
    }


}