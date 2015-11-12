<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSendsAddMultisend extends Migration {

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('send', function(Blueprint $table)
        {
            $table->mediumText('destinations')->nullable();
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
            $table->dropColumn('destinations');
        });
    }

}
