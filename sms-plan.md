# پلن ۵ فازی پیاده‌سازی ارسال پیامک لایسنس

> فایل موقت — در gitignore است، کامیت نمی‌شود.
> سرویس: پیامیتو (REST API)

---

## نکات کلیدی API پیامیتو

- Auth: `username` + `password` (password = API key از منوی توسعه‌دهندگان — نه رمز ورود!)
- Endpoint: `POST https://rest.payamak-panel.com/api/SmartSMS/Send`
- موفقیت: `RetStatus === 1` — مقدار `Value` = ID پیامک
- **کد `7`** (محتوای فیلتر): پیامک از خط اصلی می‌رود — **success تلقی شود**
- **کد `14`** (حاوی لینک): استفاده از `{site_url}` در template این خطا را می‌دهد — در UI هشدار
- پیامیتو خودش fallback بین سه خط فرستنده را مدیریت می‌کند

---

## فاز ۱ — هسته SMS (`includes/sms.php`)

### توابع اصلی

```php
spot_sms_is_enabled(): bool
spot_sms_normalize_phone(string $phone): string
spot_sms_build_message(string $template, $order): string
spot_sms_send_raw(string $phone, string $text): array
// return: ['ok' => bool, 'msg_id' => int|null, 'code' => int|null, 'error' => string]
```

### `spot_sms_send_raw` — ارتباط با Payamito

```php
POST https://rest.payamak-panel.com/api/SmartSMS/Send
Body (form-urlencoded):
  username      = $sp['sms_username']
  password      = $sp['sms_password']
  to            = $phone
  text          = $text
  from          = $sp['sms_from']
  fromSupportOne = $sp['sms_from1']   // اگه خالی نباشد
  fromSupportTwo = $sp['sms_from2']   // اگه خالی نباشد
```

### تشخیص موفقیت از response

```
RetStatus !== 1          → failure
Value در {0,2,4,5,9,14,15} → failure با error_code
Value === 7              → success (فیلتر اما رفته)
Value عدد مثبت دیگر     → success (ID پیامک)
```

### Error messages فارسی (برای order note)

```
0  → نام کاربری یا رمز پیامیتو اشتباه است.
2  → اعتبار حساب پیامیتو کافی نیست.
5  → شماره فرستنده پیامیتو معتبر نیست.
9  → ارسال از خطوط عمومی مجاز نیست.
14 → متن پیامک حاوی لینک است.
15 → عبارت «لغو11» در انتهای متن وجود ندارد.
```

### نرمال‌سازی شماره تلفن

```
حذف فاصله، خط تیره، پرانتز
تبدیل ارقام فارسی به انگلیسی
+98XXXXXXXXXX → 09XXXXXXXXX
```

### متغیرهای template

| متغیر | منبع |
|---|---|
| `{customer_name}` | billing first + last name |
| `{order_id}` | order number |
| `{course_names}` | نام دوره‌های متصل به آیتم‌های سفارش |
| `{site_name}` | `get_bloginfo('name')` |
| `{site_url}` | ⚠ باعث خطای کد ۱۴ می‌شود |

`{license_key}` در template اول **جایگزین نمی‌شود** (literal می‌ماند تا ادمین اشتباهش را ببیند).

---

## فاز ۲ — UI تنظیمات (`includes/admin/settings.php`)

### کارت جدید «📲 ارسال پیامک»

```
☑ ارسال خودکار پیامک پس از صدور لایسنس

نام کاربری پیامیتو:       [______________]
کلید API (رمز وب‌سرویس):  [______________]   ← از منوی توسعه‌دهندگان پیامیتو
شماره فرستنده اصلی:       [______________]
شماره بکاپ ۱ (اختیاری):   [______________]
شماره بکاپ ۲ (اختیاری):   [______________]

متن پیامک توضیحات:
┌──────────────────────────────────────────────────────┐
│                                                       │
└──────────────────────────────────────────────────────┘
[XX کاراکتر — حدود X پارت پیامک فارسی (هر پارت ۷۰ کاراکتر)]

متغیرها: {customer_name} {order_id} {course_names} {site_name} {site_url}
⚠ استفاده از {site_url} ممکن است باعث خطای «حاوی لینک» شود.
⚠ پیامک دوم به‌صورت خودکار فقط کد لایسنس خالص ارسال می‌شود.

ارسال پیامک آزمایشی: [09XXXXXXXXXX] [ارسال آزمایشی]  ← نتیجه inline نمایش داده می‌شود
```

