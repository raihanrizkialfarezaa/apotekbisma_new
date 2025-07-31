<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddKeteranganColumnToRekamanStoksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('rekaman_stoks', function (Blueprint $table) {
            $table->text('keterangan')->nullable()->after('stok_sisa');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('rekaman_stoks', function (Blueprint $table) {
            $table->dropColumn('keterangan');
        });
    }
}
