# Cultural Activity Portal

KU ISC/IWC 문화체험 활동을 위한 PHP 기반 포털입니다. 학생 신청, 관리자 관리,
체크인, 공지 기능을 포함합니다.

## 요구 사항

- PHP 8.x (PDO MySQL 확장 필요)
- MySQL/MariaDB
- 웹 서버 (Apache/Nginx) 또는 PHP 내장 서버

## 설정

1) 의존성 설치 (이 레포는 Composer 패키지 없음)
2) DB 스키마 생성:
   - `database.sql`을 기준으로 필요한 테이블 생성
3) 환경변수 설정:
   - `.env.example`을 `.env`로 복사 후 값 입력

```bash
cp .env.example .env
```

## 환경 변수

```text
# Dev/Test login
ENABLE_DEV_LOGIN=false
DEV_LOGIN_SECRET=

# Portal DB
PORTAL_DB_HOST=
PORTAL_DB_PORT=3306
PORTAL_DB_NAME=portal
PORTAL_DB_USER=
PORTAL_DB_PASS=

# UwaySync DB
UWAYSYNC_DB_HOST=
UWAYSYNC_DB_PORT=3306
UWAYSYNC_DB_NAME=UwaySync
UWAYSYNC_DB_USER=
UWAYSYNC_DB_PASS=
```

## 프로젝트 구조

- `config/` - 앱 설정, 세션/보안 설정
- `functions/` - 공통 헬퍼 (CSRF, 레이트리밋, IP 처리 등)
- `handler/` - 로그인 핸들러
- `public/` - 웹 루트 (학생/관리자 UI 및 핸들러)

## 로컬 실행 (선택)

```bash
php -S localhost:8000 -t public
```

브라우저에서 `http://localhost:8000` 접속.
