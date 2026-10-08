# ALIEN hax

ALIEN hax is a PHP storefront with a password-protected admin dashboard. The storefront includes PUBG Cheats for LDPlayer/MEmu and GameLoop, plus iOS products with 24-hour, 7-day, and 30-day access options. Manage each duration's price independently in the dashboard Products section. Product photos are uploaded to the private site's `uploads` directory and served as regular storefront images. Checkout shows these manual payment methods:

- Vodafone Cash: `01031799323`
- InstaPay: `abdallah.204@instapay`, with its QR image in `assets/payments/instapay-qr.png`.
- Binance: `0x127a3a398819dd9f08a0e36858eb13d89f2ed6b0`, accepting USDT on BEP20 (BNB Smart Chain), with the wallet QR in `assets/images/wallet-qr.png`.

Buyers need to send payment proof through support; payments are not automatically processed. Checkout creates an order with status `Awaiting payment confirmation`. An administrator must verify the transfer and confirm the order in the dashboard before the customer confirmation email is sent.

## PHP hosting requirements

- PHP 8.1 or newer with the `fileinfo` extension.
- Apache with `mod_rewrite` and `.htaccess` enabled, or an equivalent setup that sends `/api/*` and `/dashboard` to `index.php`.
- PHP write access to `products.json`, `theme.json`, `store-pricing.json`, `contacts.json`, and the `uploads` directory.
- PHP write access to `users.json` and its directory so customer account registrations can be stored. Customer passwords are stored as PHP password hashes, not plaintext.
- PHP write access to `orders.json` and its directory so checkout orders and email delivery status can be stored.
- Set `upload_max_filesize` to at least `8M` and `post_max_size` to at least `10M` to support product image uploads.

Upload the project files to the PHP site's document root. Keep `.htaccess` and `uploads/.htaccess` in place. Do not deploy this PHP version to Netlify; Netlify does not run PHP.

## Admin credentials

Set these in your PHP hosting provider's private environment settings:

- `ALIEN_ADMIN_USER`: `abosalah` (this is also the default if the variable is omitted).
- `ALIEN_ADMIN_PASSWORD`: set an initial admin password. Keep it in the hosting environment only, never in a committed file.

On a local installation with no admin password configured, open `/admin-login` from that computer to create the initial password; this first-time setup is restricted to localhost. Production hosting must set `ALIEN_ADMIN_PASSWORD` privately before sign-in.

After signing in, change the admin username and password in Dashboard → Store settings. The saved password is stored as a password hash in `admin-credentials.json`, which is blocked from direct web access; saved credentials take precedence over the initial environment values. Changing credentials signs out other active admin sessions. PHP needs write access to the project directory. Keep this file private and out of public backups/source control. Admin passwords must be at least 10 characters.

The default admin username is `abosalah`. The dashboard is at `/dashboard`. PHP creates an HTTP-only, SameSite Strict session after login. Use HTTPS in production so the session cookie is marked Secure.

The admin sign-in page and dashboard support Arabic and English. Use the language toggle to switch languages; the selected language is remembered in the browser, and Arabic uses right-to-left layout.

Customers can create an account or sign in with an email address at `/account`. Passwords must be at least 10 characters. Shopping cart and checkout actions require a customer session; checkout revalidates account status, product availability, and prices on the server. Customer sessions use HTTP-only, SameSite Strict cookies, and production deployments must use HTTPS.

## Order confirmation email

Customer confirmation messages are sent with the Resend email API only after an admin marks a customer order as payment-confirmed in the dashboard. Configure `RESEND_API_KEY` and a verified sender address in the PHP host's private environment as `RESEND_FROM_EMAIL` (for example, `ALIEN hax <orders@example.com>`). Never put the API key in project files. If email delivery fails, the order stays confirmed, the failure is logged, and the dashboard lets the admin retry sending the email. Customers can view order and email status at `/account?view=orders`.

## Local PHP preview

Install PHP 8.1+ with `fileinfo`, then set the credentials in the PowerShell process used to start the server. To test order emails locally, also set a Resend API key and a verified sender address:

```powershell
$env:ALIEN_ADMIN_USER = "abosalah"
$env:ALIEN_ADMIN_PASSWORD = Read-Host "Admin password"
$env:RESEND_API_KEY = Read-Host "Resend API key"
$env:RESEND_FROM_EMAIL = "ALIEN hax <orders@example.com>"
php -S 127.0.0.1:8000 router.php
```

Open `http://127.0.0.1:8000`. The PHP process must have write access to this folder and `uploads`.

## Products

Use the dashboard catalog to update product prices and stock, hide or show products on the storefront, replace product photos, or remove products. The add-product form supports a product name, category, description, prices, billing period, optional card label, and optional image. Duration-based products with the same category and name are grouped into a single storefront card. Saved product changes appear immediately in other open storefront tabs in the same browser and refresh from the server when a storefront tab becomes active again.
