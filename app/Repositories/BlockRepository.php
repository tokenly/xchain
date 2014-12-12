<?php

namespace App\Repositories;

use App\Models\Block;
use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* BlockRepository
*/
class BlockRepository
{

    public function create($attributes) {
        return Block::create($attributes);
    }

    public function findAll() {
        return Block::all();
    }

    public function findByHash($hash) {
        return Block::where('hash', $hash)->first();
    }

    public function findAllAsOfHeight($height) {
        return Block::where('height', '>=', $height)->orderBy('height')->get();
    }

    public function findLatestBlockHeight() {
        $block = Block::orderBy('height', 'desc')->limit(1)->first();
        if (!$block) { return null; }
        return $block['height'];
    }

    public function updateByHash($hash, $attributes) {
        return $this->update($this->findByHash($hash), $attributes);
    }

    public function update(Model $block, $attributes) {
        return $block->update($attributes);
    }

    public function deleteByHash($hash) {
        if ($block = self::findByHash($hash)) {
            return self::delete($block);
        }
        return false;
    }

    public function delete(Model $block) {
        return $block->delete();
    }

}
