<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStokFieldToRekamanStoksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('rekaman_stoks', function (Blueprint $table) {
            $table->integer('stok_awal')->nullable();
            $table->integer('stok_sisa')->nullable();
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
            $table->dropColumn('stok_awal');
            $table->dropColumn('stok_sisa');
        });
    }
}
