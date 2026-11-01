# 📱 GetNumber — Enterprise Virtual Number Platform & Real-Time Messaging Gateway

<p align="center">
  <b>A self-hosted, offline-ready virtual communication platform engineered for high-concurrency number provisioning, real-time threaded messaging, unified developer APIs, and integrated cryptocurrency payments.</b>
</p>

<p align="center">
  <a href="#-english-version">🇬🇧 English Version</a> •
  <a href="#-نسخه-فارسی">🇮🇷 نسخه فارسی</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-13.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 13.x">
  <img src="https://img.shields.io/badge/Inertia.js-v2.0-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia.js v2">
  <img src="https://img.shields.io/badge/Vue.js-3.4%2B-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3">
  <img src="https://img.shields.io/badge/TypeScript-5.6%2B-3178C6?style=for-the-badge&logo=typescript&logoColor=white" alt="TypeScript 5.6">
  <img src="https://img.shields.io/badge/TailwindCSS-v3%2Fv4-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS">
  <img src="https://img.shields.io/badge/WebSockets-Laravel%20Reverb-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel Reverb">
  <img src="https://img.shields.io/badge/Payments-TRON%20%2F%20Nobitex-00A651?style=for-the-badge&logo=tether&logoColor=white" alt="Crypto Payments">
  <img src="https://img.shields.io/badge/Tests-180%2B%20Passing-success?style=for-the-badge&logo=phpunit&logoColor=white" alt="PHPUnit Tests">
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-GNU%20GPLv3-00599C?style=for-the-badge&logo=gnu" alt="GPL-3.0 License"></a>
  <a href="https://github.com/shanaqisaeed/GetNumber"><img src="https://img.shields.io/badge/Author-Saeed%20Shanaqi-181717?style=for-the-badge&logo=github&logoColor=white" alt="Author"></a>
</p>

---

## 🌐 Language Switcher / انتخاب زبان

