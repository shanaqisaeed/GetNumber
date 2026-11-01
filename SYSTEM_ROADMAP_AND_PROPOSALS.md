# 🚀 سند راهبردی، ایده‌ها و نقشه راه توسعه سامانه GetNumber (نسخه Enterprise Production)

این سند به عنوان راهنمای جامع معماری و نقشه راه اجرایی، تمام پیشنهادها، الگوهای کدنویسی، طراحی پایگاه داده و استراتژی‌های زیرساختی لازم برای ارتقای سامانه **GetNumber** به یک محصول در کلاس تجاری و سازمانی پایدار را ارائه می‌دهد.

---

## ۱. بازطراحی موتور مخابراتی (Carrier-Agnostic Telecom Engine)

برای تبدیل سامانه از یک محیط بسته به یک دروازه مخابراتی قدرتمند، سیستم باید از وابستگی صرف به خطوط داخلی دیتابیس جدا شده و معماری چند درایوره به خود بگیرد.

```
                           ┌──────────────────────────┐
                           │    MessageService        │
                           └────────────┬─────────────┘
                                        │
                         ┌──────────────┴──────────────┐
                         ▼                             ▼
              [Internal Line Driver]        [External Carrier Driver]
                         │                             │
                         ▼                             ▼
              [Local Database Route]         [Carrier Routing Engine]
                                                       │
                           ┌───────────────────────────┼───────────────────────────┐
                           ▼                           ▼                           ▼
                   [SMPP v3.4/v5.0]             [HTTP Webhook]             [GSM Hardware]
                 (Direct Telecom Link)      (Twilio, Kavenegar, etc.)      (GoIP / SIM Bank)
```

### ۱.۱. تعریف رابط واحد درایورهای مخابراتی (`SmsCarrierInterface`)
یک اینترفیس منعطف در مسیر `app/Contracts/SmsCarrierInterface.php` ایجاد می‌شود:

```php
namespace App\Contracts;

use App\DTOs\OutboundSmsRequest;
use App\DTOs\OutboundSmsResponse;
use App\DTOs\DeliveryReceipt;

interface SmsCarrierInterface
{
    public function getIdentifier(): string;
    public function getName(): string;
    public function send(OutboundSmsRequest $request): OutboundSmsResponse;
    public function parseInboundWebhook(array $payload): OutboundSmsResponse;
    public function parseDeliveryReceipt(array $payload): DeliveryReceipt;
    public function checkBalance(): ?float;
}
```

### ۱.۲. محاسبه‌گر طول و پارت‌های پیامک (`SmsSegmentCalculator`)
ایجاد یک سرویس تخصصی در بک‌اند و فرانت‌اند برای تفکیک الفبای پیامک:
* **الفبای لاتین (`GSM 7-bit`):** هر پارت تک‌صفحه‌ای ۱۶۰ کاراکتر؛ پیام‌های چندپارتی هر پارت ۱۵۳ کاراکتر (۷ کاراکتر برای سرآیند اتصال `UDH`).
* **الفبای فارسی/یونیکد (`UCS-2`):** هر پارت تک‌صفحه‌ای ۷۰ کاراکتر؛ پیام‌های چندپارتی هر پارت ۶۷ کاراکتر.

---

## ۲. انقلاب در سیستم مالی و بلاکچین (Non-Custodial HD Wallet & Webhooks)

برای حذف کامل آسیب‌پذیری سرقت هش تراکنش (`Front-Running`) و تضمین صحت تراکنش‌ها، فرآیند شارژ کیف پول باید به صورت غیرامانی و بر پایه مشتق‌سازی آدرس‌های مجزا بازطراحی شود.

```
               [Master Public Key (xPub)]  (ذخیره امن روی سرور، بدون کلید خصوصی)
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
    [Address Derivation]        [Dedicated Address per User]
   (BIP-44: m/44'/195'/0'/0/i)    (e.g., TXYZ... برای فاکتور #102)
                                         │
                                         ▼
                           [Tron Blockchain Node Webhook]
                                         │
                                         ▼
                             [Block Confirmations >= 19]
                                         │
                                         ▼
                              [Atomic Auto-Credit Job]
```

