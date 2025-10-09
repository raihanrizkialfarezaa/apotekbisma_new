#!/bin/bash
# Script verifikasi untuk memastikan fix_kartu_stok files berfungsi dengan benar

echo "================================================"
echo "VERIFIKASI FIX KARTU STOK FILES"
echo "================================================"
echo ""

# Warna untuk output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "1. Memeriksa struktur file..."
echo ""

# Check main files di root
echo "   Memeriksa file utama di root directory:"
for file in fix_kartu_stok_perfect.php fix_kartu_stok_robust.php fix_kartu_stok_ultimate.php; do
    if [ -f "$file" ]; then
        size=$(wc -c < "$file")
        if [ $size -gt 1000 ]; then
            echo -e "   ${GREEN}✓${NC} $file (${size} bytes) - OK"
        else
            echo -e "   ${RED}✗${NC} $file terlalu kecil (${size} bytes) - MUNGKIN BRIDGE FILE"
        fi
    else
        echo -e "   ${RED}✗${NC} $file tidak ditemukan"
    fi
done

echo ""
echo "   Memeriksa bridge files di public/:"
for file in public/fix_kartu_stok_perfect.php public/fix_kartu_stok_robust.php public/fix_kartu_stok_ultimate.php; do
    if [ -f "$file" ]; then
        size=$(wc -c < "$file")
        if [ $size -lt 500 ]; then
            echo -e "   ${GREEN}✓${NC} $file (${size} bytes) - OK (Bridge file)"
        else
            echo -e "   ${YELLOW}!${NC} $file terlalu besar (${size} bytes) - MUNGKIN FILE UTAMA"
        fi
    else
        echo -e "   ${RED}✗${NC} $file tidak ditemukan"
    fi
done

echo ""
echo "2. Memeriksa syntax PHP..."
echo ""

# Check syntax untuk semua file
for file in fix_kartu_stok_perfect.php fix_kartu_stok_robust.php fix_kartu_stok_ultimate.php; do
    if php -l "$file" > /dev/null 2>&1; then
        echo -e "   ${GREEN}✓${NC} $file - Syntax OK"
    else
        echo -e "   ${RED}✗${NC} $file - Syntax ERROR"
    fi
done

for file in public/fix_kartu_stok_perfect.php public/fix_kartu_stok_robust.php public/fix_kartu_stok_ultimate.php; do
    if php -l "$file" > /dev/null 2>&1; then
        echo -e "   ${GREEN}✓${NC} $file - Syntax OK"
    else
        echo -e "   ${RED}✗${NC} $file - Syntax ERROR"
    fi
done

echo ""
echo "3. Memeriksa konten bridge files..."
echo ""

# Check apakah bridge file mengandung chdir dan require_once
for file in public/fix_kartu_stok_perfect.php public/fix_kartu_stok_robust.php public/fix_kartu_stok_ultimate.php; do
    if grep -q "chdir" "$file" && grep -q "require_once" "$file"; then
        echo -e "   ${GREEN}✓${NC} $file - Mengandung chdir() dan require_once()"
    else
        echo -e "   ${RED}✗${NC} $file - TIDAK mengandung bridge pattern yang benar"
    fi
done

echo ""
echo "4. Memeriksa konten file utama..."
echo ""

# Check apakah file utama mengandung require vendor/autoload.php yang benar
for file in fix_kartu_stok_perfect.php fix_kartu_stok_robust.php fix_kartu_stok_ultimate.php; do
    if grep -q "__DIR__.'/vendor/autoload.php'" "$file" || grep -q "__DIR__ . '/vendor/autoload.php'" "$file"; then
        echo -e "   ${GREEN}✓${NC} $file - Path autoload.php sudah benar (relative dari root)"
    elif grep -q "__DIR__.'/../vendor/autoload.php'" "$file"; then
        echo -e "   ${YELLOW}!${NC} $file - Masih menggunakan path lama (relative dari public)"
    else
        echo -e "   ${RED}✗${NC} $file - Path autoload.php tidak ditemukan atau salah"
    fi
done

echo ""
echo "================================================"
echo "VERIFIKASI SELESAI"
echo "================================================"
echo ""
echo "Jika semua check menampilkan tanda ${GREEN}✓${NC}, maka fix sudah benar!"
echo "File-file sekarang bisa diakses melalui URL seperti:"
echo "  - https://apotikbisma.viviashop.com/fix_kartu_stok_perfect.php"
echo "  - https://apotikbisma.viviashop.com/fix_kartu_stok_robust.php"
echo "  - https://apotikbisma.viviashop.com/fix_kartu_stok_ultimate.php"
echo ""
