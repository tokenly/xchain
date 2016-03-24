<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAddressTxosCache extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('address_txos_cache', function (Blueprint $table) {
            $table->char('txid', 64)->primary();
            $table->longText('transaction');
            $table->timestamp('last_update')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('address_txos_cache');
    }
}
