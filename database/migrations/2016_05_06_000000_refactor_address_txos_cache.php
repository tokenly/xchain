<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RefactorAddressTxosCache extends Migration
{

        // -----------------------------------
        // database format:
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
        //   
        // -----------------------------------


    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // this migration requires a complete clear and rebuild
        Schema::drop('address_txos_cache');
        
        Schema::create('address_txos_cache', function (Blueprint $table) {
            $table->increments('id');

            $table->char('address_reference', 35)->index();
            $table->char('txid', 64);
            $table->integer('n')->unsigned();
            $table->integer('confirmations')->unsigned()->default(0);
            $table->longText('script')->nullable();

            $table->char('destination_address', 35)->index()->nullable();
            $table->integer('destination_value')->unsigned()->nullable();

            $table->boolean('spent')->default(false);
            $table->integer('spent_confirmations')->unsigned()->nullable();


            $table->timestamp('last_update');

            $table->unique(['address_reference','txid','n',]);
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

        // restore the old table
        Schema::create('address_txos_cache', function (Blueprint $table) {
            $table->char('txid', 64)->primary();
            $table->longText('transaction');
            $table->timestamp('last_update')->index();
        });
    }
}
