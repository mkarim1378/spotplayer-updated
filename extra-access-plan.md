# پلن پیاده‌سازی: درخواست دسترسی اضافه برای لایسنس

> فایل موقتی برنامه‌ریزی — پس از پیاده‌سازی کامل قابل حذف است.
> تاریخ: ۱۴۰۵/۰۳/۲۶ (2026-06-16)

## هدف فیچر

کاربری که قبلاً دوره خریده و لایسنسش را روی سقف دستگاه‌های مجاز فعال کرده، از طریق یک
صفحه‌ی جدید در حساب کاربری ووکامرس می‌تواند **درخواست دسترسی روی دستگاه‌های بیشتر** بدهد.
درخواست با پرداخت یک هزینه (پلکانی) ثبت می‌شود؛ ادمین به‌صورت دستی در پنل اسپات تعداد
دستگاه را زیاد می‌کند و سپس با یک دکمه، پیامک اطلاع‌رسانی (دومرحله‌ای) همراه کد لایسنس برای
مشتری ارسال می‌کند.

---

## درک معماری فعلی (مبنای طراحی)

- بدون جدول دیتابیس سفارشی — همه‌چیز روی **متای سفارش ووکامرس** و **آپشن‌های وردپرس**.
- دوره‌ها در آپشن `spot_courses` = `[{id, name, installments:[...]}]` — مدیریت در
  `includes/admin/settings.php`.
- تنظیمات کلی در آپشن `spotplayer` (شامل API، نمایش، و تمام تنظیمات پیامک).
- **اندپوینت حساب کاربری** الگوی آماده: `includes/woocommerce/shop.php:122-138` — منوی
  «لایسنس‌های من» با `add_rewrite_endpoint('licenses')` + فیلتر `woocommerce_account_menu_items`.
- **پیامک دومرحله‌ای** در `includes/sms.php`: مرحله ۱ متن توضیحات (با قالب)، مرحله ۲ فقط کد
  لایسنس؛ async با Action Scheduler + retry تا ۸ بار. هسته‌ی ارسال: `spot_sms_send_raw()`.
- **داشبورد ادمین** الگوی `includes/admin/installments.php`: submenu زیر `spotplayer`، جدول
  با فیلتر/جستجو/صفحه‌بندی، دکمه‌ی AJAX جلوی هر ردیف.
- کد لایسنس هر سفارش در متای `_spotplayer_data['key']` ذخیره است.

---

## تصمیمات نهایی (تأییدشده توسط کارفرما)

1. **واحد کار = سفارش/لایسنس مبدأ، نه دوره.** هر سفارش دقیقاً یک لایسنس دارد (که می‌تواند
   چند دوره را پوشش دهد). درخواست به `origin_order_id` گره می‌خورد.
2. **مبنای درصد** = «مجموع قیمت فعلی محصولات همان سفارش مبدأ».
3. **اعطای دسترسی** = دستی توسط ادمین در پنل اسپات + فقط ارسال پیامک. افزونه API صدا نمی‌زند.
4. **پله‌های قیمت و رفتار انتها** = یک تنظیم **سراسری** برای همه‌ی دوره‌ها (نه per-course).
5. **کد لایسنس پیامک** = مستقیماً از `_spotplayer_data['key']` سفارش مبدأ.

### فرض‌های پیش‌فرض (در صورت نیاز اصلاح شود)

- «قیمت فعلی محصول» = `get_price()` (قیمت فعالِ فعلی، با احتساب تخفیف اگر فعال باشد).
  اگر منظور قیمت بدون تخفیف است → `get_regular_price()`.
- اگر برای یک سفارش، درخواست `pending` پرداخت‌نشده وجود دارد، تا پرداخت/انقضا درخواست جدید
  برای همان سفارش بلاک می‌شود (جلوگیری از سفارش‌های نیمه‌کاره‌ی تکراری).

---

## مدل داده

### درخواست = یک سفارش ووکامرس مستقل

