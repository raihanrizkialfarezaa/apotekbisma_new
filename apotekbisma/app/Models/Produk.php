<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produk extends Model
{
    use HasFactory;

    protected $table = 'produk';
    protected $primaryKey = 'id_produk';
    protected $guarded = [];

    /**
     * Mutator untuk memastikan stok tidak pernah negatif
     */
    public function setStokAttribute($value)
    {
        // Pastikan stok tidak pernah kurang dari 0
        $this->attributes['stok'] = max(0, intval($value));
    }

    /**
     * Accessor untuk stok dengan normalisasi otomatis
     */
    public function getStokAttribute($value)
    {
        return max(0, intval($value));
    }

    public function kategori()
    {
        return $this->hasOne(Kategori::class, 'id_kategori', 'id_kategori');
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
