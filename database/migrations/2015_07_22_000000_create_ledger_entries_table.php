<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLedgerEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();

            $table->integer('payment_address_id')->unsigned()->index();
            $table->foreign('payment_address_id')->references('id')->on('payment_address');

            $table->integer('type')->unsigned();

            $table->integer('account_id')->unsigned()->index();
            $table->foreign('account_id')->references('id')->on('accounts');

            $table->char('txid', 64)->nullable()->index();

            $table->integer('api_call_id')->unsigned()->index()->nullable();
            $table->foreign('api_call_id')->references('id')->on('api_calls');

            $table->bigInteger('amount')->default(0);  // 0.0001
            $table->string('asset');

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
        Schema::drop('ledger_entries');
    }
}
