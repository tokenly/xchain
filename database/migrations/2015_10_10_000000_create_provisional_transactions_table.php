<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProvisionalTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provisional_transactions', function (Blueprint $table) {
            $table->increments('id');

            $table->char('txid', 64)->unique();
            $table->integer('transaction_id')->unsigned();
            $table->foreign('transaction_id')->references('id')->on('transaction');

            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('provisional_transactions');
    }
}
