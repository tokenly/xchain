<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNotificationArchiveTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notification_archive', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('txid', 64)->index();
            $table->integer('confirmations');
            $table->integer('monitored_address_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned()->nullable();
            $table->tinyInteger('status')->index();
            $table->integer('attempts')->nullable();
            $table->timestamp('returned')->nullable();
            $table->text('error')->nullable();
            $table->longText('notification');
            $table->tinyInteger('event_type')->default(0);
            $table->char('block_hash', 64);
            $table->timestamp('created_at');

            $table->unique(['txid','block_hash','monitored_address_id','user_id',], 'notification_txid_conf_addr_user');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('notification_archive');
    }
}
