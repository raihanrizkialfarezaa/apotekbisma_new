<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Produk extends Model
{
    use HasFactory;

    protected $table = 'produk';
    protected $primaryKey = 'id_produk';
    protected $guarded = [];

    /**
     * Mutator untuk stok - warning jika akan negatif tapi tidak force ke 0
     */
    public function setStokAttribute($value)
    {
        $intValue = intval($value);
        if ($intValue < 0) {
            Log::warning("Attempting to set negative stock for product ID {$this->id_produk}: {$intValue}");
        }
        $this->attributes['stok'] = $intValue;
    }

    /**
     * Accessor untuk stok
     */
    public function getStokAttribute($value)
    {
        return intval($value);
    }

    public function kategori()
    {
        return $this->hasOne(Kategori::class, 'id_kategori', 'id_kategori');
    }

    public function rekamanStoks()
    {
        return $this->hasMany(RekamanStok::class, 'id_produk', 'id_produk');
    }

    /**
     * Method untuk mengurangi stok dengan validasi
     */
    public function reduceStock($quantity)
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive');
        }

        if ($this->stok < $quantity) {
            throw new \Exception("Stok tidak mencukupi. Stok tersedia: {$this->stok}, diminta: {$quantity}");
        }

        $this->stok = $this->stok - $quantity;
        return $this;
    }

    /**
     * Method untuk menambah stok
     */
    public function addStock($quantity)
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive');
        }

        $this->stok = $this->stok + $quantity;
        return $this;
    }
}
