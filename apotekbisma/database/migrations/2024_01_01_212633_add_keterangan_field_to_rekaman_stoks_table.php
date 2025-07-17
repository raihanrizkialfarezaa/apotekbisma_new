<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddKeteranganFieldToRekamanStoksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('rekaman_stoks', function (Blueprint $table) {
            $table->integer('id_penjualan')->nullable();
            $table->integer('id_pembelian')->nullable();
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
            $table->dropColumn('id_penjualan');
            $table->dropColumn('id_pembelian');
        });
    }
}