سفارش `pending` با یک ردیف **Fee** به مبلغ محاسبه‌شده + متاها:

| متا | مقدار |
|-----|-------|
| `_spot_extra_request` | `1` (شناسه‌ی نوع رکورد) |
| `_spot_extra_origin_order` | شناسه‌ی سفارش مبدأ (دارای لایسنس) |
| `_spot_extra_stage` | شماره‌ی مرحله‌ی این درخواست |

مزایا: استفاده‌ی مستقیم از درگاه‌های پرداخت ووکامرس، وضعیت پرداخت = وضعیت سفارش، همسو با
معماری فعلی. چون محصول دارای `_spotplayer_course` ندارد، مکانیزم خودکار لایسنس روی آن فعال
نمی‌شود.

### تنظیمات سراسری (در آپشن `spotplayer`)

- `extra_stages`: آرایه‌ای از `{type: 'fixed'|'percent', value: number}`
- `extra_end_mode`: `'max'` (حداکثر = تعداد پله‌ها) یا `'repeat_last'` (تکرار قیمت پله آخر)
- `sms_template_extra`: متن پیامک توضیحات دسترسی اضافه (مرحله ۱)
- `extra_admin_phone`: شماره‌ی ادمین برای گزارش روزانه
- `extra_report_time`: ساعت ارسال گزارش (HH:MM، تایم‌زون سایت)

---

## جریان کار

### فرانت (فرم درخواست)

1. اندپوینت جدید در منوی حساب کاربری ووکامرس (مثلاً `license-request`).
2. فرم شامل:
   - نام مشتری (ثابت، از billing — اینپوت نیست)
   - شماره موبایل (یه اینپوت باشه که مقدار پیش فرضش شماره کاربر باشه ولی کاربر بتونه پاک کنه و شماره دیگه ای بنویسه)
   - دراپ‌داون = سفارش‌های لایسنس‌دار مشتری (هر گزینه با نام دوره‌ها + شماره سفارش)؛
     مقدار = `origin_order_id`
   - دکمه‌ی سابمیت
3. هنگام سابمیت:
   - مرحله = (تعداد درخواست‌های paid قبلی برای همان سفارش مبدأ) + ۱
   - اگر `extra_end_mode = max` و مرحله > تعداد پله‌ها → بلاک (پیام: به سقف رسیده)
   - محاسبه‌ی مبلغ:
     - **ثابت** → همان مبلغ پله
     - **درصد** → درصد × مجموع قیمت فعلی محصولات سفارش مبدأ
     - اگر `repeat_last` و مرحله از آخرین پله گذشت → قیمت آخرین پله
   - ساخت سفارش Fee با متاهای بالا
   - ریدایرکت به `$order->get_checkout_payment_url()`

### ادمین (داشبورد + پیامک)

- داشبورد: سفارش‌های دارای `_spot_extra_request` و **paid** — با ستون‌های نام/موبایل/دوره/
  مرحله/مبلغ/وضعیت + دکمه‌ی «ارسال پیامک اطلاع‌رسانی».
- ادمین در پنل اسپات دستگاه را زیاد می‌کند، سپس دکمه را می‌زند → پیامک دومرحله‌ای:
  - مرحله ۱: قالب `sms_template_extra`
  - مرحله ۲: کد لایسنس از `_spotplayer_data['key']` سفارش مبدأ

### گزارش روزانه

- اکشن recurring (Action Scheduler؛ fallback به WP-Cron) در ساعت `extra_report_time`.
- شمارش درخواست‌های paid در ۲۴ ساعت گذشته → پیامک به `extra_admin_phone`.
- اگر صفر بود، پیامکی ارسال نمی‌شود.

---

## پلن پیاده‌سازی — ۱۰ فاز

---

### فاز ۱ — تنظیمات پله‌های قیمت و رفتار انتها
**فایل:** `includes/admin/settings.php`

