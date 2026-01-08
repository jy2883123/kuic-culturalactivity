<?php
/**
 * Rate Limiting 헬퍼 함수
 * IP 기반 로그인 시도 횟수 제한
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * 로그인 시도 횟수 체크 및 제한
 *
 * @param string $ip_address 클라이언트 IP 주소
 * @param int $max_attempts 최대 시도 횟수 (기본: 5회)
 * @param int $lockout_time 잠금 시간 (초, 기본: 900초 = 15분)
 * @return array ['allowed' => bool, 'remaining' => int, 'reset_time' => int|null]
 */
function check_rate_limit(string $ip_address, int $max_attempts = 5, int $lockout_time = 900): array
{
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }

    $now = time();
    $attempts = &$_SESSION['login_attempts'];

    // IP별 시도 기록이 없으면 초기화
    if (!isset($attempts[$ip_address])) {
        $attempts[$ip_address] = [
            'count' => 0,
            'first_attempt' => $now,
            'locked_until' => null
        ];
    }

    $ip_data = &$attempts[$ip_address];

    // 잠금 시간이 지났으면 리셋
    if ($ip_data['locked_until'] !== null && $now >= $ip_data['locked_until']) {
        $ip_data['count'] = 0;
        $ip_data['first_attempt'] = $now;
        $ip_data['locked_until'] = null;
    }

    // 현재 잠금 상태인지 확인
    if ($ip_data['locked_until'] !== null && $now < $ip_data['locked_until']) {
        return [
            'allowed' => false,
            'remaining' => 0,
            'reset_time' => $ip_data['locked_until'],
            'wait_seconds' => $ip_data['locked_until'] - $now
        ];
    }

    // 시도 횟수 확인
    $remaining = max(0, $max_attempts - $ip_data['count']);

    return [
        'allowed' => $ip_data['count'] < $max_attempts,
        'remaining' => $remaining,
        'reset_time' => null,
        'wait_seconds' => 0
    ];
}

/**
 * 로그인 실패 기록
 *
 * @param string $ip_address 클라이언트 IP 주소
 * @param int $max_attempts 최대 시도 횟수
 * @param int $lockout_time 잠금 시간 (초)
 * @return void
 */
function record_failed_login(string $ip_address, int $max_attempts = 5, int $lockout_time = 900): void
{
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }

    $now = time();
    $attempts = &$_SESSION['login_attempts'];

    if (!isset($attempts[$ip_address])) {
        $attempts[$ip_address] = [
            'count' => 0,
            'first_attempt' => $now,
            'locked_until' => null
        ];
    }

    $ip_data = &$attempts[$ip_address];
    $ip_data['count']++;

    // 최대 시도 횟수 초과 시 잠금
    if ($ip_data['count'] >= $max_attempts) {
        $ip_data['locked_until'] = $now + $lockout_time;
    }
}

/**
 * 로그인 성공 시 시도 횟수 리셋
 *
 * @param string $ip_address 클라이언트 IP 주소
 * @return void
 */
function reset_login_attempts(string $ip_address): void
{
    if (isset($_SESSION['login_attempts'][$ip_address])) {
        unset($_SESSION['login_attempts'][$ip_address]);
    }
}

/**
 * 사용자 친화적인 대기 시간 메시지 생성
 *
 * @param int $seconds 대기 시간 (초)
 * @return string 포맷된 메시지
 */
function format_wait_time(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . '초';
    }

    $minutes = ceil($seconds / 60);
    return $minutes . '분';
}
