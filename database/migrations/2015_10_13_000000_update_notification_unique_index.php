<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateNotificationUniqueIndex extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notification', function (Blueprint $table) {
            $table->dropIndex('notification_txid_conf_addr_user');
            
            $table->tinyInteger('event_type')->default(0);
            $table->unique(['txid','block_id','monitored_address_id','event_type',], 'notification_txid_blk_addr_event');
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
            $table->dropIndex('notification_txid_blk_addr_event');

            $table->unique(['txid','block_id','monitored_address_id','user_id',], 'notification_txid_conf_addr_user');
        });
    }
}