- در تب «دوره‌ها» (یا یک تب جدید «دسترسی اضافه»)، یک کارت جدید اضافه کن.
- **جدول پله‌ها** (`extra_stages[]`): هر ردیف دو فیلد:
  - `extra_stages[n][type]`: select با دو گزینه `fixed` (مبلغ ثابت تومان) و `percent` (درصد از مجموع سفارش)
  - `extra_stages[n][value]`: عدد (مبلغ یا درصد)
  - دکمه‌ی «+ افزودن پله» (JS مشابه «+ افزودن قسط» در همان فایل)
  - دکمه‌ی حذف هر ردیف
- **رفتار انتها** (`extra_end_mode`): دو گزینه:
  - `max` = «حداکثر N بار — بعد از آخرین پله بلاک شود» (N = تعداد پله‌های تعریف‌شده)
  - `repeat_last` = «قیمت آخرین پله برای دفعات بعدی هم تکرار شود»
- ذخیره در همان `register_setting('spotplayer', 'spotplayer')` موجود.
- Sanitize: هر `value` با `floatval`، `type` با `sanitize_key`.

---

### فاز ۲ — تنظیمات پیامک و گزارش روزانه
**فایل:** `includes/admin/settings.php` (تب «پیامک»)

- **`sms_template_extra`**: textarea قالب پیامک مرحله ۱ دسترسی اضافه (مشابه `sms_template`).
  متغیرها: `{customer_name}`, `{order_id}`, `{course_names}`, `{site_name}`.
  یادآوری: پیامک مرحله ۲ (کد لایسنس) مثل سایر triggerها، فقط کد می‌فرستد.
- **`extra_admin_phone`**: input text شماره‌ی موبایل ادمین برای دریافت گزارش روزانه (`09XXXXXXXXX`).
- **`extra_report_time`**: input type `time` (HH:MM) ساعت ارسال گزارش روزانه (بر اساس تایم‌زون سایت — از `wp_timezone()` استفاده شود).
- Sanitize: `sms_template_extra` با `sanitize_textarea_field`؛ `extra_admin_phone` با `spot_sms_normalize_phone`؛ `extra_report_time` با regex `/^([01]\d|2[0-3]):[0-5]\d$/`.

---

### فاز ۳ — ثبت اندپوینت و آیتم منو
**فایل:** `includes/woocommerce/extra-access.php` (جدید)

- `add_action('init', ...)` ← `add_rewrite_endpoint('license-request', EP_PAGES)` (الگوی دقیق `shop.php:130-133`)
- `add_filter('woocommerce_account_menu_items', 'spot_extra_menu_item', 50)`:
  آیتم «درخواست دسترسی اضافه» را بعد از «لایسنس‌های من» اضافه کند.
- `add_action('woocommerce_account_license-request_endpoint', 'spot_extra_render_page')`:
  تابع رندر (فاز ۴).
- در `activation hook` (در `spotplayer.php`): `flush_rewrite_rules()` فراخوانی شود.

---

### فاز ۴ — رندر فرم فرانت
**فایل:** `includes/woocommerce/extra-access.php`

تابع `spot_extra_render_page()`:

1. اگر کاربر لاگین نیست → ریدایرکت به صفحه‌ی login.
2. سفارش‌های لایسنس‌دار مشتری را با `wc_get_orders` + `meta_query` برای کلید `_spotplayer_data` بگیر (الگوی `shop.php:93-99`).
3. **بررسی سقف قبل از نمایش فرم**: برای هر سفارش، مرحله‌ی فعلی حساب شود؛ اگر `extra_end_mode = max` و مرحله‌ی بعدی > تعداد پله‌ها → آن سفارش در dropdown با label «(به سقف رسیده)» disabled نمایش داده شود.
4. **رندر فرم:**
   - نام مشتری (ثابت، از billing — متن، نه input)
   - موبایل: `<input type="tel">` با مقدار پیش‌فرض billing_phone کاربر (قابل ویرایش)
   - دراپ‌داون سفارش‌ها: مقدار = `origin_order_id`، label = نام دوره‌ها + شماره سفارش
   - یک `<span id="spot-extra-price">` که با JS بر اساس انتخاب dropdown بدون reload آپدیت شود
   - `wp_nonce_field('spot_extra_submit')`
   - دکمه‌ی «پرداخت و ثبت درخواست»