### ذخیره در option `spotplayer`

```
spotplayer[sms_enabled]   = 1
spotplayer[sms_username]  = "..."
spotplayer[sms_password]  = "..."    ← API key
spotplayer[sms_from]      = "1000..."
spotplayer[sms_from1]     = ""
spotplayer[sms_from2]     = ""
spotplayer[sms_template]  = "..."
```

### AJAX: `wp_ajax_spot_test_sms`

در `sms.php` — گرفتن credentials از POST (مقادیر لحظه‌ای، قبل از save):
```php
spot_sms_send_raw($phone, 'پیامک آزمایشی از افزونه اسپات پلیر — ' . get_bloginfo('name'))
```
→ `wp_send_json_success` یا `wp_send_json_error` با متن فارسی خطا.

---

## فاز ۳ — ارسال async و retry (WooCommerce)

### Order meta

```
_spot_sms_phone          = "09121234567"
_spot_sms_msg1_status    = pending|sent|failed
_spot_sms_msg1_attempts  = 0
_spot_sms_msg1_sent_at   = timestamp|null
_spot_sms_msg1_id        = payamito_id|null
_spot_sms_msg2_status    = blocked|pending|sent|failed
_spot_sms_msg2_attempts  = 0
_spot_sms_msg2_sent_at   = timestamp|null
_spot_sms_msg2_id        = payamito_id|null
```

`blocked` = msg2 منتظر موفقیت msg1 است.

### Hook در `spot_woo_order_license_request`

بعد از `$ord->save_meta_data()` موفق:

```php
if (spot_sms_is_enabled() && !$is_installment_renewal) {
    $phone = spot_sms_normalize_phone($ord->get_billing_phone());
    if ($phone) {
        spot_sms_init_order_meta($ord, $phone);
        spot_sms_schedule_msg1($ord->get_id(), 'woo', 0, 5);  // 5s delay
    } else {
        $ord->add_order_note('⚠️ شماره تلفن در سفارش یافت نشد — پیامک ارسال نشد.');
        $ord->save();
    }
}
```

### Action Scheduler actions

```
spot_sms_msg1(order_id, platform, attempt) → spot_sms_handle_msg1(...)
spot_sms_msg2(order_id, platform, attempt) → spot_sms_handle_msg2(...)
```

### سیاست retry

```
attempt 1, 2, 3 → delay 2 دقیقه
attempt 4+      → delay 5 دقیقه
attempt == 5    → order note هشدار (retry ادامه می‌یابد)
```

### `spot_sms_handle_msg1`

```
ارسال msg1
├── موفق →
│     update meta: status=sent, sent_at, msg_id
│     add_order_note: «📲 پیامک توضیحات لایسنس به {phone} ارسال شد.»
│     as_schedule: spot_sms_msg2 (delay=0)
└── شکست →
      update meta: attempts++
      schedule retry
      اگر attempt == 5 → add_order_note هشدار
```

### `spot_sms_handle_msg2`

```
خواندن _spotplayer_data['key']
├── خالی → add_order_note «⚠️ کد لایسنس یافت نشد — پیامک دوم ارسال نشد.»
└── موجود →
    ارسال msg2 (کد خام)
    ├── موفق → meta: status=sent + add_order_note «📲 کد لایسنس به {phone} ارسال شد.»
    └── شکست → retry با همان سیاست
```

### Fallback بدون Action Scheduler

```php
if (!function_exists('as_schedule_single_action')) {
    $r = spot_sms_send_raw($phone, $msg1_text);
    if ($r['ok']) {
        spot_sms_send_raw($phone, $license_key);
    } else {
        $ord->add_order_note('⚠️ ارسال پیامک ناموفق. برای retry خودکار، Action Scheduler لازم است.');
    }
}
```

### جدول کامل order notes