- [🇬🇧 English Documentation](#-english-version)
- [🇮🇷 مستندات و راهنمای فارسی](#-نسخه-فارسی)

---

# 🇬🇧 English Version

## 📖 Table of Contents
1. [Overview & Practical Purpose](#1-overview--practical-purpose)
2. [What Makes GetNumber Different?](#2-what-makes-getnumber-different)
3. [Core Feature Breakdown](#3-core-feature-breakdown)
   - [Virtual Number Pool & Allocation Engine](#31-virtual-number-pool--allocation-engine)
   - [Two-Way Threaded Messaging Hub](#32-two-way-threaded-messaging-hub)
   - [Real-Time WebSockets with Reverb & Echo](#33-real-time-websockets-with-reverb--echo)
   - [Internal Wallet & Crypto Payment Engine](#34-internal-wallet--crypto-payment-engine)
   - [Unified RESTful Developer API (v1)](#35-unified-restful-developer-api-v1)
   - [Enterprise Support Ticket Desk](#36-enterprise-support-ticket-desk)
   - [Zero-CDN & High-Security Intranet Readiness](#37-zero-cdn--high-security-intranet-readiness)
4. [System Architecture & Data Model](#4-system-architecture--data-model)
5. [User & Administrator Workflows](#5-user--administrator-workflows)
6. [Developer API Quickstart](#6-developer-api-quickstart)
7. [Installation & Getting Started](#7-installation--getting-started)
8. [Background Workers & Automation](#8-background-workers--automation)
9. [Automated Testing Suite](#9-automated-testing-suite)
10. [License & Intellectual Property](#10-license--intellectual-property)

---

### 1. Overview & Practical Purpose

**GetNumber** is an enterprise-grade, self-hosted communication platform built on **Laravel 13**, **Inertia.js v2**, and **Vue 3 (TypeScript)**. It bridges the gap between simulated virtual phone networks, private corporate intranets, and programmable SMS gateways.

In real-world operations, organizations often face severe constraints:
- Public telecom providers impose heavy recurring subscription costs, rigid KYC checks, and vendor lock-in.
- Private networks, banking environments, government infrastructure, and development staging environments cannot depend on external SMS gateways or third-party cloud CDNs.
- Modern software teams need a centralized, auditable platform to issue virtual phone numbers to users, exchange two-way messages, run automated end-to-end integration tests, and monetize communication services with cryptocurrency billing.

**GetNumber solves these challenges out of the box.** It provides a production-ready web application and API where users can recharge their internal wallets with cryptocurrency, claim virtual numbers from pre-configured pools, send and receive messages in a mobile-style chat UI in real time, and integrate third-party applications through scoped API tokens.

---

### 2. What Makes GetNumber Different?

| Requirement | Traditional SMS Gateways / Cloud SaaS | GetNumber Self-Hosted Platform |
| :--- | :--- | :--- |
| **Hosting & Isolation** | Hosted on external clouds; blocked by strict firewalls | **100% On-Premise & Intranet Ready** with zero external CDN dependencies |
| **Asset Delivery** | Dependent on Google Fonts, cdnjs, Cloudflare | **All assets bundled locally** (Vazirmatn fonts, local QR codes, bundled scripts) |
| **Concurrency & Racing** | Prone to duplicate assignment under high load | **Pessimistic row locking (`lockForUpdate`)** in database transactions |
| **Billing & Payments** | Credit cards or bank webhooks with geographical limits | **Native Crypto Engine** (TRON TRX/USDT, Nobitex exchange, Wallex, TronScan) |
| **Real-Time Communication** | High-latency polling or costly third-party Pusher plans | **Native Laravel Reverb WebSockets** with graceful auto-fallback to polling |
| **Privacy & GDPR** | Message history often remains tied to recycled numbers | **Atomic privacy scrub**: Releasing a line permanently deletes previous messages |
| **Authentication** | Tied to email servers and OTP email verification | **Username-centric auth**: Operates without any external mail server dependency |

---

### 3. Core Feature Breakdown

#### 3.1. Virtual Number Pool & Allocation Engine
- **Standard Numbers Pool:** Supports fixed 9-digit lines (`000000000` to `999999999`) with zero-padded formatting preservation, assigned to dynamic system prefixes (default `+85`).
- **Dynamic Prefix Management:** Admins can define, activate, or deactivate prefixes (e.g., `+85`, `+98`, `+44`) and choose the default routing prefix. Prefixes linked to active numbers are protected against accidental deletion.
- **Atomic Concurrency Guarantee:** Number allocation in `PhoneNumberAllocationService` utilizes strict database transactions wrapped in `lockForUpdate`. Simultaneous purchase requests never result in double allocation.
- **Custom Service Numbers (4 to 8 Digits):** Users can request custom short vanity numbers for OTP, billing alerts, or department gateways. Admins review requests with approval, rejection notes, or manual line assignment.
- **Platform System Lines:** Exclusive administrative numbers (such as `1000` or `9999`) with customizable `accepts_incoming_sms` policy, allowing broadcast-only or interactive system messaging.
- **Automated Lifecycle Sweeper:** The artisan command `php artisan numbers:sweep-expired` cleans up expired reservations and allocations automatically on an hourly cron schedule.

#### 3.2. Two-Way Threaded Messaging Hub
- **Mobile-Style Conversation Threads:** Messages are organized into responsive chat threads by `peer_number`, offering a familiar messenger experience.
- **Multi-SIM Switching:** Users with multiple assigned virtual lines can toggle between their numbers seamlessly without reloading the interface.
- **Multi-Recipient Chip Selector:** Send outbound SMS to single or multiple destination lines simultaneously using smart tag chips.
- **Admin Conversation Inspector:** Provides oversight through two complementary views: an interactive chat view with directional bubbles and line ownership metadata, and a tabular audit grid for technical compliance.
- **System Line Spoofing Prevention:** Senders cannot spoof sender titles (`display_title`). Titles are strictly verified and enforced by server-side policy.

#### 3.3. Real-Time WebSockets with Reverb & Echo
- **Instant Delivery & Read Receipts:** Powered by **Laravel Reverb** and **Laravel Echo**, inbound and outbound messages update live on the screen without manual refresh.
- **Two-Ticks Message Delivery Status:** Real-time events broadcast delivery and read state changes directly to the active chat thread.
- **Adaptive Resilience Engine:** If WebSockets become unavailable due to corporate proxy policies or connection loss, the frontend (`resources/js/echo.ts`) automatically falls back to 4-second polling coordinated with the `Page Visibility API` (pausing network activity in hidden tabs).

#### 3.4. Internal Wallet & Crypto Payment Engine
- **Immutable Balance Ledger:** User balances are tracked with an integer-based coin ledger (`wallets` and `wallet_transactions`) with strict idempotency guards against duplicate credits.
- **TRON Blockchain Integration:** Supports direct TRX and TRC20 token top-ups with 1:1 coin conversion rates.
- **Multi-Exchange Gateway Adapters:**
  - **Nobitex Gateway:** Native integration with the Nobitex exchange API (`apiv2.nobitex.ir`) featuring **Ed25519 signature authentication** (`sodium_crypto_sign_seed_keypair`), IP whitelist restriction detection, automatic TRX deposit address sync, and manual deposit address override support.
  - **Wallex & TronScan & TronGrid:** Pluggable gateway adapters for direct blockchain queries and alternative exchanges.
  - **Mock Sandbox Driver:** Built-in test driver for automated local testing without touching real crypto networks.
- **100% Offline Client-Side QR Codes:** Deposit addresses are rendered into interactive QR codes on the client using the local `qrcode` bundle—no external Google Charts or third-party image APIs required.
- **3-Stage Asynchronous Verification:** When a user submits a Transaction Hash (TX Hash), `VerifyCryptoDepositJob` runs instant checks, schedules delayed re-checks, and escalates to manual review if unconfirmed after 3 attempts.
- **Admin Payment Oversight:** Admin panel to inspect pending deposits, verify network states, view TX Hashes, trigger manual completion with audit logging, or safely reject invalid deposits.

#### 3.5. Unified RESTful Developer API (v1)
- **Token-Based Authentication:** API tokens are stored using `SHA-256` one-way hashing. The plain-text key is revealed only once upon creation or rotation.
- **Granular Scopes & Abilities:** Control token privileges (`messages:send`, `messages:write`, `numbers:read`, `admin:full`).
- **Single & Bulk Outbound SMS:** Send messages to single or multiple destination numbers via `POST /api/v1/messages`.
- **Atomic Inbound Webhook Ingestion:** Ingest incoming messages via `POST /api/v1/messages/inbound` protected by a unique composite index `(source_system_id, client_message_id)` to prevent duplicate entries caused by network re-transmissions.
- **Interactive In-App Documentation:** Full interactive developer guide hosted at `/docs/api` with live code examples.

#### 3.6. Enterprise Support Ticket Desk
- **Full Support Ticketing Workflow:** Integrated ticket management with atomic tracking numbers (`TK-XXXXX`).
- **Department & Priority Routing:** Categorized by departments (`technical`, `numbers`, `api`, `general`) and urgency levels (`low`, `medium`, `high`, `urgent`).
- **Staff Agent Role (`agent`):** Dedicated agent dashboard allowing customer support staff to respond, assign tickets to specialists, and track resolution lifecycles.
- **Private Streamed File Attachments:** Ticket attachments are strictly isolated from the public web root (`storage/app/private/tickets/...`). Files are streamed securely only after server-side policy verification.

#### 3.7. Zero-CDN & High-Security Intranet Readiness
- **Zero External CDN Dependencies:** All CSS, fonts, and scripts are locally compiled. Uses `@fontsource/vazirmatn` for typography and local Vite bundles.
- **Hardened Security Headers:** Enforces Content Security Policy (CSP), Strict-Transport-Security (HSTS), X-Frame-Options (DENY), and X-Content-Type-Options (nosniff) on both web and API responses.
- **Comprehensive Audit Trail:** All administrative adjustments, token revocations, line assignments, and financial operations are permanently recorded in `audit_logs` with IP addresses and JSON request payloads.

---

### 4. System Architecture & Data Model

```mermaid
erDiagram
    USERS ||--o{ WALLETS : "owns"
    WALLETS ||--o{ WALLET_TRANSACTIONS : "records"
    USERS ||--o{ CRYPTO_DEPOSITS : "initiates"
    PAYMENT_GATEWAYS ||--o{ CRYPTO_DEPOSITS : "processes"
    
    USERS ||--o{ PHONE_NUMBERS : "owns"
    USERS ||--o{ NUMBER_REQUESTS : "submits"
    USERS ||--o{ API_TOKENS : "issues"
    USERS ||--o{ AUDIT_LOGS : "triggers"
    USERS ||--o{ TICKETS : "opens"
    USERS ||--o{ TICKET_MESSAGES : "authors"
    
    PHONE_PREFIXES ||--o{ PHONE_NUMBERS : "prefixes"
    PHONE_NUMBERS ||--o{ MESSAGES : "sends_or_receives"
    PHONE_NUMBERS ||--o| NUMBER_REQUESTS : "allocated_to"
    
    TICKETS ||--|{ TICKET_MESSAGES : "contains"
    TICKET_MESSAGES ||--o{ TICKET_ATTACHMENTS : "encloses"

    USERS {
        bigint id PK
        string username UK "Unique username login"
        string name "Full Name"
        string role "admin | agent | user"
        string status "active | suspended | inactive"
    }

    WALLETS {
        bigint id PK
        bigint user_id FK
        decimal balance "Current usable coin balance"
    }

    CRYPTO_DEPOSITS {
        bigint id PK
        bigint user_id FK
        string tracking_code UK
        string currency "TRX | USDT"
        string network "TRON | TRC20"
        decimal requested_amount
        string deposit_address
        string tx_hash UK
        string status "pending_transfer | verifying | completed | failed"
    }

    PHONE_NUMBERS {
        bigint id PK
        string number UK "4 to 9 digits with preserved zeros"
        string type "user_standard | user_service | platform_service"
        string status "available | allocated | reserved | suspended"
        bigint user_id FK "nullable"
        boolean accepts_incoming_sms
    }

    MESSAGES {
        bigint id PK
        bigint phone_number_id FK
        string destination_number
        string sender
        text body
        string direction "inbound | outbound"
        string client_message_id "Idempotency key"
        string delivery_status "sent | delivered | read"
    }
```

---

### 5. User & Administrator Workflows

#### 👤 End-User Experience
1. **Sign In:** Fast authentication with a unique `username` (no email activation roadblocks).
2. **Top-Up Wallet:** Navigate to Profile / Wallet, enter a TRX amount, and view an offline QR code with the dedicated deposit address. Transfer funds and submit the transaction hash (TX Hash).
3. **Provision a Number:** Select an instant 9-digit number from the available pool or submit a request for a custom 4–8 digit vanity number.
4. **Chat & Text:** Access the messaging dashboard, choose your active virtual number (Multi-SIM), select recipients, and exchange messages in real time.
5. **Developer Integration:** Generate SHA-256 protected API keys with scoped capabilities to automate communications from external systems.
6. **Support Tickets:** Open tickets with file attachments directly to the support desk whenever assistance is needed.

#### 🛡️ Administrator & Staff Experience
1. **System Health Metrics:** Real-time dashboard tracking active users, allocated numbers, gateway status, and daily messaging volume.
2. **Number & Prefix Management:** Generate batches of numbers with leading zeroes preserved, configure dynamic dial codes, and manage allocation quotas.
3. **Number Request Approvals:** Review user requests for custom service numbers, evaluate business justifications, and approve or reject with reasons.
4. **Payment Gateways & Nobitex Sync:** Configure API keys and Ed25519 secrets, trigger automated TRX wallet address discovery, and review flagged crypto deposits.
5. **Support Agent Desk:** Triage tickets by urgency, assign tickets to dedicated specialists, and respond securely.
6. **Security & Audit Logs:** Inspect searchable audit logs with caller IP addresses and JSON change payloads for full regulatory compliance.

---

### 6. Developer API Quickstart

All API requests must include your Bearer token in the `Authorization` header:

```http
Authorization: Bearer gn_live_your_api_token_here
Accept: application/json
Content-Type: application/json
```

#### Outbound SMS (Single Recipient)
```bash
curl -X POST "http://localhost:8000/api/v1/messages" \
  -H "Authorization: Bearer gn_live_abcdef123456" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "000100200",
    "to": "+85000100201",
    "message": "Your verification code is: 481902",
    "client_message_id": "TX-REQ-98120"
  }'
```

#### Outbound SMS (Bulk Dispatch)
```bash
curl -X POST "http://localhost:8000/api/v1/messages" \
  -H "Authorization: Bearer gn_live_abcdef123456" \
  -H "Content-Type: application/json" \
  -d '{
    "sender": "000100200",
    "destinations": ["+85000100201", "+85000100202", "+85000100203"],
    "body": "System update scheduled tonight at 02:00 UTC.",
    "client_message_id": "BROADCAST-2026-11"
  }'
```

#### Inbound Webhook Reception (Atomic Ingestion)
```bash
curl -X POST "http://localhost:8000/api/v1/messages/inbound" \
  -H "Authorization: Bearer gn_live_abcdef123456" \
  -H "Content-Type: application/json" \
  -d '{
    "destination_number": "+85000100200",
    "sender": "+989120000000",
    "body": "Thank you, registration confirmed.",
    "source_system_id": "TELCO_GATEWAY_MAIN",
    "client_message_id": "HOOK_UUID_772183"
  }'
```

#### Incremental Message Polling
```bash
curl -X GET "http://localhost:8000/api/v1/messages/poll?since_id=1400" \
  -H "Authorization: Bearer gn_live_abcdef123456"
```

---

### 7. Installation & Getting Started

#### System Prerequisites
- **PHP:** `8.3` or higher (extensions: `bcmath`, `curl`, `mbstring`, `openssl`, `pdo_mysql` or `pdo_sqlite`, `sodium`, `xml`, `zip`)
- **Node.js:** `20.x` or `22.x LTS` & `npm 10.x`
- **Composer:** `2.8` or higher
- **Database:** MariaDB 10.4+, MySQL 8.0+, or SQLite 3

#### Step-by-Step Installation

```bash
# 1. Clone repository
git clone https://github.com/shanaqisaeed/GetNumber.git
cd GetNumber

# 2. Install PHP dependencies
composer install --optimize-autoloader

# 3. Setup environment configuration
cp .env.example .env
php artisan key:generate

# 4. Run database migrations & seeders
php artisan migrate --force
php artisan db:seed --force

# 5. Install frontend dependencies & compile production build
npm install
npm run build

# 6. Start the local server
php artisan serve
```

#### Running Real-Time Messaging & Background Workers
For live WebSocket messaging and async queue processing, launch Reverb and the queue listener in separate terminal tabs:

```bash
# Start Laravel Reverb WebSocket Server
php artisan reverb:start

# Start Queue Worker for Crypto Verification and Jobs
php artisan queue:listen --tries=3
```

#### Default Seeded Accounts

| Username | Role | Password | Access Scope |
| :---: | :---: | :---: | :--- |
| `admin` | **System Administrator** | `Admin@123456` | Full system control: Users, Numbers, Prefixes, Gateways, Logs |
| `user` | **Standard Enterprise User** | `User@123456` | Number allocation, Chat UI, Wallet recharge, API tokens |

---

### 8. Background Workers & Automation

To automate line expiration sweeps, pending deposit checks, and gateway address syncing, add the following cron entry on your server:

```bash
* * * * * cd /path-to-project/GetNumber && php artisan schedule:run >> /dev/null 2>&1
```

Registered Scheduled Tasks:
- `numbers:sweep-expired`: Runs hourly to release expired lines and sanitize previous message logs.
- `payments:verify-pending`: Runs every minute to probe blockchain confirmations for pending crypto deposits.
- `payments:nobitex:sync-wallet`: Runs daily to validate API credentials and synchronize deposit addresses.

---

### 9. Automated Testing Suite

GetNumber maintains an automated test suite with **over 180 tests** covering unit, feature, security, concurrency, and payment flows:

```bash
# Run all automated tests
php artisan test

# Run tests with compact summary
php artisan test --compact
```

Test coverage includes:
- Pessimistic locking and race-condition prevention during number allocation.
- Inbound webhook idempotency via `(source_system_id, client_message_id)` keys.
- Ed25519 signature verification and Nobitex gateway IP whitelist detection.
- Wallet balance ledger integrity, atomic purchases, and automatic refunds on allocation failure.
- WebSocket authorization channels and two-way broadcast dispatches.
- Strict security headers across web and API endpoints.

---

### 10. License & Intellectual Property

This project is licensed under the **[GNU General Public License v3.0 (GPL-3.0)](LICENSE)**.

```text
Copyright (C) 2026 Saeed Shanaqi (https://github.com/shanaqisaeed/GetNumber)
All Rights Reserved under GNU General Public License v3.0.
```

- **Attribution & Integrity:** The original author attribution (`Saeed Shanaqi`) and repository links must remain intact in all source files, documentation, and user interfaces.
- **Copyleft Guarantee:** Any modification or derivative work must be distributed under the same GPLv3 license with full source code availability.

---

# 🇮🇷 نسخه فارسی

## 📑 فهرست مطالب
1. [معرفی و هدف کاربردی سامانه](#۱-معرفی-و-هدف-کاربردی-سامانه)
2. [چرا گت‌نامبر متمایز است؟](#۲-چرا-گت‌نامبر-متمایز-است؟)
3. [بررسی قابلیت‌های کلیدی سیستم](#۳-بررسی-قابلیت‌های-کلیدی-سیستم)
   - [موتور تخصیص و چرخه حیات شماره‌ها](#۳۱-موتور-تخصیص-و-چرخه-حیات-شماره‌ها)
   - [هاب پیام‌رسانی شبه‌موبایل و گفتگومحور](#۳۲-هاب-پیام‌رسانی-شبه‌موبایل-و-گفتگومحور)
   - [وب‌سوکت آنی با روِرب و اکو](#۳۳-وب‌سوکت-آنی-با-روِرب-و-اکو)
   - [کیف پول داخلی و درگاه پرداخت کریپتو](#۳۴-کیف-پول-داخلی-و-درگاه-پرداخت-کریپتو)
   - [وب‌سرویس یکپارچه توسعه‌دهندگان](#۳۵-وب‌سرویس-یکپارچه-توسعه‌دهندگان)
   - [میز خدمت و تیکتینگ سازمانی](#۳۶-میز-خدمت-و-تیکتینگ-سازمانی)
   - [استقلال کامل از سی‌دی‌ان و امنیت اینترانت](#۳۷-استقلال-کامل-از-سی‌دی‌ان-و-امنیت-اینترانت)
4. [معماری سیستم و مدل داده‌ها](#۴-معماری-سیستم-و-مدل-داده‌ها)
5. [سناریوهای کاربری و مدیریتی](#۵-سناریوهای-کاربری-و-مدیریتی)
6. [راهنمای سریع وب‌سرویس برای برنامه‌نویسان](#۶-راهنمای-سریع-وب‌سرویس-برای-برنامه‌نویسان)
7. [راهنمای راه‌اندازی و نصب گام‌به‌گام](#۷-راهنمای-راه‌اندازی-و-نصب-گام‌به‌گام)
8. [پردازش‌های پس‌زمینه و زمان‌بندی](#۸-پردازش‌های-پس‌زمینه-و-زمان‌بندی)
9. [تضمین کیفیت و آزمون‌های خودکار](#۹-تضمین-کیفیت-و-آزمون‌های-خودکار)
10. [مجوز و حقوق مالکیت معنوی](#۱۰-مجوز-و-حقوق-مالکیت-معنوی)

---

### ۱. معرفی و هدف کاربردی سامانه

پلتفرم **GetNumber** یک سامانه جامع، سلف‌هاستد و آماده استقرار سازمانی است که بر پایه **Laravel 13**، **Inertia.js v2** و **Vue 3 (TypeScript)** توسعه یافته است. این سیستم به منظور مدیریت استخر شماره‌های مجازی، برقراری ارتباطات متنی دوطرفه، ارائه وب‌سرویس به سامانه‌های جانبی و مدیریت تراکنش‌های مالی با ارزهای دیجیتال طراحی شده است.

در پروژه‌های واقعی و سازمان‌ها چالش‌های زیر همواره وجود دارد:
- هزینه‌های سنگین اپراتورهای عمومی مخابراتی، احراز هویت‌های دست‌وپاگیر و وابستگی به سرویس‌های متمرکز خارجی.
- محدودیت شبکه‌های ایزوله بانکی، دولتی، دیتاسنترهای داخلی و شبکه ملی اطلاعات در استفاده از سرویس‌ها و فونت‌های تحت وب خارجی (`CDN`).
- نیاز تیم‌های نرم‌افزاری به بستری پایدار جهت تست یکپارچه سناریوهای پیامکی، ایجاد خطوط خدمات مشتریان، صدور شماره‌های اختصاصی و شارژ کیف پول با کریپتو.

سامانه GetNumber تمامی این نیازها را در قالب یک نرم‌افزار منسجم برطرف می‌کند. کاربران می‌توانند موجودی حساب خود را با ارز دیجیتال شارژ کنند، خطوط مورد نظر را تحویل بگیرند، به صورت زنده در محیطی مشابه پیام‌رسان‌های موبایل چت کنند و با توکن‌های امن، سامانه را به نرم‌افزارهای خود متصل نمایند.

---

### ۲. چرا گت‌نامبر متمایز است؟

| شاخص ارزیابی | سرویس‌های رایج ابری | سامانه بومی و مستقل GetNumber |
| :--- | :--- | :--- |
| **محیط میزبانی** | وابسته به ابر خارجی و در معرض تحریم یا قطع ارتباط | **۱۰۰٪ سلف‌هاستد و قابل استقرار در اینترانت** بدون هیچ وابستگی بیرونی |
| **بارگذاری منابع فرانت‌اند** | وابستگی به گوگل، کلودفلر یا سی‌دی‌ان‌های خارجی | **باندل ۱۰۰٪ محلی تمام اسکریپت‌ها**، کیوآرکد آفلاین و فونت متن‌باز وزیزمتن |
| **کنترل همزمانی در تخصیص** | خطای واگذاری همزمان یک خط در ترافیک‌های لحظه‌ای | **قفل سطری بدبینانه (`lockForUpdate`)** درون تراکنش‌های پایگاه‌داده |
| **سیستم مالی و شارژ** | نیازمند درگاه بانکی سنتی با محدودیت جغرافیایی | **موتور پرداخت رمزارز** (پشتیبانی ترون TRX، تتر، نوبیتکس، والکس و ترون‌اسکن) |
| **تبادل داده در فرانت‌اند** | پولینگ‌های مداوم یا اشتراک‌های پرهزینه پوشر | **وب‌سوکت آنی مبتنی بر Laravel Reverb** به همراه بازگشت هوشمند به پولینگ |
| **حریم خصوصی شماره‌های آزادشده** | باقی‌ماندن سوابق پیام‌ها برای کاربر جدید خط | **پاک‌سازی اتمیک پیام‌های پیشین** در لحظه بازیافت یا حذف شماره |
| **مکانیزم احراز هویت** | وابسته به سرور ایمیل خارجی جهت فعال‌سازی | **احراز هویت کاربری با شناسه یکتا (`username`)** بدون ریسک افشای ایمیل |

---

### ۳. بررسی قابلیت‌های کلیدی سیستم

#### ۳.۱. موتور تخصیص و چرخه حیات شماره‌ها
- **استخر شماره‌های استاندارد:** پشتیبانی از خطوط ۹ رقمی (از `000000000` تا `999999999`) با حفظ صفرهای اول و الحاق به پیشوندهای سازمانی فعال (پیش‌فرض `+85`).
- **مدیریت داینامیک پیشوندها:** امکان تعریف و مدیریت کدهای مخابراتی مختلف با امکان تعیین پیشوند پیش‌فرض و جلوگیری سیستمی از حذف کدهای دارای شماره فعال.
- **تخصیص اتمیک و ضد همزمانی:** لایه سرویس `PhoneNumberAllocationService` با بهره‌گیری از قفل سطری بدبینانه (`Pessimistic Locking`) تضمین می‌کند هیچ شماره‌ای حتی در صدم ثانیه به دو کاربر متفاوت واگذار نشود.
- **سرشماره‌های اختصاصی (۴ تا ۸ رقم):** مشترکین می‌توانند سرشماره‌های رند یا کوتاه دلخواه خود را درخواست دهند تا پس از بررسی اداری توسط مدیران، خط موردنظر تخصیص داده شود.
- **خطوط انحصاری پلتفرم:** سرشماره‌های سیستمی ویژه راهبران (مانند `1000` یا `9999`) با سیاست قابل تنظیم یک‌طرفه یا دوطرفه (`accepts_incoming_sms`).
- **رفت‌وروب خودکار خطوط:** اجرای دوره‌ای دستور `php artisan numbers:sweep-expired` در پس‌زمینه جهت آزادسازی شماره‌های منقضی‌شده و پاک‌سازی پیام‌ها برای تضمین حریم خصوصی مالک جدید.

#### ۳.۲. هاب پیام‌رسانی شبه‌موبایل و گفتگومحور
- **رشته‌گفتگوهای ساختاریافته:** گروه‌بندی پیام‌ها بر اساس شماره مخاطب (`peer_number`) جهت ارائه محیطی مشابه اپلیکیشن‌های موبایل.
- **سوییچ سریع میان خطوط (Multi-SIM):** امکان جابه‌جایی سریع و بدون نیاز به رفرش صفحه بین خطوط مختلف تحت مالکیت کاربر.
- **ارسال چندگانه هوشمند:** ارسال پیامک تکی یا انبوه به مخاطبان از طریق فیلد انتخاب چیپ‌های تعاملی.
- **بازرس مکالمات برای مدیران:** مانیتورینگ متمرکز گفتگوها در دو حالت «حباب‌های چت با نمایش جزئیات کامل طرفین» و «جدول داده‌های خام»، به همراه قابلیت ارسال پاسخ سازمانی.
- **جلوگیری از جعل عنوان:** عنوان فرستنده پیامک (`display_title`) منحصراً از سرور خوانده شده و کاربر امکان دستکاری آن را نخواهد داشت.

#### ۳.۳. وب‌سوکت آنی با روِرب و اکو
- **تحویل بلادرنگ پیام‌ها:** یکپارچگی کامل با **Laravel Reverb** و کلاینت **Laravel Echo** جهت نمایش آنی پیام‌های ورودی و خروجی بدون رفرش صفحه.
- **نمایش وضعیت تحویل دوخطی (تیک دوم):** به‌روزرسانی لحظه‌ای وضعیت خوانده شدن و تحویل پیام در محیط گفتگو.
- **انعطاف‌پذیری و بازگشت خودکار:** چنانچه پورت‌های وب‌سوکت به دلیل فایروال مسدود شوند، کلاینت (`resources/js/echo.ts`) سامانه را بدون وقفه به حالت پولینگ هر ۴ ثانیه (هماهنگ با `Page Visibility API` برای توقف در تب‌های پس‌زمینه) منتقل می‌کند.

#### ۳.۴. کیف پول داخلی و درگاه پرداخت کریپتو
- **دفترکل اتمیک دارایی:** ثبت موجودی حساب در قالب واحد اعتباری با جدول تراکنش‌های تفکیک‌شده و کلید یکتایی جهت جلوگیری قطعی از شارژ تکراری.
- **شبکه ترون (TRON):** امکان افزایش اعتبار با استفاده از رمزارز TRX و تتر TRC20 با نرخ تبدیل مستقیم.
- **درایورهای چندگانه صرافی و شبکه:**
  - **درگاه صرافی نوبیتکس (Nobitex):** اتصال مستقیم به وب‌سرویس نوبیتکس (`apiv2.nobitex.ir`) با پشتیبانی از **امضای دیجیتال Ed25519**، تشخیص محدودیت آی‌پی، دریافت خودکار آدرس کیف پول و پشتیبانی از آدرس دستی رزرو.
  - **درگاه‌های والکس، ترون‌گرید و ترون‌اسکن:** ماژول‌های آماده برای استعلام مستقیم وضعیت تراکنش‌ها از بلاکچین.
  - **درگاه شبیه‌ساز (Mock Gateway):** درایور سندباکس داخلی جهت اجرای تست‌های خودکار بدون نیاز به شبکه اصلی کریپتو.
- **تولید کیوآرکد آفلاین:** تولید کدهای QR آدرس واریز به صورت محلی در مرورگر کاربر با کتابخانه `qrcode` بدون استفاده از سرویس‌های آنلاین خارجی.
- **اعتبارسنجی ۳ مرحله‌ای شناسه تراکنش:** پردازش کد رهگیری هش با جاب صف `VerifyCryptoDepositJob`، استعلام دوره‌ای وضعیت و ارجاع خودکار به بازبینی دستی در صورت عدم تایید شبکه.
- **میز مدیریت پرداخت‌ها:** پنل اختصاصی مدیر جهت تنظیم درگاه‌ها، استعلام وضعیت تراکنش‌ها، تایید یا رد دستی واریزی‌ها همراه با ثبت دلیل و لاگ ممیزی.

#### ۳.۵. وب‌سرویس یکپارچه توسعه‌دهندگان
- **توکن‌های امنیتی هش‌شده:** ذخیره‌سازی کلیدهای دسترسی با الگوریتم یک‌طرفه `SHA-256` و نمایش مقدار خام کلید منحصراً در لحظه ایجاد یا بازنشانی.
- **سطوح دسترسی ریزدانه:** تخصیص دسترسی‌های مشخص به هر توکن (`messages:send`، `numbers:read` و...).
- **ارسال تکی و انبوه پیامک:** ارسال پیام به یک یا چندین شماره مقصد از طریق اندپوینت `POST /api/v1/messages`.
- **وب‌هوک ورودی با کنترل تکرار (Idempotency):** دریافت آنی پیام‌ها با اندپوینت `POST /api/v1/messages/inbound` و کلید ترکیبی یکتا `(source_system_id, client_message_id)` جهت مقابله با پیام‌های تکراری ناشی از خطای شبکه.
- **مستندات تعاملی داخل سیستم:** راهنمای فنی کامل و آماده کپی نمونه کدها در آدرس `/docs/api`.

#### ۳.۶. میز خدمت و تیکتینگ سازمانی
- **چرخه کامل رسیدگی به تیکت‌ها:** صدور کد رهگیری خودکار و یکتا با ساختار اتمیک `TK-XXXXX`.
- **دسته‌بندی دپارتمان و اولویت:** تفکیک بر اساس بخش‌ها (`technical`, `numbers`, `api`, `general`) و اولویت‌های کاری (`low`, `medium`, `high`, `urgent`).
- **نقش مستقل کارشناس پشتیبانی (`agent`):** پنل ویژه کارشناسان جهت پاسخ‌گویی، تغییر وضعیت تیکت و ارجاع به همکاران متخصص.
- **پیوست‌های حفاظت‌شده:** ذخیره امن فایل‌های ضمیمه در مسیر خصوصی `storage/app/private/tickets/...` و دانلود فایل صرفاً از طریق استریم احراز هویت شده در لایه Policy.

#### ۳.۷. استقلال کامل از سی‌دی‌ان و امنیت اینترانت
- **عدم وابستگی به اینترنت جهانی:** کامپایل بومی تمامی قالب‌ها، اسکریپت‌ها و فونت استاندارد وزیزمتن (`@fontsource/vazirmatn`) برای محیط‌های ملی و اینترانت.
- **سرآیندهای امنیتی سخت‌گیرانه:** اعمال مداوم سیاست‌های امنیتی CSP، HSTS، X-Frame-Options و X-Content-Type-Options در کلیه روت‌های وب و وب‌سرویس.
- **دفترکل ممیزی رخدادها:** ثبت تمام تغییرات حساس کاربری، مالی و خطوط در جدول `audit_logs` به همراه آی‌پی و جزئیات تغییرات در فیلد JSON.

---

### ۴. معماری سیستم و مدل داده‌ها

```mermaid
erDiagram
    USERS ||--o{ WALLETS : "مالک کیف پول"
    WALLETS ||--o{ WALLET_TRANSACTIONS : "سوابق تراکنش‌ها"
    USERS ||--o{ CRYPTO_DEPOSITS : "ایجاد واریز کریپتو"
    PAYMENT_GATEWAYS ||--o{ CRYPTO_DEPOSITS : "پردازش درگاه"
    
    USERS ||--o{ PHONE_NUMBERS : "مالکیت خطوط"
    USERS ||--o{ NUMBER_REQUESTS : "ثبت درخواست شماره"
    USERS ||--o{ API_TOKENS : "صدور توکن"
    USERS ||--o{ AUDIT_LOGS : "ایجاد رخداد امنیتی"
    USERS ||--o{ TICKETS : "ثبت تیکت"
    USERS ||--o{ TICKET_MESSAGES : "ارسال پیام پشتیبانی"
    
    PHONE_PREFIXES ||--o{ PHONE_NUMBERS : "پیشوند خطوط"
    PHONE_NUMBERS ||--o{ MESSAGES : "ارسال یا دریافت"
    PHONE_NUMBERS ||--o| NUMBER_REQUESTS : "تخصیص یافته به"
    
    TICKETS ||--|{ TICKET_MESSAGES : "شامل پیام‌ها"
    TICKET_MESSAGES ||--o{ TICKET_ATTACHMENTS : "ضمیمه فایل"

    USERS {
        bigint id PK
        string username UK "ورود با نام کاربری"
        string name "نام و نام خانوادگی"
        string role "admin | agent | user"
        string status "active | suspended | inactive"
    }

    WALLETS {
        bigint id PK
        bigint user_id FK
        decimal balance "موجودی قابل استفاده"
    }

    CRYPTO_DEPOSITS {
        bigint id PK
        bigint user_id FK
        string tracking_code UK
        string currency "TRX | USDT"
        string network "TRON | TRC20"
        decimal requested_amount
        string deposit_address
        string tx_hash UK
        string status "pending_transfer | verifying | completed | failed"
    }

    PHONE_NUMBERS {
        bigint id PK
        string number UK "شماره با حفظ صفرهای اول"
        string type "user_standard | user_service | platform_service"
        string status "available | allocated | reserved | suspended"
        bigint user_id FK "nullable"
        boolean accepts_incoming_sms
    }

    MESSAGES {
        bigint id PK
        bigint phone_number_id FK
        string destination_number
        string sender
        text body
        string direction "inbound | outbound"
        string client_message_id "کلید یکتایی"
        string delivery_status "sent | delivered | read"
    }
```

---

### ۵. سناریوهای کاربری و مدیریتی

#### 👤 فرآیند و تجربه کاربر
1. **ورود به سامانه:** احراز هویت سریع با `username` و رمزعبور، بدون معطلی برای لینک‌های فعال‌سازی ایمیلی.
2. **افزایش اعتبار حساب:** مراجعه به بخش پروفایل/کیف پول، انتخاب مبلغ TRX، مشاهده کیوآرکد آفلاین آدرس کیف پول، واریز وجه و ثبت شناسه تراکنش (TX Hash).
3. **دریافت شماره:** انتخاب آنی شماره از استخر ۹ رقمی یا ارسال درخواست بررسی برای سرشماره‌های رند ۴ تا ۸ رقمی.
4. **ارسال و دریافت پیام:** ورود به بخش پیام‌ها، انتخاب خط فعال فرستنده، درج شماره مقصد و چت دوطرفه بلادرنگ.
5. **ارتباط با وب‌سرویس:** تولید توکن اختصاصی با سطوح دسترسی محدود جهت استفاده در اسکریپت‌ها یا نرم‌افزارهای سازمان.
6. **پشتیبانی:** ثبت تیکت‌های پشتیبانی به همراه ضمیمه فایل و پیگیری پاسخ کارشناسان.

#### 🛡️ فرآیند و وظایف راهبر و کارشناسان
1. **داشبورد نظارتی:** پایش لحظه‌ای تعداد خطوط تخصیص‌یافته، پیام‌های روزانه، تراکنش‌ها و کاربران آنلاین.
2. **مدیریت استخر شماره‌ها:** تولید شماره‌های جدید با حفظ صفرهای مقدماتی، تعریف پیشوندهای دلخواه و کنترل سقف تخصیص هر کاربر.
3. **بررسی درخواست‌های سرشماره:** مشاهده درخواست شماره‌های کوتاه، بررسی دلیل فنی مشترک و تایید یا رد مستدل با تخصیص خودکار.
4. **مدیریت درگاه‌های پرداخت:** ثبت کلیدهای وب‌سرویس و امضای دیجیتال نوبیتکس، همگام‌سازی روزانه آدرس‌های واریز و بررسی تراکنش‌های معلق.
5. **رسیدگی به تیکت‌ها:** ارجاع تیکت به کارشناس مربوطه، تغییر وضعیت به در حال بررسی یا پاسخ‌داده‌شده.
6. **لاگ‌های ممیزی امنیتی:** جستجو در سوابق رویدادها همراه با جزئیات آی‌پی و تغییرات رخ داده برای پاسخگویی به مراجع ناظر.

---

### ۶. راهنمای سریع وب‌سرویس برای برنامه‌نویسان

تمامی درخواست‌ها باید حامل توکن دسترسی در سرآیند `Authorization` باشند:

```http
Authorization: Bearer gn_live_your_api_token_here
Accept: application/json
Content-Type: application/json
```

#### ارسال پیامک تکی به مقصد
```bash
curl -X POST "http://localhost:8000/api/v1/messages" \
  -H "Authorization: Bearer gn_live_abcdef123456" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "000100200",
    "to": "+85000100201",
    "message": "کد ورود شما به سامانه: 481902",
    "client_message_id": "TX-REQ-98120"
  }'
```

#### ارسال گروهی پیامک (Bulk Dispatch)
```bash
curl -X POST "http://localhost:8000/api/v1/messages" \
  -H "Authorization: Bearer gn_live_abcdef123456" \
  -H "Content-Type: application/json" \
  -d '{
    "sender": "000100200",
    "destinations": ["+85000100201", "+85000100202", "+85000100203"],
    "body": "به‌روزرسانی سرورها امشب در ساعت ۰۲:۰۰ انجام می‌شود.",
    "client_message_id": "BROADCAST-2026-11"
  }'
```

#### ثبت وب‌هوک پیام ورودی (Inbound Webhook)
```bash
curl -X POST "http://localhost:8000/api/v1/messages/inbound" \
  -H "Authorization: Bearer gn_live_abcdef123456" \
  -H "Content-Type: application/json" \
  -d '{
    "destination_number": "+85000100200",
    "sender": "+989120000000",
    "body": "درخواست شما با موفقیت ثبت شد.",
    "source_system_id": "TELCO_GATEWAY_MAIN",
    "client_message_id": "HOOK_UUID_772183"
  }'
```

#### استعلام پیام‌های جدید با پولینگ
```bash
curl -X GET "http://localhost:8000/api/v1/messages/poll?since_id=1400" \
  -H "Authorization: Bearer gn_live_abcdef123456"
```

---

### ۷. راهنمای راه‌اندازی و نصب گام‌به‌گام

#### پیش‌نیازهای سرور
- **PHP:** نسخه `8.3` یا بالاتر (اکستنشن‌های `bcmath`, `curl`, `mbstring`, `openssl`, `pdo_mysql` یا `pdo_sqlite`, `sodium`, `xml`, `zip`)
- **Node.js:** نسخه `20.x` یا `22.x LTS` همراه با `npm 10.x`
- **Composer:** نسخه `2.8` یا بالاتر
- **Database:** MariaDB 10.4+، MySQL 8.0+ یا SQLite 3

#### مراحل نصب و راه‌اندازی

```bash
# ۱. کلون پروژه و ورود به دایرکتوری اصلی
git clone https://github.com/shanaqisaeed/GetNumber.git
cd GetNumber

# ۲. نصب بسته‌های بک‌اند
composer install --optimize-autoloader

# ۳. آماده‌سازی فایل تنظیمات محیطی
cp .env.example .env
php artisan key:generate

# ۴. اجرای مایگریشن‌ها و داده‌های اولیه سامانه
php artisan migrate --force
php artisan db:seed --force

# ۵. نصب وابستگی‌های فرانت‌اند و کامپایل بیلد نهایی
npm install
npm run build

# ۶. اجرای سرور توسعه محلی
php artisan serve
```

#### راه‌اندازی سرویس وب‌سوکت و صف پردازش
جهت فعال‌سازی وب‌سوکت Reverb و صف بررسی تراکنش‌های ارز دیجیتال، این دستورات را در ترمینال‌های مجزا اجرا نمایید:

```bash
# اجرای سرور وب‌سوکت Laravel Reverb
php artisan reverb:start

# اجرای ورکر پردازش صف‌های پس‌زمینه
php artisan queue:listen --tries=3
```

#### حساب‌های پیش‌فرض راه‌اندازی

| نام کاربری | نقش کاربری | کلمه عبور پیش‌فرض | سطح دسترسی |
| :---: | :---: | :---: | :--- |
| `admin` | **راهبر ارشد سامانه** | `Admin@123456` | دسترسی کامل به خطوط، کاربران، درگاه‌های پرداخت، توکن‌ها و لاگ‌ها |
| `user` | **مشترک سازمانی نمونه** | `User@123456` | تخصیص شماره، ارسال/دریافت پیام، شارژ کیف پول و توکن API |

---

### ۸. پردازش‌های پس‌زمینه و زمان‌بندی

جهت اجرای خودکار فرآیندهای پس‌زمینه (نظیر آزادسازی خطوط، استعلام تراکنش‌های رمزارز و همگام‌سازی کیف‌پول نوبیتکس)، کران‌جاب زیر را در سرور ثبت نمایید:

```bash
* * * * * cd /path-to-project/GetNumber && php artisan schedule:run >> /dev/null 2>&1
```

دستورات زمان‌بندی‌شده سامانه:
- `numbers:sweep-expired`: اجرای خودکار ساعتی برای آزادسازی شماره‌های منقضی و حذف پیام‌های قبلی.
- `payments:verify-pending`: اجرای دقیقه‌ای برای استعلام و اعتبارسنجی شبکه ترون جهت تراکنش‌های در انتظار تایید.
- `payments:nobitex:sync-wallet`: اجرای روزانه برای اعتبارسنجی توکن صرافی و همگام‌سازی آدرس واریز TRX.

---

### ۹. تضمین کیفیت و آزمون‌های خودکار

پروژه GetNumber دارای مجموعه‌ای کامل از **بیش از ۱۸۰ تست خودکار** است که تمامی سناریوهای کارکردی، امنیتی، همزمانی و مالی را پوشش می‌دهد:

```bash
# اجرای سوئیت کامل آزمون‌ها
php artisan test

# اجرای تست‌ها در نمای خلاصه
php artisan test --compact
```

محورهای آزمون‌های پوشش‌داده‌شده:
- تضمین عدم وقوع Race Condition در تخصیص شماره‌ها با قفل بدبینانه.
- کنترل Idempotency در وب‌هوک با کلید ترکیبی `(source_system_id, client_message_id)`.
- اعتبارسنجی امضاهای دیجیتال Ed25519 و کنترل دسترسی آی‌پی در درگاه نوبیتکس.
- یکپارچگی دفترکل موجودی کیف پول، کسر اتمیک هزینه شماره و عودت خودکار در زمان خطا.
- کانال‌های مجوز وب‌سوکت Reverb و ارسال رویدادهای زنده دوطرفه.
- اعتبارسنجی سرآیندهای امنیتی CSP، HSTS، X-Frame در روت‌های عمومی و خصوصی.

---

### ۱۰. مجوز و حقوق مالکیت معنوی

این نرم‌افزار تحت مجوز بین‌المللی **[GNU General Public License v3.0 (GPL-3.0)](LICENSE)** منتشر شده است.

```text
Copyright (C) 2026 Saeed Shanaqi (https://github.com/shanaqisaeed/GetNumber)
All Rights Reserved under GNU General Public License v3.0.
```

- **حفظ حق انتساب و اصالت اثر:** نام پدیدآورنده اصلی (**Saeed Shanaqi**) و پیوند مخزن گیت‌هاب باید در تمامی فایل‌های سورس‌کد، مستندات و رابط کاربری دست‌نخورده باقی بماند.
- **تضمین کپی‌لفت متقابل:** هرگونه استفاده، توسعه یا انتشار نسخه‌های انشعابی الزاماً باید تحت همین مجوز (GPL-3.0) همراه با انتشار سورس‌کد کامل صورت گیرد.