5. قیمت‌های هر سفارش را به‌صورت JSON به JS پاس بده.
6. جدول ساده‌ی درخواست‌های قبلی paid همان مشتری زیر فرم (سفارش / دوره / مرحله / تاریخ / وضعیت).

---

### فاز ۵ — منطق قیمت (توابع کمکی)
**فایل:** `includes/woocommerce/extra-access.php`

**`spot_extra_count_paid_requests(int $origin_order_id): int`**
- `wc_get_orders` با فیلتر `meta_query`:
  `_spot_extra_request = 1` AND `_spot_extra_origin_order = $origin_order_id`
  + status: `['processing', 'completed']`
- برمی‌گرداند تعداد درخواست‌های paid قبلی.

**`spot_extra_has_pending(int $origin_order_id, int $user_id): bool`**
- همان query با status: `['pending', 'on-hold']` — برای جلوگیری از درخواست تکراری نیمه‌کاره.

**`spot_extra_origin_total(int $origin_order_id): float`**
- `$order = wc_get_order($origin_order_id)`
- مجموع `$item->get_product()->get_price() * $item->get_quantity()` برای آیتم‌های Product.

**`spot_extra_calc_price(int $origin_order_id): array`**
- مرحله = `spot_extra_count_paid_requests($origin_order_id) + 1`
- `$stages = get_option('spotplayer')['extra_stages'] ?? []`
- `$mode   = get_option('spotplayer')['extra_end_mode'] ?? 'max'`
- انتخاب پله:
  - اگر `$mode = max` و `$stage > count($stages)` → `['blocked' => true]`
  - اگر `$mode = repeat_last` و `$stage > count($stages)` → از آخرین پله استفاده کن
  - وگرنه: `$stages[$stage - 1]`
- محاسبه‌ی مبلغ:
  - `fixed`: مقدار عددی پله
  - `percent`: `$pct / 100 * spot_extra_origin_total($origin_order_id)`
- برمی‌گرداند: `['price' => float, 'stage' => int, 'blocked' => false]`

---

### فاز ۶ — هندلر سابمیت فرم
**فایل:** `includes/woocommerce/extra-access.php`

`add_action('template_redirect', 'spot_extra_handle_submit')`:

1. چک: `isset($_POST['spot_extra_submit'])` + `check_admin_referer('spot_extra_submit')`.
2. Sanitize: `$origin_id = absint($_POST['spot_extra_origin_order'])`، `$phone = spot_sms_normalize_phone(sanitize_text_field($_POST['spot_extra_phone']))`.
3. اعتبارسنجی (به ترتیب):
   - سفارش مبدأ باید متعلق به کاربر فعلی باشد (`$order->get_customer_id() === get_current_user_id()`).
   - سفارش مبدأ باید دارای `_spotplayer_data['_id']` باشد.
   - `spot_extra_has_pending($origin_id, $uid)` → اگر true: خطا «درخواست پرداخت‌نشده‌ای در انتظار دارید».
   - `$calc = spot_extra_calc_price($origin_id)` → اگر `blocked`: خطا «به سقف درخواست رسیده‌اید».
4. ساخت سفارش Fee:
   ```php
   $new_order = wc_create_order(['customer_id' => get_current_user_id()]);
   $fee = new WC_Order_Item_Fee();
   $fee->set_name('درخواست دسترسی اضافه — سفارش #' . $origin_id);
   $fee->set_amount($calc['price']);
   $fee->set_total($calc['price']);
   $new_order->add_item($fee);
   $new_order->set_billing_phone($phone);
   // copy billing info از کاربر
   $new_order->update_meta_data('_spot_extra_request',      1);
   $new_order->update_meta_data('_spot_extra_origin_order', $origin_id);
   $new_order->update_meta_data('_spot_extra_stage',        $calc['stage']);
   $new_order->calculate_totals();
   $new_order->save();
   ```
