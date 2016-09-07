<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEventMonitorTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('event_monitors', function(Blueprint $table)
		{
			$table->increments('id');
            $table->char('uuid', 36)->unique();

            $table->text('webhook_endpoint');
            $table->tinyInteger('monitor_type_int')->unsigned();

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
		Schema::drop('event_monitors');
	}

}
