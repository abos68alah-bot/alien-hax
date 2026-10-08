<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pathParts = explode('/', trim($path, '/'));
$hasHiddenPath = false;
foreach ($pathParts as $part) {
    if (str_starts_with($part, '.')) {
        $hasHiddenPath = true;
        break;
    }
}

$isPrivateUserFile = preg_match('~^/users\.json(?:\.|$)~', $path) === 1;
$isPrivateOrderFile = preg_match('~^/orders\.json(?:\.|$)~', $path) === 1;
$isPrivateAdminCredentials = preg_match('~^/admin-credentials\.json(?:\.|$)~', $path) === 1;
$isPageFile = in_array(rtrim($path, '/'), ['/dashboard', '/dashboard.html', '/admin-login', '/admin-login.html', '/account', '/account.html', '/register', '/register.html'], true);

if (!$hasHiddenPath && !$isPrivateUserFile && !$isPrivateOrderFile && !$isPrivateAdminCredentials && !$isPageFile && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/index.php';

