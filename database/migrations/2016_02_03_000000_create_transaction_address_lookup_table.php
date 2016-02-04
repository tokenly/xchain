<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransactionAddressLookupTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_address_lookup', function (Blueprint $table) {
            $table->integer('transaction_id')->unsigned();
            $table->foreign('transaction_id')
                ->references('id')->on('transaction')
                ->onDelete('cascade');

            $table->char('address', 35)->index();
            $table->tinyInteger('direction');

            $table->unique(['transaction_id','address','direction',], 'transaction_id_address_direction');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('transaction_address_lookup');
    }
}
