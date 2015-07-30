<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');

            $table->char('uuid', 36)->unique();

            $table->string('name', 127)->index();
            $table->tinyInteger('active');

            $table->longtext('meta')->nullable();

            $table->integer('payment_address_id')->unsigned();
            $table->foreign('payment_address_id')->references('id')->on('payment_address');

            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');

            $table->timestamps();

            $table->unique(['payment_address_id','name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('accounts');
    }
}
