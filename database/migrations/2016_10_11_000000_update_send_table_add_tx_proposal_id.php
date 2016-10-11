<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSendTableAddTxProposalID extends Migration
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
            $table->char('tx_proposal_id', 36)->unique()->nullable();
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
            $table->dropColumn('tx_proposal_id');
        });
    }
    
}
