<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddFeePerByteToSendTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('send', function (Blueprint $table) {
            $table->integer('fee_per_byte')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('send', function (Blueprint $table) {
            $table->dropColumn('fee_per_byte');
        });
    }
}
