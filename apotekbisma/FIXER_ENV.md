Security for Fixer Script

The fixer endpoints require a secret key stored in your Laravel `.env` as `FIX_SECRET`.

- Add this line to your `.env` on the host (choose a long random value):

  FIX_SECRET=your_long_random_secret_here

- After updating `.env`, clear config and route caches:

  php artisan config:clear
  php artisan route:clear
  php artisan cache:clear

- Example usage in browser (replace the secret):

  https://your-domain.tld/fix-kartu-stok-controller?key=your_long_random_secret_here&product_id=396

If you cannot edit `.env`, contact hosting admin or let me add a temporarily authenticated admin-only route that uses Laravel auth instead of `FIX_SECRET`.
