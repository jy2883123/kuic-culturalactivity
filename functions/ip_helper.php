<?php
/**
 * IP 주소 추출 및 검증 헬퍼 함수
 * Cloudflare, 프록시, 직접 연결 모두 지원
 */

/**
 * 클라이언트의 실제 IP 주소를 추출합니다.
 * Cloudflare → 일반 프록시 → 직접 연결 순서로 우선순위 적용
 *
 * @return string|null 검증된 IP 주소 또는 null
 */
function get_client_ip(): ?string
{
    $ip = null;

    // 1. Cloudflare IP (최우선)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    // 2. X-Real-IP 헤더
    elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    // 3. X-Forwarded-For 헤더 (프록시 체인)
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'];
        // 첫 번째 IP만 추출 (실제 클라이언트 IP)
        $ip = strpos($forwarded, ',') !== false
            ? trim(explode(',', $forwarded)[0])
            : $forwarded;
    }
    // 4. 직접 연결
    else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    // IP 주소 검증 (유효하지 않으면 null 반환)
    if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP)) {
        error_log("Invalid IP address detected: {$ip}");
        return null;
    }

    return $ip;
}

/**
 * 클라이언트 IP를 가져오되, 실패 시 기본값 반환
 *
 * @param string $default 기본값 (기본: '0.0.0.0')
 * @return string 검증된 IP 주소 또는 기본값
 */
function get_client_ip_safe(string $default = '0.0.0.0'): string
{
    return get_client_ip() ?? $default;
}