5. ریدایرکت به `$new_order->get_checkout_payment_url()`.
6. در صورت خطا: ذخیره‌ی پیام در transient کاربر و نمایش در فرم.

---

### فاز ۷ — داشبورد ادمین
**فایل:** `includes/admin/extra-access-dashboard.php` (جدید)

- `add_submenu_page('spotplayer', 'درخواست دسترسی اضافه', 'دسترسی اضافه', 'manage_options', 'spot-extra-access', 'spot_extra_admin_render')`
- **فچ داده:** `wc_get_orders` با `meta_query: _spot_extra_request = 1` + status paid + صفحه‌بندی `$per_page = 20`.
- **جدول** با ستون‌ها:
  - شماره درخواست (لینک به سفارش)
  - نام مشتری
  - موبایل
  - دوره‌ها (از سفارش مبدأ — `spot_woo_order_items($origin_order, true)` که آرایه‌ی WC_Product برمی‌گرداند)
  - مرحله (`_spot_extra_stage`)
  - مبلغ پرداختی
  - تاریخ ثبت
  - وضعیت پیامک (از متاهای `_spot_sms_msg1_status` / `_spot_sms_msg2_status` روی سفارش درخواست)
  - دکمه‌ی AJAX «📲 ارسال پیامک اطلاع‌رسانی»
- **فیلتر تاریخ** (از / تا) و **جستجو** بر اساس نام یا شماره.
- **AJAX handler** `spot_ajax_send_extra_sms()` (`action: spot_send_extra_sms`):
  - nonce: `spot_extra_sms_{request_order_id}`
  - صدا زدن `spot_sms_trigger_extra($request_order)` (فاز ۸)
  - پاسخ JSON موفق/خطا

---

### فاز ۸ — پیامک اختصاصی دسترسی اضافه
**فایل:** `includes/sms.php`

**`spot_sms_trigger_extra(WC_Order $request_order): void`**

- چک `spot_sms_is_enabled()`.
- phone از `$request_order->get_billing_phone()`.
- `$origin_id = (int) $request_order->get_meta('_spot_extra_origin_order')`
- `$origin_order = wc_get_order($origin_id)`
- `$key = (string) (((array) $origin_order->get_meta('_spotplayer_data'))['key'] ?? '')`
- اگر `$key` خالی بود → یادداشت خطا روی `$request_order` و return.
- ذخیره متاهای `_spot_sms_*` روی `$request_order` (با `spot_sms_init_order_meta`).
- ارسال async: `as_schedule_single_action(time() + 5, 'spot_sms_extra_msg1', ['order_id' => $request_order->get_id(), 'attempt' => 1])`.

**هندلرهای AS:**
- `add_action('spot_sms_extra_msg1', 'spot_sms_handle_extra_msg1', 10, 2)`:
  - قالب: `sms_template_extra`، متغیرها از سفارش مبدأ (نام، دوره‌ها) — با تابع `spot_sms_build_message`.
  - منطق retry تا ۸ بار (مشابه `spot_sms_handle_msg1`).
  - موفقیت → schedule `spot_sms_extra_msg2`.
- `add_action('spot_sms_extra_msg2', 'spot_sms_handle_extra_msg2', 10, 2)`:
  - متن = `$key` (کد لایسنس از سفارش مبدأ).
  - منطق retry تا ۸ بار (مشابه `spot_sms_handle_msg2`).

**تفاوت با جریان اصلی:**
- قالب مرحله ۱ = `sms_template_extra` (نه `sms_template`).
- کد لایسنس مرحله ۲ از سفارش **مبدأ** گرفته می‌شود، نه سفارش درخواست.

---

### فاز ۹ — گزارش روزانه به ادمین
**فایل:** `includes/woocommerce/extra-access.php`

