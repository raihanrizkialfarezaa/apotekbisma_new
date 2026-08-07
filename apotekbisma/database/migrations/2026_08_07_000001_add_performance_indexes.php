<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPerformanceIndexes extends Migration
{
    public function up()
    {
        Schema::table('penjualan', function (Blueprint $table) {
            $table->index('created_at', 'idx_penjualan_created_at');
            $table->index('waktu', 'idx_penjualan_waktu');
            $table->index('id_member', 'idx_penjualan_id_member');
        });

        Schema::table('penjualan_detail', function (Blueprint $table) {
            $table->index('id_penjualan', 'idx_penjualan_detail_id_penjualan');
            $table->index('id_produk', 'idx_penjualan_detail_id_produk');
        });

        Schema::table('pembelian', function (Blueprint $table) {
            $table->index('created_at', 'idx_pembelian_created_at');
            $table->index('waktu', 'idx_pembelian_waktu');
            $table->index('waktu_datang', 'idx_pembelian_waktu_datang');
            $table->index('id_supplier', 'idx_pembelian_id_supplier');
        });

        Schema::table('pembelian_detail', function (Blueprint $table) {
            $table->index('id_pembelian', 'idx_pembelian_detail_id_pembelian');
            $table->index('id_produk', 'idx_pembelian_detail_id_produk');
        });

        Schema::table('rekaman_stoks', function (Blueprint $table) {
            $table->index('id_produk', 'idx_rekaman_stoks_id_produk');
            $table->index('waktu', 'idx_rekaman_stoks_waktu');
        });

        Schema::table('produk', function (Blueprint $table) {
            $table->index('stok', 'idx_produk_stok');
        });
    }

    public function down()
    {
        Schema::table('penjualan', function (Blueprint $table) {
            $table->dropIndex('idx_penjualan_created_at');
            $table->dropIndex('idx_penjualan_waktu');
            $table->dropIndex('idx_penjualan_id_member');
        });

        Schema::table('penjualan_detail', function (Blueprint $table) {
            $table->dropIndex('idx_penjualan_detail_id_penjualan');
            $table->dropIndex('idx_penjualan_detail_id_produk');
        });

        Schema::table('pembelian', function (Blueprint $table) {
            $table->dropIndex('idx_pembelian_created_at');
            $table->dropIndex('idx_pembelian_waktu');
            $table->dropIndex('idx_pembelian_waktu_datang');
            $table->dropIndex('idx_pembelian_id_supplier');
        });

        Schema::table('pembelian_detail', function (Blueprint $table) {
            $table->dropIndex('idx_pembelian_detail_id_pembelian');
            $table->dropIndex('idx_pembelian_detail_id_produk');
        });

        Schema::table('rekaman_stoks', function (Blueprint $table) {
            $table->dropIndex('idx_rekaman_stoks_id_produk');
            $table->dropIndex('idx_rekaman_stoks_waktu');
        });

        Schema::table('produk', function (Blueprint $table) {
            $table->dropIndex('idx_produk_stok');
        });
    }
}
