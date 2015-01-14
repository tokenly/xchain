<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransactionTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction', function (Blueprint $table) {
            $table->increments('id');
            $table->char('txid', 64)->index();
            $table->boolean('is_mempool')->index();
            $table->boolean('is_xcp')->index();
            $table->char('block_confirmed_hash', 64)->index()->nullable();
            $table->integer('block_seq')->index()->nullable();
            $table->longText('parsed_tx');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('transaction');
    }
}
