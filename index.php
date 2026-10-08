<?php
declare(strict_types=1);

const DEFAULT_THEME = [
    'bg' => '#080b12',
    'primary' => '#f4c20d',
    'primary2' => '#ffd43b',
    'primary3' => '#fff1a8',
    'text' => '#f8f5ec',
    'muted' => '#c2bca8',
];
const DEFAULT_CONTACTS = [
    'discord' => 'https://discord.gg/DdukFbQuea',
    'telegram' => 'https://t.me/ALIENOFFICIAL_1',
];
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_file(string $filename, array $fallback): array
{
    $contents = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $filename);
    if ($contents === false || trim($contents) === '') {
        return $fallback;
    }

    try {
        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log("Invalid JSON in {$filename}: {$error->getMessage()}");
        return $fallback;
    }

    return is_array($value) ? $value : $fallback;
}

function read_products(): array
{
    $contents = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'products.json');
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $products = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log("Invalid JSON in products.json: {$error->getMessage()}");
        return [];
    }

    if (!is_array($products) || !array_is_list($products)) {
        return [];
    }

    return $products;
}

function write_json_file(string $filename, array $value): void
{
    $destination = __DIR__ . DIRECTORY_SEPARATOR . $filename;
    $temporary = $destination . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $encoded = json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (@file_put_contents($temporary, $encoded, LOCK_EX) !== false) {
        if (@rename($temporary, $destination)) {
            return;
        }
        @unlink($temporary);
    }

    if (@file_put_contents($destination, $encoded, LOCK_EX) === false) {
        throw new RuntimeException("Could not write {$filename}");
    }
}

function update_products(callable $update): mixed
{
    $lockPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '.catalog.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not lock the product catalog.');
    }

    try {
        [$updated, $result] = $update(read_products());
        write_json_file('products.json', $updated);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function persist_json_file(string $filename, array $value): void
{
    try {
        write_json_file($filename, $value);
    } catch (Throwable $error) {
        error_log("Could not save {$filename}: {$error->getMessage()}");
        json_response(['error' => 'Settings could not be saved. Check that the site data directory is writable.'], 500);
    }
}

function request_data(): array
{
    $contents = file_get_contents('php://input');
    if ($contents === false || $contents === '') {
        return [];
    }

    try {
        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['error' => 'Invalid JSON request.'], 400);
    }

    if (!is_array($value)) {
        json_response(['error' => 'Invalid request data.'], 400);
    }

    return $value;
}

function require_admin(): void
{
    try {
        $authenticated = current_admin_session_is_valid();
    } catch (Throwable $error) {
        error_log('Could not verify the admin session: ' . $error->getMessage());
        json_response(['error' => 'Admin authentication is temporarily unavailable.'], 500);
    }
    if (!$authenticated) {
        json_response(['error' => 'Admin login required.'], 401);
    }
}

function read_admin_credentials(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'admin-credentials.json';
    if (!is_file($path)) {
        $username = getenv('ALIEN_ADMIN_USER') ?: 'abosalah';
        $password = getenv('ALIEN_ADMIN_PASSWORD') ?: '';
        return [
            'username' => $username,
            'password' => $password,
            'passwordHash' => null,
            'credentialVersion' => hash('sha256', $username . "\0" . $password),
        ];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Could not read admin credentials.');
    }

    $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($credentials)
        || !is_string($credentials['username'] ?? null)
        || !is_string($credentials['passwordHash'] ?? null)
        || !is_string($credentials['credentialVersion'] ?? null)) {
        throw new RuntimeException('The admin credentials file is invalid.');
    }

    return [
        'username' => $credentials['username'],
        'password' => null,
        'passwordHash' => $credentials['passwordHash'],
        'credentialVersion' => $credentials['credentialVersion'],
    ];
}

function current_admin_session_is_valid(): bool
{
    $adminSession = $_SESSION['alien_admin'] ?? null;
    if (!is_array($adminSession)
        || !is_string($adminSession['username'] ?? null)
        || !is_string($adminSession['credentialVersion'] ?? null)) {
        return false;
    }

    $credentials = read_admin_credentials();
    if (hash_equals($credentials['username'], $adminSession['username'])
        && hash_equals($credentials['credentialVersion'], $adminSession['credentialVersion'])) {
        return true;
    }

    unset($_SESSION['alien_admin']);
    session_regenerate_id(true);
    return false;
}

function verify_admin_password(array $credentials, string $password): bool
{
    if (is_string($credentials['passwordHash'] ?? null)) {
        return password_verify($password, $credentials['passwordHash']);
    }

    $expectedPassword = $credentials['password'] ?? '';
    return is_string($expectedPassword)
        && $expectedPassword !== ''
        && hash_equals($expectedPassword, $password);
}

function is_local_admin_setup_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function is_admin_setup_available(): bool
{
    return is_local_admin_setup_request()
        && !is_file(__DIR__ . DIRECTORY_SEPARATOR . 'admin-credentials.json')
        && (getenv('ALIEN_ADMIN_PASSWORD') ?: '') === '';
}

function require_customer(): void
{
    if (empty($_SESSION['alien_customer']['email'])) {
        json_response(['error' => 'Sign in to your customer account before checkout.'], 401);
    }
}

function require_same_origin(): void
{
    $targetHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if ($targetHost === '') {
        json_response(['error' => 'The request origin could not be verified. Reload the page and try again.'], 403);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin === '') {
        $fetchSite = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($fetchSite === 'same-origin' || $fetchSite === 'same-site' || $fetchSite === 'none') {
            return;
        }
        json_response(['error' => 'The request origin could not be verified. Reload the page and try again.'], 403);
    }

    $parsedOrigin = parse_url($origin);
    $originHost = strtolower($parsedOrigin['host'] ?? '');
    $originPort = isset($parsedOrigin['port']) ? ':' . $parsedOrigin['port'] : '';
    $originHostWithPort = $originHost . $originPort;
    $hostOnly = strtolower(explode(':', $targetHost)[0]);

    if ($originHost !== '' && ($originHost === $hostOnly || $originHostWithPort === $targetHost)) {
        return;
    }

    json_response(['error' => 'The request origin could not be verified. Reload the page and try again.'], 403);
}