| رویداد | متن |
|---|---|
| msg1 موفق | `📲 پیامک توضیحات لایسنس به {phone} ارسال شد.` |
| msg2 موفق | `📲 کد لایسنس به {phone} ارسال شد.` |
| ۵ شکست متوالی | `⚠️ پس از ۵ بار تلاش ناموفق، هر ۵ دقیقه مجدداً تلاش می‌شود.` |
| phone خالی | `⚠️ شماره تلفن یافت نشد — پیامک ارسال نشد.` |
| license key خالی | `⚠️ کد لایسنس یافت نشد — پیامک دوم ارسال نشد.` |
| بدون AS + شکست | `⚠️ ارسال پیامک ناموفق. Action Scheduler برای retry خودکار لازم است.` |

---

## فاز ۴ — باکس ادمین + دکمه ارسال مجدد

### بخش SMS در `order-box.php`

زیر لینک لایسنس، فقط اگر SMS فعال باشد:

```
📲 پیامک لایسنس
─────────────────────────────────────────
پیامک ۱:  ✅ ارسال شد — ۱۴۰۴/۳/۵ ۱۴:۲۳
پیامک ۲:  ✅ ارسال شد — ۱۴۰۴/۳/۵ ۱۴:۲۳
[ارسال مجدد]
```

| مقدار status | نمایش |
|---|---|
| `sent` | ✅ ارسال شد — {تاریخ} |
| `pending` با attempts > 0 | 🔄 در حال تلاش ({X} بار) |
| `blocked` | ⏸ در انتظار پیامک اول |
| `failed` با attempts ≥ 5 | ⚠️ ارسال نشده ({X} بار تلاش) |

### AJAX: `wp_ajax_spot_resend_sms`

```
1. check_admin_referer + manage_options
2. spot_sms_cancel_pending_for_order($order_id)
3. reset تمام _spot_sms_* meta
4. spot_sms_init_order_meta($order, $phone)
5. spot_sms_schedule_msg1($order_id, 'woo', 0, 0)  // بلافاصله
6. wp_send_json_success
```

### `spot_sms_cancel_pending_for_order`

```php
function spot_sms_cancel_pending_for_order(int $order_id): void {
    if (!function_exists('as_unschedule_all_actions')) return;
    foreach (['woo', 'edd'] as $p) {
        as_unschedule_all_actions('spot_sms_msg1', ['order_id' => $order_id, 'platform' => $p]);
        as_unschedule_all_actions('spot_sms_msg2', ['order_id' => $order_id, 'platform' => $p]);
    }
}
```

---

## فاز ۵ — EDD + تکمیل

### Hook در EDD (`includes/edd/functions.php`)

بعد از صدور موفق لایسنس EDD:

```php
if (spot_sms_is_enabled()) {
    $phone = spot_sms_normalize_phone($payment->user_info['phone'] ?? '');
    if ($phone) {
        spot_sms_init_order_meta_edd($payment, $phone);
        spot_sms_schedule_msg1($payment->ID, 'edd', 0, 5);
    }
}
```

### تکمیل‌های نهایی فاز ۵

- تأیید: `{license_key}` در template msg1 جایگزین نمی‌شود
- تست retry flow با AS (شبیه‌سازی شکست)
- تست fallback بدون AS (sync)
- تست دکمه ارسال مجدد + cancel pending
- تست پیامک آزمایشی از تنظیمات
- تست کد `7` (فیلتر) → success
- تست کد `14` (لینک) → order note فارسی

---

## خلاصه فازها

| فاز | محتوا | فایل‌های اصلی |
|---|---|---|
| ۱ | هسته — Payamito wrapper، phone normalizer، template engine | `includes/sms.php` (جدید) |
| ۲ | تنظیمات — کارت SMS، char counter، دکمه تست | `includes/admin/settings.php` |
| ۳ | async WooCommerce — hook، AS actions، retry، order notes | `functions.php` + `sms.php` |
| ۴ | باکس ادمین — نمایش وضعیت، دکمه ارسال مجدد | `order-box.php` + `sms.php` |
| ۵ | EDD + تکمیل و تست | `edd/functions.php` + polish |
