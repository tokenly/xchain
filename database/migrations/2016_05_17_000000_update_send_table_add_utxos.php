<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSendTableAddUtxos extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('send', function(Blueprint $table)
        {
            $table->mediumText('utxos')->default('');
            $table->boolean('unsigned')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('send', function(Blueprint $table)
        {
            $table->dropColumn('unsigned');
            $table->dropColumn('utxos');
        });
    }
    
}