function read_users(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'users.json';
    if (!is_file($path)) {
        return [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $users = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log("Invalid JSON in users.json: {$error->getMessage()}");
        return [];
    }

    if (!is_array($users) || !array_is_list($users)) {
        return [];
    }

    return $users;
}

function update_users(callable $update): mixed
{
    $lock = @fopen(__DIR__ . DIRECTORY_SEPARATOR . '.users.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not lock the customer account store.');
    }

    try {
        [$updated, $result] = $update(read_users());
        write_json_file('users.json', $updated);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function read_orders(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'orders.json';
    if (!is_file($path)) {
        return [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    try {
        $orders = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log("Invalid JSON in orders.json: {$error->getMessage()}");
        return [];
    }

    if (!is_array($orders) || !array_is_list($orders)) {
        return [];
    }

    return $orders;
}

function update_orders(callable $update): mixed
{
    $lock = @fopen(__DIR__ . DIRECTORY_SEPARATOR . '.orders.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not lock the order store.');
    }

    try {
        [$updated, $result] = $update(read_orders());
        write_json_file('orders.json', $updated);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function send_order_confirmation_email(array $order): void
{
    $apiKey = getenv('RESEND_API_KEY') ?: '';
    $sender = getenv('RESEND_FROM_EMAIL') ?: '';
    if ($apiKey === '' || $sender === '') {
        throw new RuntimeException('RESEND_API_KEY and RESEND_FROM_EMAIL must be configured.');
    }

    $customerName = (string) ($order['customerName'] ?? 'Customer');
    $email = (string) ($order['customerEmail'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('The customer order email address is invalid.');
    }

    $lines = [];
    $htmlItems = [];
    foreach ($order['items'] as $item) {
        $name = (string) ($item['name'] ?? 'Product');
        $quantity = (int) ($item['quantity'] ?? 0);
        $lineTotal = (float) ($item['lineTotal'] ?? 0);
        $lines[] = sprintf('%s x %d — $%.2f', $name, $quantity, $lineTotal);
        $htmlItems[] = '<tr><td style="padding:10px;border-bottom:1px solid #ddd">'
            . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</td><td style="padding:10px;border-bottom:1px solid #ddd;text-align:center">'
            . $quantity
            . '</td><td style="padding:10px;border-bottom:1px solid #ddd;text-align:right">$'
            . number_format($lineTotal, 2, '.', '')
            . '</td></tr>';
    }

    $orderNumber = htmlspecialchars((string) $order['orderNumber'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeName = htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $createdAt = new DateTimeImmutable((string) $order['createdAt']);
    $orderDate = $createdAt->format('Y-m-d H:i:s T');
    $total = number_format((float) $order['total'], 2, '.', '');
    $status = (string) ($order['status'] ?? 'Payment confirmed');
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#171717">'
        . '<h1>Order payment confirmed</h1><p>Hello ' . $safeName . ',</p>'
        . '<p>Your payment has been confirmed. Here is your order summary:</p>'
        . '<p><strong>Order number:</strong> ' . $orderNumber . '<br>'
        . '<strong>Date and time:</strong> ' . htmlspecialchars($orderDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>'
        . '<strong>Status:</strong> ' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px"><thead><tr>'
        . '<th style="padding:10px;text-align:left">Product</th><th style="padding:10px">Quantity</th>'
        . '<th style="padding:10px;text-align:right">Total</th></tr></thead><tbody>'
        . implode('', $htmlItems)
        . '</tbody></table><p><strong>Order total: $' . $total . '</strong></p>'
        . '<p>Thank you for your order,<br>ALIEN hax</p></body></html>';
    $text = "Hello {$customerName},\n\nYour payment has been confirmed.\n"
        . "Order number: {$order['orderNumber']}\nDate and time: {$orderDate}\nStatus: {$status}\n\n"
        . implode("\n", $lines) . "\n\nOrder total: \${$total}\n\nALIEN hax";

    $payload = json_encode([
        'from' => $sender,
        'to' => [$email],
        'subject' => "Payment confirmed — order {$order['orderNumber']}",
        'html' => $html,
        'text' => $text,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents('https://api.resend.com/emails', false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = $responseHeaders[0] ?? '';
    if ($response === false || !preg_match('~\s2\d\d(?:\s|$)~', $statusLine)) {
        $error = error_get_last();
        $detail = $response === false && isset($error['message']) ? $error['message'] : $statusLine;
        throw new RuntimeException('Resend email delivery failed: ' . $detail);
    }
}

function validate_optional_price(mixed $value, string $field): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        json_response(['error' => "Enter a valid {$field}."], 400);
    }

    $price = (float) $value;
    if (!is_finite($price) || $price < 0 || $price > 100000000) {
        json_response(['error' => "Enter a valid {$field}."], 400);
    }

    return round($price, 2);
}

function store_product_image(): ?string
{
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $upload = $_FILES['image'];
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        json_response(['error' => 'The image upload failed. Choose the image and try again.'], 400);
    }

    if ($upload['size'] > MAX_IMAGE_BYTES) {
        json_response(['error' => 'Choose an image smaller than 8 MB.'], 400);
    }

    $imageInfo = @getimagesize($upload['tmp_name']);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if ($imageInfo === false || !isset($extensions[$mimeType])) {
        json_response(['error' => 'Use a valid JPG, PNG, GIF, or WebP image.'], 400);
    }

    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        json_response(['error' => 'The image upload directory could not be created.'], 500);
    }

    if (!is_writable($directory)) {
        json_response(['error' => 'The image upload directory is not writable by PHP.'], 500);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        json_response(['error' => 'The uploaded image could not be saved.'], 500);
    }

    return '/uploads/' . $filename;
}

function remove_product_image(mixed $image): void
{
    if (!is_string($image) || !preg_match('~^/uploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)$~', $image)) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($image);
    if (is_file($path) && !unlink($path)) {
        error_log("Could not remove product image {$path}");
    }
}

// ─── Reviews helpers ─────────────────────────────────────────────────────────

function read_reviews(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'reviews.json';
    if (!is_file($path)) {
        return [];
    }
    $contents = @file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }
    try {
        $reviews = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log("Invalid JSON in reviews.json: {$error->getMessage()}");
        return [];
    }
    return is_array($reviews) && array_is_list($reviews) ? $reviews : [];
}

function update_reviews(callable $update): mixed
{
    $lock = @fopen(__DIR__ . DIRECTORY_SEPARATOR . '.reviews.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not lock the reviews store.');
    }
    try {
        [$updated, $result] = $update(read_reviews());
        write_json_file('reviews.json', $updated);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function store_review_image(): ?string
{
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $upload = $_FILES['image'];
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        json_response(['error' => 'The image upload failed.'], 400);
    }
    if ($upload['size'] > MAX_IMAGE_BYTES) {
        json_response(['error' => 'Choose an image smaller than 8 MB.'], 400);
    }
    $imageInfo = @getimagesize($upload['tmp_name']);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if ($imageInfo === false || !isset($extensions[$mimeType])) {
        json_response(['error' => 'Use a valid JPG, PNG, GIF, or WebP image.'], 400);
    }
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        json_response(['error' => 'The upload directory could not be created.'], 500);
    }
    if (!is_writable($directory)) {
        json_response(['error' => 'The upload directory is not writable.'], 500);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        json_response(['error' => 'The uploaded image could not be saved.'], 500);
    }
    return '/uploads/' . $filename;
}

function remove_review_image(mixed $image): void
{
    if (!is_string($image) || !preg_match('~^/uploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)$~', $image)) {
        return;
    }
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($image);
    if (is_file($path) && !unlink($path)) {
        error_log("Could not remove review image {$path}");
    }
}


$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$rawUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

if ($scriptDir !== '' && str_starts_with($rawUri, $scriptDir)) {
    $path = substr($rawUri, strlen($scriptDir));
} else {
    $path = $rawUri;
}
$path = '/' . ltrim($path, '/');
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET' && $path === '/api/auth/status') {
    $customer = $_SESSION['alien_customer'] ?? null;
    json_response([
        'authenticated' => is_array($customer) && is_string($customer['email'] ?? null),
        'email' => is_array($customer) ? ($customer['email'] ?? null) : null,
        'fullName' => is_array($customer) ? ($customer['fullName'] ?? '') : '',
    ]);
}

if ($method === 'POST' && in_array($path, ['/api/auth/register', '/api/auth/login', '/api/auth/logout'], true)) {
    require_same_origin();

    if ($path === '/api/auth/logout') {
        unset($_SESSION['alien_customer']);
        session_regenerate_id(true);
        json_response(['authenticated' => false]);
    }

    $credentials = request_data();
    $email = $credentials['email'] ?? null;
    $password = $credentials['password'] ?? null;
    $fullName = $credentials['fullName'] ?? null;
    if (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_response(['error' => 'Enter a valid email address.'], 400);
    }
    if (!is_string($password) || strlen($password) > 1024) {
        json_response(['error' => 'Enter a valid password.'], 400);
    }

    $email = strtolower(trim($email));
    if ($path === '/api/auth/register') {
        $nameLength = is_string($fullName) ? preg_match_all('/./us', trim($fullName)) : false;
        if (!is_string($fullName) || trim($fullName) === '' || $nameLength === false || $nameLength > 100) {
            json_response(['error' => 'Enter your name (up to 100 characters).'], 400);
        }
        if (strlen($password) < 10) {
            json_response(['error' => 'Choose a password with at least 10 characters.'], 400);
        }

        try {
            $user = [
                'id' => bin2hex(random_bytes(16)),
                'email' => $email,
                'fullName' => trim($fullName),
                'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
                'createdAt' => gmdate(DATE_ATOM),
            ];
            $created = update_users(static function (array $users) use ($user): array {
                foreach ($users as $existing) {
                    if (is_array($existing) && strtolower((string) ($existing['email'] ?? '')) === $user['email']) {
                        return [$users, false];
                    }
                }
                $users[] = $user;
                return [$users, true];
            });
        } catch (Throwable $error) {
            error_log('Could not register customer: ' . $error->getMessage());
            json_response(['error' => 'Your account could not be created. Please try again later.'], 500);
        }

        if (!$created) {
            json_response(['error' => 'An account with this email already exists. Sign in instead.'], 409);
        }

        unset($_SESSION['alien_customer']);
        session_regenerate_id(true);
        json_response(['authenticated' => false, 'email' => $user['email']]);
    }

    try {
        $user = null;
        foreach (read_users() as $storedUser) {
            if (is_array($storedUser) && is_string($storedUser['email'] ?? null)
                && hash_equals($storedUser['email'], $email)) {
                $user = $storedUser;
                break;
            }
        }
    } catch (Throwable $error) {
        error_log('Could not read customer account store: ' . $error->getMessage());
        json_response(['error' => 'Sign-in is temporarily unavailable. Please try again later.'], 500);
    }

    if ($user === null || !is_string($user['passwordHash'] ?? null)
        || !password_verify($password, $user['passwordHash'])) {
        json_response(['error' => 'Email or password is incorrect.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['alien_customer'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'fullName' => is_string($user['fullName'] ?? null) ? $user['fullName'] : '',
    ];
    json_response(['authenticated' => true, 'email' => $user['email'], 'fullName' => $_SESSION['alien_customer']['fullName']]);
}

if ($method === 'POST' && $path === '/api/auth/forgot-password') {
    require_same_origin();
    $data = request_data();
    $email = $data['email'] ?? null;
    if (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_response(['error' => 'Enter a valid email address.'], 400);
    }
    $email = strtolower(trim($email));

    $resetCode = sprintf('%06d', random_int(100000, 999999));
    $userFound = false;

    try {
        update_users(static function (array $users) use ($email, $resetCode, &$userFound): array {
            foreach ($users as &$user) {
                if (is_array($user) && strtolower((string) ($user['email'] ?? '')) === $email) {
                    $user['resetCode'] = $resetCode;
                    $user['resetExpiresAt'] = time() + 3600;
                    $userFound = true;
                    break;
                }
            }
            unset($user);
            return [$users, $userFound];
        });
    } catch (Throwable $error) {
        error_log('Could not generate reset code: ' . $error->getMessage());
        json_response(['error' => 'Could not process password reset request. Try again later.'], 500);
    }

    if (!$userFound) {
        json_response(['error' => 'No account found with this email address.'], 404);
    }

    json_response(['success' => true, 'message' => 'Reset code generated.', 'code' => $resetCode]);
}

if ($method === 'POST' && $path === '/api/auth/reset-password') {
    require_same_origin();
    $data = request_data();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $code = trim((string) ($data['code'] ?? ''));
    $newPassword = (string) ($data['newPassword'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        json_response(['error' => 'Enter a valid email address.'], 400);
    }
    if (strlen($code) < 4) {
        json_response(['error' => 'Enter a valid reset code.'], 400);
    }
    if (strlen($newPassword) < 10) {
        json_response(['error' => 'Choose a new password with at least 10 characters.'], 400);
    }

    $success = false;
    $errorMessage = 'Invalid reset code or code expired.';

    try {
        update_users(static function (array $users) use ($email, $code, $newPassword, &$success, &$errorMessage): array {
            foreach ($users as &$user) {
                if (is_array($user) && strtolower((string) ($user['email'] ?? '')) === $email) {
                    $storedCode = (string) ($user['resetCode'] ?? '');
                    $expiresAt = (int) ($user['resetExpiresAt'] ?? 0);

                    if ($storedCode !== '' && hash_equals($storedCode, $code)) {
                        if ($expiresAt > 0 && time() > $expiresAt) {
                            $errorMessage = 'The reset code has expired. Please request a new one.';
                            break;
                        }
                        $user['passwordHash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        unset($user['resetCode'], $user['resetExpiresAt']);
                        $success = true;
                        break;
                    }
                }
            }
            unset($user);
            return [$users, $success];
        });
    } catch (Throwable $error) {
        error_log('Could not reset password: ' . $error->getMessage());
        json_response(['error' => 'Could not reset password. Please try again.'], 500);
    }

    if (!$success) {
        json_response(['error' => $errorMessage], 400);
    }

    json_response(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);
}

if ($method === 'GET' && $path === '/api/account/orders') {
    require_customer();
    try {
        $customerId = $_SESSION['alien_customer']['id'] ?? '';
        $customerOrders = array_values(array_filter(
            read_orders(),
            static fn (mixed $order): bool => is_array($order) && ($order['customerId'] ?? null) === $customerId
        ));
        json_response($customerOrders);
    } catch (Throwable $error) {
        error_log('Could not read customer orders: ' . $error->getMessage());
        json_response(['error' => 'Your order history is temporarily unavailable.'], 500);
    }
}

if ($method === 'POST' && $path === '/api/checkout') {
    require_same_origin();
    require_customer();
    $submitted = request_data();
    $productIds = $submitted['productIds'] ?? null;
    if (!is_array($productIds) || $productIds === [] || count($productIds) > 100) {
        json_response(['error' => 'Add at least one product to your cart before checkout.'], 400);
    }

    try {
        $products = read_products();
        $pricing = read_json_file('store-pricing.json', ['divisor' => 5.2]);
    } catch (Throwable $error) {
        error_log('Could not validate checkout catalog: ' . $error->getMessage());
        json_response(['error' => 'Checkout is temporarily unavailable. Please try again later.'], 500);
    }

    $divisor = filter_var($pricing['divisor'] ?? 5.2, FILTER_VALIDATE_FLOAT);
    if ($divisor === false || !is_finite((float) $divisor) || $divisor < 0.01 || $divisor > 10000) {
        json_response(['error' => 'Store pricing settings are invalid. Contact support.'], 500);
    }

    $catalogById = [];
    foreach ($products as $product) {
        if (is_array($product) && is_string($product['id'] ?? null)) {
            $catalogById[$product['id']] = $product;
        }
    }

    $itemsById = [];
    $total = 0.0;
    foreach ($productIds as $productId) {
        if (!is_string($productId) || !isset($catalogById[$productId])) {
            json_response(['error' => 'A product in your cart is no longer available. Refresh the catalog and try again.'], 409);
        }
        $product = $catalogById[$productId];
        if (!empty($product['hidden']) || in_array($product['status'] ?? '', ['out-of-stock', 'sold'], true)) {
            json_response(['error' => 'A product in your cart is not available for purchase.'], 409);
        }

        $price = isset($product['storePrice']) && is_numeric($product['storePrice'])
            ? (float) $product['storePrice']
            : (isset($product['basePrice']) && is_numeric($product['basePrice'])
                ? (float) $product['basePrice'] / (float) $divisor
                : null);
        if ($price === null || !is_finite($price) || $price < 0) {
            json_response(['error' => 'A product in your cart does not have a valid price.'], 409);
        }

        $price = round($price, 2);
        if (!isset($itemsById[$productId])) {
            $itemsById[$productId] = [
                'id' => $productId,
                'name' => $product['name'],
                'unitPrice' => $price,
                'quantity' => 0,
            ];
        }
        $itemsById[$productId]['quantity']++;
    }

    $items = [];
    foreach ($itemsById as $item) {
        $item['lineTotal'] = round($item['unitPrice'] * $item['quantity'], 2);
        $total += $item['lineTotal'];
        $items[] = $item;
    }

    $customer = $_SESSION['alien_customer'];
    $createdAt = gmdate(DATE_ATOM);
    $order = [
        'id' => bin2hex(random_bytes(16)),
        'orderNumber' => 'AL-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'customerId' => $customer['id'],
        'customerName' => trim((string) ($customer['fullName'] ?? '')) ?: $customer['email'],
        'customerEmail' => $customer['email'],
        'items' => $items,
        'total' => round($total, 2),
        'createdAt' => $createdAt,
        'status' => 'Awaiting payment confirmation',
        'emailStatus' => 'not_sent',
    ];
    try {
        update_orders(static function (array $orders) use ($order): array {
            $orders[] = $order;
            return [$orders, null];
        });
    } catch (Throwable $error) {
        error_log('Could not create customer order: ' . $error->getMessage());
        json_response(['error' => 'Your order could not be created. No payment confirmation was sent. Please try again.'], 500);
    }

    json_response([
        'authenticated' => true,
        'order' => $order,
        'items' => $items,
        'total' => round($total, 2),
    ]);
}

if ($method === 'GET' && $path === '/api/admin/setup/status') {
    json_response([
        'setupRequired' => is_admin_setup_available(),
        'username' => getenv('ALIEN_ADMIN_USER') ?: 'abosalah',
    ]);
}

if ($method === 'POST' && $path === '/api/admin/setup') {
    require_same_origin();
    if (!is_local_admin_setup_request()) {
        json_response(['error' => 'Initial admin setup is only available from this computer.'], 403);
    }

    $submitted = request_data();
    $username = $submitted['username'] ?? null;
    $password = $submitted['password'] ?? null;
    $confirmPassword = $submitted['confirmPassword'] ?? null;
    if (!is_string($username) || !is_string($password) || !is_string($confirmPassword)) {
        json_response(['error' => 'Enter a username and matching password.'], 400);
    }
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        json_response(['error' => 'Use 3–64 letters, numbers, dots, underscores, or hyphens for the username.'], 400);
    }
    if (strlen($password) < 10 || strlen($password) > 1024) {
        json_response(['error' => 'Choose a password with at least 10 characters.'], 400);
    }
    if (!hash_equals($password, $confirmPassword)) {
        json_response(['error' => 'The passwords do not match.'], 400);
    }

    $lock = @fopen(__DIR__ . DIRECTORY_SEPARATOR . '.admin-credentials.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        error_log('Could not lock admin credentials during initial setup.');
        json_response(['error' => 'Initial admin setup could not be completed. Please try again.'], 500);
    }

    try {
        if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'admin-credentials.json')
            || (getenv('ALIEN_ADMIN_PASSWORD') ?: '') !== '') {
            json_response(['error' => 'Admin credentials are already configured. Sign in instead.'], 409);
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not hash the initial admin password.');
            }
            $credentialVersion = bin2hex(random_bytes(16));
            write_json_file('admin-credentials.json', [
                'username' => $username,
                'passwordHash' => $passwordHash,
                'credentialVersion' => $credentialVersion,
                'updatedAt' => gmdate(DATE_ATOM),
            ]);
        } catch (Throwable $error) {
            error_log('Could not save initial admin credentials: ' . $error->getMessage());
            json_response(['error' => 'Initial admin setup could not be saved. Check that the site data directory is writable.'], 500);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    session_regenerate_id(true);
    $_SESSION['alien_admin'] = ['username' => $username, 'credentialVersion' => $credentialVersion];
    json_response(['authenticated' => true]);
}

if ($method === 'POST' && $path === '/api/admin/login') {
    $credentials = request_data();
    try {
        $adminCredentials = read_admin_credentials();
    } catch (Throwable $error) {
        error_log('Could not read admin credentials: ' . $error->getMessage());
        json_response(['error' => 'Admin sign-in is temporarily unavailable. Check the admin credentials file.'], 500);
    }

    $username = $credentials['username'] ?? null;
    $password = $credentials['password'] ?? null;
    if ($adminCredentials['password'] === '' && $adminCredentials['passwordHash'] === null) {
        json_response(['error' => 'Set ALIEN_ADMIN_PASSWORD in the PHP hosting environment before signing in.'], 503);
    }
    if (!is_string($username) || !is_string($password)
        || !hash_equals($adminCredentials['username'], $username)
        || !verify_admin_password($adminCredentials, $password)) {
        json_response(['error' => 'Username or password is incorrect.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['alien_admin'] = [
        'username' => $adminCredentials['username'],
        'credentialVersion' => $adminCredentials['credentialVersion'],
    ];
    json_response(['authenticated' => true]);
}

if ($method === 'GET' && $path === '/api/admin/credentials') {
    require_admin();
    try {
        $credentials = read_admin_credentials();
        json_response(['username' => $credentials['username']]);
    } catch (Throwable $error) {
        error_log('Could not load admin credential settings: ' . $error->getMessage());
        json_response(['error' => 'Admin credential settings could not be loaded.'], 500);
    }
}

if ($method === 'PUT' && $path === '/api/admin/credentials') {
    require_admin();
    require_same_origin();
    $submitted = request_data();
    $currentUsername = $submitted['currentUsername'] ?? null;
    $currentPassword = $submitted['currentPassword'] ?? null;
    $newUsername = $submitted['newUsername'] ?? null;
    $newPassword = $submitted['newPassword'] ?? null;
    $confirmPassword = $submitted['confirmPassword'] ?? null;

    if (!is_string($currentUsername) || !is_string($currentPassword)
        || !is_string($newUsername) || !is_string($newPassword) || !is_string($confirmPassword)) {
        json_response(['error' => 'Complete all admin credential fields.'], 400);
    }
    $newUsername = trim($newUsername);
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $newUsername)) {
        json_response(['error' => 'Use 3–64 letters, numbers, dots, underscores, or hyphens for the username.'], 400);
    }
    if (strlen($newPassword) < 10 || strlen($newPassword) > 1024) {
        json_response(['error' => 'Choose a new password with at least 10 characters.'], 400);
    }
    if (!hash_equals($newPassword, $confirmPassword)) {
        json_response(['error' => 'The new passwords do not match.'], 400);
    }

    $lock = @fopen(__DIR__ . DIRECTORY_SEPARATOR . '.admin-credentials.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        error_log('Could not lock admin credentials for update.');
        json_response(['error' => 'Admin credentials could not be updated. Please try again.'], 500);
    }

    try {
        try {
            $existing = read_admin_credentials();
        } catch (Throwable $error) {
            error_log('Could not read admin credentials during update: ' . $error->getMessage());
            json_response(['error' => 'Admin credentials could not be verified.'], 500);
        }
        if (!hash_equals($existing['username'], $currentUsername)
            || !verify_admin_password($existing, $currentPassword)) {
            json_response(['error' => 'Current username or password is incorrect.'], 401);
        }

        try {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not hash the updated admin password.');
            }
            $credentialVersion = bin2hex(random_bytes(16));
            write_json_file('admin-credentials.json', [
                'username' => $newUsername,
                'passwordHash' => $passwordHash,
                'credentialVersion' => $credentialVersion,
                'updatedAt' => gmdate(DATE_ATOM),
            ]);
        } catch (Throwable $error) {
            error_log('Could not save updated admin credentials: ' . $error->getMessage());
            json_response(['error' => 'Admin credentials could not be saved. Check that the site data directory is writable.'], 500);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    session_regenerate_id(true);
    $_SESSION['alien_admin'] = ['username' => $newUsername, 'credentialVersion' => $credentialVersion];
    json_response(['saved' => true, 'username' => $newUsername]);
}

if ($method === 'POST' && $path === '/api/admin/logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
    }
    session_destroy();
    json_response(['authenticated' => false]);
}

if ($method === 'GET' && $path === '/api/products') {
    try {
        json_response(read_products());
    } catch (Throwable $error) {
        error_log('Could not load product catalog: ' . $error->getMessage());
        json_response(['error' => 'The product catalog is unavailable.'], 500);
    }
}

if ($method === 'GET' && $path === '/api/theme') {
    json_response(read_json_file('theme.json', DEFAULT_THEME));
}

if ($method === 'GET' && $path === '/api/pricing') {
    json_response(read_json_file('store-pricing.json', ['divisor' => 5.2]));
}

if ($method === 'GET' && $path === '/api/contacts') {
    json_response(read_json_file('contacts.json', DEFAULT_CONTACTS));
}

if ($method === 'GET' && $path === '/api/dashboard/orders') {
    require_admin();
    try {
        json_response(array_reverse(read_orders()));
    } catch (Throwable $error) {
        error_log('Could not read admin orders: ' . $error->getMessage());
        json_response(['error' => 'The order list is temporarily unavailable.'], 500);
    }
}

if ($method === 'POST' && $path === '/api/dashboard/orders/confirm') {
    require_admin();
    require_same_origin();
    $submitted = request_data();
    $id = $submitted['id'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
        json_response(['error' => 'Choose a valid order.'], 400);
    }

    $orderForEmail = null;
    $deliveryState = null;
    try {
        $found = update_orders(static function (array $orders) use ($id, &$orderForEmail, &$deliveryState): array {
            foreach ($orders as &$order) {
                if (!is_array($order) || ($order['id'] ?? null) !== $id) {
                    continue;
                }
                if (($order['status'] ?? '') === 'Payment confirmed' && ($order['emailStatus'] ?? '') === 'sent') {
                    $orderForEmail = $order;
                    $deliveryState = 'already_sent';
                    break;
                }
                if (($order['status'] ?? '') === 'Payment confirmed' && ($order['emailStatus'] ?? '') === 'sending') {
                    $deliveryState = 'sending';
                    break;
                }
                if (!in_array($order['status'] ?? '', ['Awaiting payment confirmation', 'Payment confirmed'], true)) {
                    $deliveryState = 'invalid_state';
                    break;
                }

                if (($order['status'] ?? '') !== 'Payment confirmed') {
                    $order['status'] = 'Payment confirmed';
                    $order['confirmedAt'] = gmdate(DATE_ATOM);
                }
                $order['emailStatus'] = 'sending';
                unset($order['emailError']);
                $order['emailAttemptAt'] = gmdate(DATE_ATOM);
                $orderForEmail = $order;
                $deliveryState = 'send';
                break;
            }
            unset($order);

            return [$orders, $deliveryState !== null];
        });
    } catch (Throwable $error) {
        error_log('Could not confirm order: ' . $error->getMessage());
        json_response(['error' => 'The order could not be updated. Please try again.'], 500);
    }

    if (!$found || $deliveryState === null) {
        json_response(['error' => 'Order not found.'], 404);
    }
    if ($deliveryState === 'invalid_state') {
        json_response(['error' => 'This order cannot be confirmed in its current state.'], 409);
    }
    if ($deliveryState === 'already_sent') {
        json_response(['order' => $orderForEmail, 'emailSent' => true, 'alreadySent' => true]);
    }
    if ($deliveryState === 'sending') {
        json_response(['order' => null, 'emailSent' => false, 'deliveryInProgress' => true]);
    }

    $emailSent = false;
    $emailError = 'Order confirmed, but the confirmation email could not be sent. Check email configuration and retry.';
    try {
        send_order_confirmation_email($orderForEmail);
        $emailSent = true;
    } catch (Throwable $error) {
        error_log(sprintf(
            'Order %s payment is confirmed, but its confirmation email failed: %s',
            $orderForEmail['orderNumber'],
            $error->getMessage()
        ));
    }

    $updatedOrder = null;
    try {
        update_orders(static function (array $orders) use ($id, $emailSent, $emailError, &$updatedOrder): array {
            foreach ($orders as &$order) {
                if (is_array($order) && ($order['id'] ?? null) === $id) {
                    $order['emailStatus'] = $emailSent ? 'sent' : 'failed';
                    if (!$emailSent) {
                        $order['emailError'] = $emailError;
                    } else {
                        unset($order['emailError']);
                        $order['emailSentAt'] = gmdate(DATE_ATOM);
                    }
                    $updatedOrder = $order;
                    break;
                }
            }
            unset($order);

            return [$orders, $updatedOrder !== null];
        });
    } catch (Throwable $error) {
        error_log(sprintf('Could not save email delivery status for order %s: %s', $orderForEmail['orderNumber'], $error->getMessage()));
        json_response([
            'order' => $orderForEmail,
            'emailSent' => $emailSent,
            'warning' => 'Order is confirmed, but the email delivery status could not be saved.',
        ]);
    }

    json_response([
        'order' => $updatedOrder,
        'emailSent' => $emailSent,
        'warning' => $emailSent ? null : $emailError,
    ]);
}

if (str_starts_with($path, '/api/dashboard/')) {
    require_admin();

    if ($method === 'PUT' && $path === '/api/dashboard/theme') {
        $submitted = request_data();
        $theme = [];
        foreach (DEFAULT_THEME as $key => $fallback) {
            $value = $submitted[$key] ?? null;
            if (!is_string($value) || !preg_match('/^#[0-9a-f]{6}$/i', $value)) {
                json_response(['error' => "Invalid color for {$key}."], 400);
            }
            $theme[$key] = $value;
        }
        persist_json_file('theme.json', $theme);
        json_response(['saved' => true, 'theme' => $theme]);
    }

    if ($method === 'PUT' && $path === '/api/dashboard/pricing') {
        $submitted = request_data();
        $divisor = filter_var($submitted['divisor'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($divisor === false || !is_finite((float) $divisor) || $divisor < 0.01 || $divisor > 10000) {
            json_response(['error' => 'Enter a divisor between 0.01 and 10,000.'], 400);
        }
        $pricing = ['divisor' => round((float) $divisor, 4)];
        persist_json_file('store-pricing.json', $pricing);
        json_response(['saved' => true, ...$pricing]);
    }

    if ($method === 'PUT' && $path === '/api/dashboard/contacts') {
        $submitted = request_data();
        $contacts = [];
        foreach (DEFAULT_CONTACTS as $key => $fallback) {
            $value = $submitted[$key] ?? null;
            if (!is_string($value) || strlen($value) > 500 || !preg_match('~^https://~i', $value)) {
                json_response(['error' => "Enter a valid HTTPS URL for {$key}."], 400);
            }
            $contacts[$key] = $value;
        }
        persist_json_file('contacts.json', $contacts);
        json_response(['saved' => true, 'contacts' => $contacts]);
    }

    if ($method === 'POST' && $path === '/api/dashboard/products') {
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
                json_response(['error' => 'Choose a valid product to update.'], 400);
            }

            $image = store_product_image();
            if ($image === null) {
                json_response(['error' => 'Choose a product image to upload.'], 400);
            }

            $previousImage = null;
            $updatedProduct = null;
            try {
                $found = update_products(static function (array $products) use ($id, $image, &$previousImage, &$updatedProduct): array {
                    foreach ($products as &$product) {
                        if (is_array($product) && ($product['id'] ?? null) === $id) {
                            $previousImage = $product['image'] ?? null;
                            $product['image'] = $image;
                            $updatedProduct = $product;
                            break;
                        }
                    }
                    unset($product);

                    return [$products, $updatedProduct !== null];
                });
            } catch (Throwable $error) {
                remove_product_image($image);
                error_log('Could not update product image: ' . $error->getMessage());
                json_response(['error' => 'The product image could not be updated. Check that the site data directory is writable.'], 500);
            }

            if (!$found || $updatedProduct === null) {
                remove_product_image($image);
                json_response(['error' => 'Product not found.'], 404);
            }

            remove_product_image($previousImage);
            json_response(['saved' => true, 'product' => $updatedProduct]);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $icon = trim((string) ($_POST['icon'] ?? ''));
        $billingPeriod = (string) ($_POST['billingPeriod'] ?? '');
        $duration = (string) ($_POST['duration'] ?? '');

        if ($name === '' || strlen($name) > 100 || $category === '' || strlen($category) > 50) {
            json_response(['error' => 'Enter a product name (up to 100 characters) and category (up to 50 characters).'], 400);
        }
        if (strlen($description) > 2000 || strlen($icon) > 24) {
            json_response(['error' => 'The description or icon is too long.'], 400);
        }
        if (!in_array($billingPeriod, ['', 'week', 'permanent'], true)) {
            json_response(['error' => 'Choose a valid billing period.'], 400);
        }
        if (!in_array($duration, ['', '24-hours', '7-days', '30-days'], true)) {
            json_response(['error' => 'Choose a valid product duration.'], 400);
        }
        $status = (string) ($_POST['status'] ?? 'in-stock');
        if (!in_array($status, ['in-stock', 'out-of-stock'], true)) {
            json_response(['error' => 'Choose a valid stock status.'], 400);
        }

        $basePrice = validate_optional_price($_POST['basePrice'] ?? null, 'base price');
        $storePrice = validate_optional_price($_POST['storePrice'] ?? null, 'store price');
        if ($basePrice === null && $storePrice === null) {
            json_response(['error' => 'Enter a base price or a fixed store price.'], 400);
        }

        $image = store_product_image();
        $product = [
            'id' => bin2hex(random_bytes(16)),
            'name' => $name,
            'category' => $category,
            'status' => $status,
        ];
        if ($basePrice !== null) {
            $product['basePrice'] = $basePrice;
        }
        if ($storePrice !== null) {
            $product['storePrice'] = $storePrice;
        }
        if ($billingPeriod !== '') {
            $product['billingPeriod'] = $billingPeriod;
        }
        if ($duration !== '') {
            $product['duration'] = $duration;
        }
        if ($icon !== '') {
            $product['icon'] = $icon;
        }
        if ($image !== null) {
            $product['image'] = $image;
        }
        $product['description'] = $description;

        try {
            update_products(static function (array $products) use ($product): array {
                $products[] = $product;
                return [$products, null];
            });
        } catch (Throwable $error) {
            remove_product_image($image);
            error_log('Could not save product: ' . $error->getMessage());
            json_response(['error' => 'The product could not be saved. Check that the site data directory is writable.'], 500);
        }

        json_response(['saved' => true, 'product' => $product], 201);
    }

    if ($method === 'PATCH' && $path === '/api/dashboard/products') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            json_response(['error' => 'Choose a valid product to update.'], 400);
        }

        $submitted = request_data();
        $hasStatus = array_key_exists('status', $submitted);
        $hasStorePrice = array_key_exists('storePrice', $submitted);
        $hasHidden = array_key_exists('hidden', $submitted);
        if (!$hasStatus && !$hasStorePrice && !$hasHidden) {
            json_response(['error' => 'Enter a product stock status, visibility, or store price to update.'], 400);
        }

        $status = $submitted['status'] ?? null;
        if ($hasStatus && (!is_string($status) || !in_array($status, ['in-stock', 'out-of-stock'], true))) {
            json_response(['error' => 'Choose a valid stock status.'], 400);
        }
        $storePrice = $hasStorePrice
            ? validate_optional_price($submitted['storePrice'], 'store price')
            : null;
        if ($hasStorePrice && $storePrice === null) {
            json_response(['error' => 'Enter a valid store price.'], 400);
        }
        $hidden = $submitted['hidden'] ?? null;
        if ($hasHidden && !is_bool($hidden)) {
            json_response(['error' => 'Choose a valid product visibility.'], 400);
        }

        $updatedProduct = null;
        try {
            $found = update_products(static function (array $products) use ($id, $hasStatus, $status, $hasStorePrice, $storePrice, $hasHidden, $hidden, &$updatedProduct): array {
                foreach ($products as &$product) {
                    if (is_array($product) && ($product['id'] ?? null) === $id) {
                        if ($hasStatus) {
                            $product['status'] = $status;
                        }
                        if ($hasStorePrice) {
                            $product['storePrice'] = $storePrice;
                        }
                        if ($hasHidden) {
                            $product['hidden'] = $hidden;
                        }
                        $updatedProduct = $product;
                        break;
                    }
                }
                unset($product);

                return [$products, $updatedProduct !== null];
            });
        } catch (Throwable $error) {
            error_log('Could not update product: ' . $error->getMessage());
            json_response(['error' => 'The product could not be updated. Check that the site data directory is writable.'], 500);
        }
        if (!$found || $updatedProduct === null) {
            json_response(['error' => 'Product not found.'], 404);
        }

        json_response(['saved' => true, 'product' => $updatedProduct]);
    }

    if ($method === 'DELETE' && $path === '/api/dashboard/products') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            json_response(['error' => 'Choose a valid product to remove.'], 400);
        }

        $removed = null;
        try {
            $found = update_products(static function (array $products) use ($id, &$removed): array {
                $updated = [];
                foreach ($products as $product) {
                    if (is_array($product) && ($product['id'] ?? null) === $id) {
                        $removed = $product;
                    } else {
                        $updated[] = $product;
                    }
                }
                return [$updated, $removed !== null];
            });
        } catch (Throwable $error) {
            error_log('Could not remove product: ' . $error->getMessage());
            json_response(['error' => 'The product could not be removed. Check that the site data directory is writable.'], 500);
        }
        if (!$found) {
            json_response(['error' => 'Product not found.'], 404);
        }

        if ($removed === null) {
            json_response(['error' => 'Product not found.'], 404);
        }
        remove_product_image($removed['image'] ?? null);
        json_response(['deleted' => true]);
    }

    json_response(['error' => 'Dashboard endpoint not found.'], 404);
}

if ($method === 'GET' && in_array($path, ['/dashboard', '/dashboard.html', '/admin-login', '/admin-login.html', '/account', '/account.html', '/register', '/register.html'], true)) {
    $adminAuthenticated = false;
    try {
        $adminAuthenticated = current_admin_session_is_valid();
    } catch (Throwable $error) {
        error_log('Could not verify admin session for page request: ' . $error->getMessage());
    }
    $dashboardUrl = ($scriptDir !== '' ? $scriptDir : '') . '/dashboard';
    if (str_starts_with($path, '/admin-login') && $adminAuthenticated) {
        header('Location: ' . $dashboardUrl, true, 302);
        exit;
    }

    if (str_starts_with($path, '/register')) {
        $page = 'register.html';
    } elseif (str_starts_with($path, '/account')) {
        $page = 'account.html';
    } else {
        $page = str_starts_with($path, '/admin-login') || !$adminAuthenticated
            ? 'admin-login.html'
            : 'dashboard.html';
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . DIRECTORY_SEPARATOR . $page);
    exit;
}

if ($method === 'GET' && ($path === '/' || $path === '/index.php')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . DIRECTORY_SEPARATOR . 'index.html');
    exit;
}


// ─── Public: GET approved reviews ────────────────────────────────────────────
if ($method === 'GET' && $path === '/api/reviews') {
    try {
        $all = read_reviews();
        $approved = array_values(array_filter($all, static fn ($r) => is_array($r) && ($r['status'] ?? '') === 'approved'));
        json_response($approved);
    } catch (Throwable $error) {
        error_log('Could not load reviews: ' . $error->getMessage());
        json_response(['error' => 'Reviews temporarily unavailable.'], 500);
    }
}

// ─── Public: POST new review (pending) ───────────────────────────────────────
if ($method === 'POST' && $path === '/api/reviews') {
    require_same_origin();

    // multipart/form-data for optional image
    $authorName  = trim((string) ($_POST['authorName'] ?? ''));
    $rating      = (int) ($_POST['rating'] ?? 0);
    $title       = trim((string) ($_POST['title'] ?? ''));
    $body        = trim((string) ($_POST['body'] ?? ''));

    $nameLen = preg_match_all('/./us', $authorName);
    if (!$authorName || $nameLen === false || $nameLen > 80) {
        json_response(['error' => 'Enter your name (up to 80 characters).'], 400);
    }
    if ($rating < 1 || $rating > 5) {
        json_response(['error' => 'Choose a rating between 1 and 5 stars.'], 400);
    }
    $titleLen = preg_match_all('/./us', $title);
    if ($title === '' || $titleLen === false || $titleLen > 120) {
        json_response(['error' => 'Enter a review title (up to 120 characters).'], 400);
    }
    $bodyLen = preg_match_all('/./us', $body);
    if ($body === '' || $bodyLen === false || $bodyLen > 2000) {
        json_response(['error' => 'Enter review text (up to 2,000 characters).'], 400);
    }

    $image = store_review_image();

    $review = [
        'id'         => bin2hex(random_bytes(16)),
        'authorName' => $authorName,
        'rating'     => $rating,
        'title'      => $title,
        'body'       => $body,
        'image'      => $image,
        'status'     => 'pending',
        'createdAt'  => gmdate(DATE_ATOM),
    ];

    try {
        update_reviews(static function (array $reviews) use ($review): array {
            $reviews[] = $review;
            return [$reviews, null];
        });
    } catch (Throwable $error) {
        if ($image !== null) {
            remove_review_image($image);
        }
        error_log('Could not save review: ' . $error->getMessage());
        json_response(['error' => 'Your review could not be saved. Please try again.'], 500);
    }

    json_response(['submitted' => true], 201);
}

// ─── Admin: Reviews management ────────────────────────────────────────────────
if (str_starts_with($path, '/api/dashboard/reviews')) {
    require_admin();

    // GET all reviews (pending + approved + rejected)
    if ($method === 'GET' && $path === '/api/dashboard/reviews') {
        try {
            json_response(array_reverse(read_reviews()));
        } catch (Throwable $error) {
            error_log('Could not load admin reviews: ' . $error->getMessage());
            json_response(['error' => 'Reviews temporarily unavailable.'], 500);
        }
    }

    // PATCH: approve / reject / edit a review
    if ($method === 'PATCH' && $path === '/api/dashboard/reviews') {
        require_same_origin();
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            json_response(['error' => 'Choose a valid review.'], 400);
        }

        $submitted = request_data();
        $updatedReview = null;
        try {
            $found = update_reviews(static function (array $reviews) use ($id, $submitted, &$updatedReview): array {
                foreach ($reviews as &$review) {
                    if (!is_array($review) || ($review['id'] ?? null) !== $id) {
                        continue;
                    }
                    if (array_key_exists('status', $submitted) && in_array($submitted['status'], ['approved', 'rejected', 'pending'], true)) {
                        $review['status'] = $submitted['status'];
                    }
                    if (array_key_exists('title', $submitted) && is_string($submitted['title'])) {
                        $review['title'] = trim($submitted['title']);
                    }
                    if (array_key_exists('body', $submitted) && is_string($submitted['body'])) {
                        $review['body'] = trim($submitted['body']);
                    }
                    if (array_key_exists('rating', $submitted) && is_int($submitted['rating']) && $submitted['rating'] >= 1 && $submitted['rating'] <= 5) {
                        $review['rating'] = $submitted['rating'];
                    }
                    if (array_key_exists('authorName', $submitted) && is_string($submitted['authorName'])) {
                        $review['authorName'] = trim($submitted['authorName']);
                    }
                    $updatedReview = $review;
                    break;
                }
                unset($review);
                return [$reviews, $updatedReview !== null];
            });
        } catch (Throwable $error) {
            error_log('Could not update review: ' . $error->getMessage());
            json_response(['error' => 'The review could not be updated.'], 500);
        }
        if (!$found || $updatedReview === null) {
            json_response(['error' => 'Review not found.'], 404);
        }
        json_response(['saved' => true, 'review' => $updatedReview]);
    }

    // DELETE: remove a review (and its image)
    if ($method === 'DELETE' && $path === '/api/dashboard/reviews') {
        require_same_origin();
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            json_response(['error' => 'Choose a valid review.'], 400);
        }
        $removed = null;
        try {
            $found = update_reviews(static function (array $reviews) use ($id, &$removed): array {
                $updated = [];
                foreach ($reviews as $review) {
                    if (is_array($review) && ($review['id'] ?? null) === $id) {
                        $removed = $review;
                    } else {
                        $updated[] = $review;
                    }
                }
                return [$updated, $removed !== null];
            });
        } catch (Throwable $error) {
            error_log('Could not delete review: ' . $error->getMessage());
            json_response(['error' => 'The review could not be deleted.'], 500);
        }
        if (!$found || $removed === null) {
            json_response(['error' => 'Review not found.'], 404);
        }
        remove_review_image($removed['image'] ?? null);
        json_response(['deleted' => true]);
    }

    // POST: delete only the image of a review
    if ($method === 'POST' && $path === '/api/dashboard/reviews/remove-image') {
        require_same_origin();
        $submitted = request_data();
        $id = $submitted['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            json_response(['error' => 'Choose a valid review.'], 400);
        }
        $oldImage = null;
        $updatedReview = null;
        try {
            $found = update_reviews(static function (array $reviews) use ($id, &$oldImage, &$updatedReview): array {
                foreach ($reviews as &$review) {
                    if (is_array($review) && ($review['id'] ?? null) === $id) {
                        $oldImage = $review['image'] ?? null;
                        $review['image'] = null;
                        $updatedReview = $review;
                        break;
                    }
                }
                unset($review);
                return [$reviews, $updatedReview !== null];
            });
        } catch (Throwable $error) {
            error_log('Could not remove review image: ' . $error->getMessage());
            json_response(['error' => 'The image could not be removed.'], 500);
        }
        if (!$found || $updatedReview === null) {
            json_response(['error' => 'Review not found.'], 404);
        }
        remove_review_image($oldImage);
        json_response(['saved' => true, 'review' => $updatedReview]);
    }

    json_response(['error' => 'Reviews endpoint not found.'], 404);
}

json_response(['error' => 'Not found.'], 404);

