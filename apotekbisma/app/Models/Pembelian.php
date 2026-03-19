<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pembelian extends Model
{
    use HasFactory;

    public const DEFAULT_PPN_PERCENT = 0.0;

    protected $table = 'pembelian';
    protected $primaryKey = 'id_pembelian';
    protected $guarded = [];
    protected $dates = ['waktu', 'waktu_datang'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'id_supplier', 'id_supplier');
    }

    public function detail()
    {
        return $this->hasMany(PembelianDetail::class, 'id_pembelian', 'id_pembelian');
    }

    public static function calculateFinancialSummary($totalHarga, $diskonPersen = 0, $ppnPersen = self::DEFAULT_PPN_PERCENT): array
    {
        $totalHarga = max(0.0, (float) $totalHarga);
        $diskonPersen = max(0.0, (float) $diskonPersen);
        $ppnPersen = max(0.0, (float) $ppnPersen);

        $roundedTotal = (int) round($totalHarga);
        $diskonNominal = (int) round($roundedTotal * ($diskonPersen / 100));
        $dpp = max(0, $roundedTotal - $diskonNominal);
        $ppnNominal = 0;
        $grandTotal = $dpp;

        return [
            'total_harga' => $roundedTotal,
            'diskon_persen' => $diskonPersen,
            'diskon_nominal' => $diskonNominal,
            'dpp' => $dpp,
            'ppn_persen' => $ppnPersen,
            'ppn_nominal' => $ppnNominal,
            'grand_total' => $grandTotal,
        ];
    }
}
