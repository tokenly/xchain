<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddRequestIdToSend extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('send', function (Blueprint $table) {
            $table->char('request_id', 36)->nullable()->unique();
            $table->string('txid', 64)->nullable()->change();
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
            $table->dropColumn('request_id');
        });
    }
}
