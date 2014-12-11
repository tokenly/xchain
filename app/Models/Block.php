<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use \Exception;

/*
* Block
*/
class Block extends Model
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'block';

    protected static $unguarded = true;

    public function setParsedBlockAttribute($parsed_block) { $this->attributes['parsed_block'] = json_encode($parsed_block); }
    public function getParsedBlockAttribute() { return json_decode($this->attributes['parsed_block'], true); }

}
