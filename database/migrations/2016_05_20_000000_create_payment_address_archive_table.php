<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePaymentAddressArchiveTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('payment_address_archive', function(Blueprint $table)
		{
			$table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('address', 35)->index();
            $table->char('private_key_token', 40);
            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');
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
		Schema::drop('payment_address_archive');
	}

}