### ۲.۱. معماری کیف‌پول سلسله‌مراتبی (`HD Wallet Derivation`)
* پلتفرم نیازی به ذخیره کلید خصوصی (`Private Key`) روی سرور ندارد. صرفاً کلید عمومی اصلی (`xPub`) در فایل کانفیگ قرار می‌گیرد.
* به ازای هر سفارش واریز یا به ازای هر کاربر یک آدرس یکتای بلاکچینی مشتق می‌شود:
  `m/44'/195'/0'/0/{user_id}` برای شبکه ترون (`TRX / USDT TRC-20`).
* چون هر آدرس متعلق به یک کاربر خاص است، واریز به آن آدرس **فقط و فقط** به حساب همان کاربر می‌نشیند و امکان سرقت هش وجود ندارد.

### ۲.۲. شنونده خودکار بلاکچین (`Automated Blockchain Listener`)
* وب‌هوک مستقیم از ارائه‌دهندگانی مانند `TronGrid Webhooks` یا یک اسکریپت سبک پس‌زمینه (`Daemon`) نود بلاکچین را پایش می‌کند.
* با واریز توکن، جاب `CreditWalletJob` به صورت خودکار و پس از دریافت حداقل ۱۹ تاییدیه (`Confirmations`) حساب کاربر را شارژ می‌کند؛ کاربر هیچ نیازی به وارد کردن دستی هش تراکنش نخواهد داشت.

### ۲.۳. استقرار حسابداری دوطرفه (`Double-Entry Ledger`)
به جای اکتفا به یک فیلد `balance`، جدول دفاتر کل مالی (`ledger_entries`) ایجاد می‌شود:
* هر تراکنش دارای دو سطر است: بدهکار (`Debit`) و بستانکار (`Credit`).
* جمع تراز کلیه ورودی‌ها همواره باید صفر باشد (`SUM(debits) - SUM(credits) = 0`) تا سلامت دیتابیس در هر لحظه مانیتور شود.

---

## ۳. مهندسی مقیاس‌پذیری و کشینگ لایه صفر (Zero-Layer Caching & Queues)

### ۳.۱. حل رادیکال سربار پولینگ (`ETag / Versioning Pattern`)
به جای اجرای مجدد کوئری‌های گروه‌بندی و شمارش در هر ۴ ثانیه:
1. برای هر خط یا هر کاربر یک کلید در ردیس نگهداری می‌شود: `line:{line_id}:version`.
2. هرگاه پیامی برای این خط ارسال یا دریافت شود، این نسخه افزایش می‌یابد (`INCR`).
3. در متد `poll`، کلاینت آخرین نسخه دریافت شده خود را می‌فرستد (`?v=12`).
4. سرور در کمتر از ۱ میلی‌ثانیه کلید ردیس را می‌خواند:
   * اگر نسخه تغییر نکرده باشد، بلافاصله پاسخ `304 Not Modified` یا یک `JSON` خالی سبک ارسال می‌شود.
   * دیتابیس به هیچ وجه درگیر کوئری‌های سنگین نمی‌شود.

### ۳.۲. پردازش ناهمگام پیام‌ها با صف‌های لاراولی (`Bus::batch`)
* ارسال‌های گروهی در قالب بسته‌های ۱۰۰ تایی به یک صف با اولویت بالا (`high-priority queue`) منتقل می‌شوند:
  ```php
  Bus::batch($jobs)
      ->then(fn (Batch $batch) => Log::info('Bulk SMS completed'))
      ->catch(fn (Batch $batch, Throwable $e) => Log::error('Batch failed'))
      ->dispatch();
  ```
* رویدادهای `WebSockets` از اینترفیس `ShouldBroadcast` استاندارد (نه `ShouldBroadcastNow`) استفاده می‌کنند تا در پس‌زمینه منتشر شوند.

