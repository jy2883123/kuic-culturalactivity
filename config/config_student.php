<?php
/**
 * 학생 전용 설정 파일
 * 학생 페이지에서 사용하는 전역 설정 및 세션 검증
 */

// 기본 설정 파일 로드
require_once __DIR__ . '/config.php';

// 학생 로그인 여부 확인
if (empty($_SESSION['logged_in'])) {
    header('Location: /index.php');
    exit();
}

// 세션에서 학생 정보 가져오기 (전역 변수로 사용 가능)
$student_name = $_SESSION['student_name'] ?? 'Student';
$student_id = $_SESSION['student_id'] ?? 'N/A';
$institution_role = $_SESSION['institution_role'] ?? 'N/A';
