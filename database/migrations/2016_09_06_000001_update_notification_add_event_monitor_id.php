<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateNotificationAddEventMonitorId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notification', function (Blueprint $table) {
            $table->integer('event_monitor_id')->unsigned()->nullable();
            $table->foreign('event_monitor_id')->references('id')->on('event_monitors');
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
            $table->dropForeign('notification_event_monitor_id_foreign');
            $table->dropColumn('event_monitor_id');
        });
    }
}
