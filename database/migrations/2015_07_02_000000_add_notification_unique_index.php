<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddNotificationUniqueIndex extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notification', function (Blueprint $table) {
            $table->integer('block_id')->unsigned()->nullable();
            $table->foreign('block_id')->references('id')->on('block');

            $table->unique(['txid','block_id','monitored_address_id','user_id',], 'notification_txid_conf_addr_user');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notification', function (Blueprint $table) {
            $table->dropIndex('notification_txid_conf_addr_user');
            $table->dropForeign('notification_block_id_foreign');
            $table->dropColumn('block_id');
        });
    }
}
