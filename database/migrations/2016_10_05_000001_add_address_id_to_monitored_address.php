<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAddressIDToMonitoredAddress extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('monitored_address', function(Blueprint $table)
        {
            $table->integer('payment_address_id')->unsigned()->nullable();
            $table->foreign('payment_address_id')->references('id')->on('payment_address');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('monitored_address', function(Blueprint $table)
        {
            $table->dropForeign('monitored_address_payment_address_id_foreign');
            $table->dropColumn('payment_address_id');
        });
    }
    
}
