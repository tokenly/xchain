<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTxosTable extends Migration {

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('txos', function(Blueprint $table)
        {
            $table->increments('id');

            $table->char('txid', 64)->index();
            $table->integer('n')->unsigned();
            $table->integer('amount')->unsigned()->default(0);

            $table->integer('type')->unsigned()->default(1);
            $table->boolean('spent')->default(false);

            $table->integer('payment_address_id')->unsigned();
            $table->foreign('payment_address_id')->references('id')->on('payment_address');

            $table->integer('account_id')->unsigned();
            $table->foreign('account_id')->references('id')->on('accounts');

            $table->timestamps();

            $table->unique(['txid','n',], 'txid_n');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('txos');
    }

}