### ۳.۳. پارتیشن‌بندی ماهانه جدول پیام‌ها (`Database Partitioning`)
طراحی میگریشن پارتیشن‌بندی در پایگاه داده بر اساس ستون تاریخ:
```sql
ALTER TABLE messages PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (
    PARTITION p_2026_10 VALUES LESS THAN (UNIX_TIMESTAMP('2026-11-01 00:00:00')),
    PARTITION p_2026_11 VALUES LESS THAN (UNIX_TIMESTAMP('2026-12-01 00:00:00')),
    PARTITION p_2026_12 VALUES LESS THAN (UNIX_TIMESTAMP('2027-01-01 00:00:00')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```
این ساختار به دیتابیس اجازه می‌دهد در کوئری‌های مکالمات اخیر، ۹۵٪ جدول را به طور کامل نادیده بگیرد (`Partition Pruning`).

---

## ۴. معماری مدرن فرانت‌اند و بازسازی ماژولار (Vue 3 Micro-Components)

### ۴.۱. شکستن کامپوننت ۱۳۰۰ خطی `Messages/Index.vue`
تقسیم کامپوننت به ساختار منظم زیر در دایرکتوری `resources/js/Pages/User/Messages/Partials/`:
* `ConversationList.vue`: مدیریت فهرست گفتگوها با پیاده‌سازی اسکرول مجازی (`vue-virtual-scroller`) برای نمایش روان هزاران گفتگو.
* `ChatArea.vue`: کانتینر اصلی گفتگو با مدیریت لودینگ، اسکرول خودکار به آخرین پیام و وضعیت‌ها.
* `ChatMessageBubble.vue`: کامپوننت حباب پیام، دکمه‌های کپی، نمایش زمان نسبی و وضعیت‌های تحویل.
* `MessageComposer.vue`: کادر ورود متن با شمارنده آنلاین کاراکتر و صفحات پیامک.
* `SimLineSwitcher.vue`: منوی شناور انتخاب خط فرستنده با نمایش برخط پیام‌های نخوانده هر خط.

### ۴.۲. مدیریت وضعیت سراسری با استور پینیا (`Pinia Store`)
ایجاد استور `useMessagingStore` برای نگهداری سوابق گفتگوها، پیام‌های جاری و رویدادهای زنده:
```typescript
export const useMessagingStore = defineStore('messaging', {
    state: () => ({
        activeLineId: null as number | null,
        activePeer: null as string | null,
        conversations: [] as Conversation[],
        messages: [] as Message[],
        connectionStatus: 'connected' as 'connected' | 'connecting' | 'disconnected',
    }),
    actions: {
        appendOptimisticMessage(payload: { body: string; to: string }) {
            // نمایش آنی پیام با وضعیت Pending و آیکون ساعت
        },
        reconcileMessage(tempId: string, realMessage: Message) {
            // جایگزینی پیام واقعی و ثبت تیک تایید
        }
    }
});
```

---

## ۵. سیستم ضد تقلب و امنیت انترپرایز (Anti-Fraud & Content Guard)

### ۵.۱. فیلتر بلادرنگ کلمات کلیدی و عبارات مشکوک
ایجاد لایه میانی امنیتی قبل از ارسال پیام‌ها به اپراتور:
* شناسایی الگوهای کلاهبرداری متداول (مانند عبارات مربوط به «یارانه»، «سامانه ثنا»، «برنده جایزه»، لینک‌های فیشینگ بانکی).
* استعلام خودکار دامنه‌های موجود در متن با سرویس رایگان `Google Safe Browsing API`.
* مسدودسازی خودکار اکانت و قرنطینه کردن پیام‌های دارای ریسک بالا.

### ۵.۲. احراز هویت دوعاملی (`TOTP 2FA`)
* استفاده از الگوریتم استاندارد `RFC 6238` برای تولید کدهای یکبارمصرف سازگار با `Google Authenticator` و `Bitwarden`.
* اجباری کردن تایید دو مرحله‌ای برای تغییر کلمه عبور، ایجاد کلیدهای `API` و درخواست‌های شارژ یا برداشت کیف پول.

