<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSendTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('send', function (Blueprint $table) {
            $table->increments('id');

            $table->char('uuid', 36)->unique();
            $table->char('txid', 64)->unique();

            $table->integer('payment_address_id')->unsigned();
            $table->foreign('payment_address_id')->references('id')->on('payment_address');

            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');

            $table->timestamp('sent')->nullable();

            $table->char('destination', 35);
            $table->bigInteger('quantity_sat')->unsigned();
            $table->bigInteger('fee_sat')->unsigned()->nullable();
            $table->bigInteger('multisig_dust_size_sat')->unsigned()->nullable();
            $table->text('asset');

            $table->text('is_sweep')->boolean()->default(false);

            $table->char('block_confirmed_hash', 64)->nullable();
            $table->integer('block_confirmed_height')->nullable();

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
        Schema::drop('send');
    }
}
