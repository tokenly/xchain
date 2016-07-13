<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class UpdateLedgerEntriesMigrateAmountToDecimal extends Migration {

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // step 1
        Schema::table('ledger_entries', function(Blueprint $table)
        {
            $table->decimal('amount_dec', 20, 8)->default(0);
        });

        // step 2
        DB::transaction(function() {
            DB::table('ledger_entries')->update(['amount_dec' => DB::raw('amount / 100000000')]);
        });

        // step 3
        Schema::table('ledger_entries', function(Blueprint $table)
        {
            $table->dropColumn('amount');
        });

        // step 4
        Schema::table('ledger_entries', function(Blueprint $table)
        {
            $table->renameColumn('amount_dec', 'amount');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // step 1
        Schema::table('ledger_entries', function(Blueprint $table)
        {
            $table->bigInteger('amount_int')->default(0);
        });

        // step 2
        DB::transaction(function() {
            DB::table('ledger_entries')->update(['amount_int' => DB::raw('amount * 100000000')]);
        });

        // step 3
        Schema::table('ledger_entries', function(Blueprint $table)
        {
            $table->dropColumn('amount');
        });

        // step 4
        Schema::table('ledger_entries', function(Blueprint $table)
        {
            $table->renameColumn('amount_int', 'amount');
        });
    }

}