---

## ۶. زیرساخت عملیاتی، کانتینرسازی و مانیتورینگ (DevOps & Docker)

### ۶.۱. پکیج کامل داکر (`Docker Infrastructure Stack`)
طراحی فایل `docker-compose.yml` در ریشه پروژه با معماری بهینه برای لاراولو سرور اختصاصی ریورب:

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    restart: unless-stopped
    volumes:
      - .:/var/www/html
    networks:
      - getnumber-network

  nginx:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - getnumber-network

  reverb:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    restart: unless-stopped
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"
    networks:
      - getnumber-network

  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    restart: unless-stopped
    command: php artisan queue:work redis --sleep=3 --tries=3
    depends_on:
      - redis
    networks:
      - getnumber-network

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis-data:/data
    networks:
      - getnumber-network

networks:
  getnumber-network:
    driver: bridge

volumes:
  redis-data:
```

### ۶.۲. رصدپذیری خطاها با سنترای (`Sentry Observability`)
* نصب پکیج `sentry/sentry-laravel` برای بک‌اند.
* نصب پکیج `@sentry/vue` برای فرانت‌اند با قابلیت ضبط خطاهای کلاینت و ردگیری مشکلات ارتباطی وب‌سوکت.
* ایجاد مسیر `/health` با استفاده از `spatie/laravel-health` برای پایش مداوم دیتابیس، کش، فضای دیسک و اتصال وب‌سوکت.

---

## ۷. نقشه راه فازبندی‌شده اجرا (Actionable 4-Phase Roadmap)

### فاز ۱: پایداری زیرساخت و رفع باگ‌های حیاتی (مدت تخمینی: ۲ هفته)
1. انتقال تمام منطق‌های ارسال پیام و رویدادهای `Reverb` از وضعیت همگام به صف‌های پس‌زمینه `Redis`.
2. اصلاح متد `poll` و پیاده‌سازی مکانیزم بررسی نسخه در `Redis` جهت کاهش ۹۵ درصدی فشار روی پایگاه داده.
3. پیکربندی کامل `Docker & Docker Compose` برای هماهنگی یکپارچه فرآیند توسعه و استقرار.

### فاز ۲: ارتقای هسته مالی و امنیت کریپتو (مدت تخمینی: ۲ هفته)
1. پیاده‌سازی سیستم مشتق‌سازی آدرس‌های مجزا با `HD Wallet (BIP-44)` بر روی شبکه ترون.
2. حذف قابلیت ثبت دستی هش تراکنش توسط کاربر و اتصال به جاب پایش اتوماتیک بلاکچین.
3. پیاده‌سازی احراز هویت دومرحله‌ای (`TOTP 2FA`) در پنل کاربری.

### فاز ۳: نوسازی فرانت‌اند و تجربه کاربری چت (مدت تخمینی: ۳ هفته)
1. تفکیک فایل هیولایی `Messages/Index.vue` به ۵ میکروکامپوننت تخصصی.
2. راه‌اندازی استور `Pinia` و فعال‌سازی به‌روزرسانی‌های خوش‌بینانه (`Optimistic Updates`).
3. تعبیه اسکرول مجازی (`Virtual Scrolling`) برای روان‌سازی نمایش پیام‌ها و لیست‌های طولانی.
4. اضافه کردن کامپوننت محاسبه‌گر هزینه و تعداد پارت‌های پیامک به کادر تایپ.

### فاز ۴: گسترش به دنیای واقعی مخابرات و ضد تقلب (مدت تخمینی: ۳ هفته)
1. پیاده‌سازی اینترفیس `SmsCarrierInterface` و ساخت درایورهای اتصال به سرورهای `SMPP` و وب‌هوک‌های ارائه‌دهندگان معتبر خارجی و داخلی.
2. راه‌اندازی سیستم گزارش تحویل واقعی پیامک (`DLR Receiver`).
3. پیاده‌سازی ماژول ضد فیشینگ با تحلیل محتوا و فیلتر عبارات مخرب.

