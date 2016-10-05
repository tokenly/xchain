<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMultisigFieldsToPaymentAddressArchive extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_address_archive', function(Blueprint $table)
        {
            $table->tinyInteger('address_type')->default(1); // P2PKH
            $table->mediumText('copay_data')->nullable();
            $table->tinyInteger('copay_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_address_archive', function(Blueprint $table)
        {
            $table->dropColumn('address_type');
            $table->dropColumn('copay_data');
            $table->dropColumn('copay_status');
        });
    }
    
}
