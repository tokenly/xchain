<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateNotificationArchiveAddEventMonitorId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notification_archive', function (Blueprint $table) {
            $table->integer('event_monitor_id')->unsigned()->nullable();
            $table->dropIndex('notification_txid_conf_addr_user');
            $table->unique(['txid','block_hash','monitored_address_id','event_monitor_id','user_id','event_type',], 'notification_txid_conf_addr_user');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notification_archive', function (Blueprint $table) {
            $table->dropIndex('notification_txid_conf_addr_user');
            $table->unique(['txid','block_hash','monitored_address_id','event_type',], 'notification_txid_conf_addr_user');
            $table->dropColumn('event_monitor_id');
        });
    }
}