**`spot_extra_schedule_daily_report(): void`** (اجرا در `init`):
- اگر Action Scheduler موجود است:
  - چک `as_next_scheduled_action('spot_extra_daily_report')`.
  - اگر نه: محاسبه‌ی timestamp بعدی از `extra_report_time` با `wp_timezone()` و `as_schedule_recurring_action(time_next, DAY_IN_SECONDS, 'spot_extra_daily_report')`.
- اگر AS موجود نیست: از WP-Cron با `wp_schedule_event` (daily) استفاده شود (ساعت دقیق تضمین نمی‌شود — نیاز به AS).
- **تغییر ساعت**: هر بار که تنظیمات ذخیره شود و `extra_report_time` تغییر کرده باشد، `as_unschedule_all_actions('spot_extra_daily_report')` + reschedule.

**`add_action('spot_extra_daily_report', 'spot_extra_handle_daily_report')`**:
- `$admin_phone = spot_sms_normalize_phone(get_option('spotplayer')['extra_admin_phone'] ?? '')`
- اگر phone خالی یا پیامک غیرفعال → return.
- شمارش:
  ```php
  wc_get_orders([
    'meta_query'   => [['key' => '_spot_extra_request', 'value' => '1']],
    'status'       => ['processing', 'completed'],
    'date_created' => (time() - DAY_IN_SECONDS) . '...' . time(),
    'limit'        => -1,
    'return'       => 'ids',
  ])
  ```
- اگر `count === 0` → return (بدون پیامک).
- متن: «سلام. در ۲۴ ساعت گذشته {count} درخواست دسترسی اضافه لایسنس ثبت شد. لطفاً داشبورد را بررسی کنید.»
- `spot_sms_send_raw($admin_phone, $text)`.

---

### فاز ۱۰ — یکپارچه‌سازی نهایی
**فایل‌های درگیر:** `spotplayer.php`، `changelog.md`

- در `spotplayer.php` دو `require_once` جدید:
  ```php
  require_once SPOTPLAYER_DIR . 'includes/woocommerce/extra-access.php';
  require_once SPOTPLAYER_DIR . 'includes/admin/extra-access-dashboard.php';
  ```
- در `register_activation_hook` موجود: اطمینان از اینکه `add_rewrite_endpoint('license-request', EP_PAGES)` قبل از `flush_rewrite_rules()` صدا زده شود.
- بامپ نسخه در `spotplayer.php` (Version header).
- بروزرسانی `changelog.md` با شرح کامل فیچر.
- **تست چک‌لیست قبل از commit:**
  - [ ] صفحه‌ی «درخواست دسترسی اضافه» در منوی حساب کاربری نمایش داده می‌شود.
  - [ ] دراپ‌داون فقط سفارش‌های دارای لایسنس نشان می‌دهد.
  - [ ] مبلغ با انتخاب هر سفارش بدون reload آپدیت می‌شود.
  - [ ] سابمیت فرم → سفارش Fee ساخته می‌شود → ریدایرکت به درگاه.
  - [ ] سفارش paid در داشبورد ادمین نمایش داده می‌شود.
  - [ ] دکمه‌ی «ارسال پیامک» → پیامک ۱ (متن `sms_template_extra`) و پیامک ۲ (کد لایسنس) ارسال می‌شود.
  - [ ] درخواست تکراری pending بلاک می‌شود.
  - [ ] در `extra_end_mode = max`، بعد از آخرین پله فرم بلاک می‌شود.
  - [ ] گزارش روزانه در ساعت تعیین‌شده ارسال می‌شود (اگر درخواستی وجود داشت).

---

## فایل‌های درگیر

- `includes/admin/settings.php` (ویرایش — فازهای ۱ و ۲)
- `includes/woocommerce/extra-access.php` (جدید — فازهای ۳، ۴، ۵، ۶، ۹)
- `includes/admin/extra-access-dashboard.php` (جدید — فاز ۷)
- `includes/sms.php` (ویرایش — فاز ۸)
- `spotplayer.php` (ویرایش — فاز ۱۰)
- `changelog.md` (ویرایش — فاز ۱۰)
