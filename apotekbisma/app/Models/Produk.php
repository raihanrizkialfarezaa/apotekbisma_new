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
     * Boot method untuk event monitoring
     */
    protected static function boot()
    {
        parent::boot();
        
        static::updating(function ($product) {
            if ($product->isDirty('stok')) {
                $oldStock = $product->getOriginal('stok');
                $newStock = $product->stok;
                
                if (abs($newStock - $oldStock) > 100) {
                    Log::warning("Large stock change detected", [
                        'product_id' => $product->id_produk,
                        'product_name' => $product->nama_produk,
                        'old_stock' => $oldStock,
                        'new_stock' => $newStock,
                        'difference' => $newStock - $oldStock,
                        'timestamp' => now()
                    ]);
                }
            }
        });
    }

    /**
     * Mutator untuk stok - dengan validasi ketat
     */
    public function setStokAttribute($value)
    {
        $intValue = max(0, intval($value)); // Pastikan tidak negatif
        if ($intValue != intval($value) && intval($value) < 0) {
            Log::warning("Prevented negative stock for product ID {$this->id_produk}: attempted {$value}, set to {$intValue}");
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
