<?php
/**
 * CSRF Token helpers
 * 저장소: $_SESSION['csrf_tokens'][token_name => ['value' => ..., 'expires_at' => ...]]
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const CSRF_TTL_SECONDS = 1800; // 30 minutes

function csrf_generate_token(string $token_name = '_csrf'): string
{
    if (!isset($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    $token_value = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$token_name] = [
        'value' => $token_value,
        'expires_at' => time() + CSRF_TTL_SECONDS
    ];

    return $token_value;
}

function csrf_get_token(string $token_name = '_csrf'): string
{
    if (
        isset($_SESSION['csrf_tokens'][$token_name]['value']) &&
        ($_SESSION['csrf_tokens'][$token_name]['expires_at'] ?? 0) > time()
    ) {
        return $_SESSION['csrf_tokens'][$token_name]['value'];
    }
    return csrf_generate_token($token_name);
}

function csrf_validate_token(string $token, string $token_name = '_csrf'): bool
{
    if (!isset($_SESSION['csrf_tokens'][$token_name])) {
        return false;
    }

    $stored = $_SESSION['csrf_tokens'][$token_name];
    $is_valid = hash_equals($stored['value'], $token) && ($stored['expires_at'] ?? 0) > time();

    if ($is_valid) {
        unset($_SESSION['csrf_tokens'][$token_name]);
    }

    return $is_valid;
}
