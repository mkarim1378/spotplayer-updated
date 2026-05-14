# تاریخچه تغییرات افزونه اسپات پلیر

## [20.6] - 1404-02-25

### رفع باگ
- **بحرانی:** `flush_rewrite_rules()` از هوک `init` حذف شد و به هوک فعال‌سازی افزونه منتقل شد — این باگ باعث کند شدن شدید هر صفحه سایت می‌شد
- **بحرانی:** رفع باگ کد Exception اشتباه (`new Exception('999')`) که باعث می‌شد لینک دیباگ در یادداشت سفارش هیچوقت نمایش داده نشود
- **بالا:** رفع Fatal Error در `spot_woo_shortcode` هنگامی که شناسه سفارش (`spo`) نامعتبر بود و `wc_get_order()` مقدار `false` برمی‌گرداند
- **بالا:** رفع crash در `spot_shop_x` هنگامی که کوکی `X` وجود نداشت
- **متوسط:** رفع XSS در دکمه کپی کلید لایسنس — خروجی `$data['key']` با `esc_js()` و `esc_html()` ایمن شد
- **متوسط:** تمام دسترسی‌های `$_POST` در ادمین WooCommerce و EDD با `isset()` و `sanitize_text_field()` ایمن شدند
- **متوسط:** اعتبارسنجی پارامتر `spp` در `spot_woo_shortcode` قبل از فراخوانی `wc_get_product()` اضافه شد
- **پایین:** مقدار `href` در لیست دوره‌ها با کوتیشن و `esc_url()` ایمن شد



## [20.5] - 1404-02-25

### بازسازی ساختار (Refactor)
- فایل اصلی `index.php` از ۱۵۶۰ خط به یک فایل ورودی تمیز ۴۴ خطی تبدیل شد
- کد به ۱۵ ماژول مستقل در پوشه `includes/` تقسیم شد:
  - `includes/helpers.php` — توابع کمکی مشترک
  - `includes/api.php` — ارتباط با API اسپات پلیر
  - `includes/admin/notices.php` — مدیریت پیام‌های ادمین
  - `includes/admin/order-box.php` — باکس سفارش مشترک بین WooCommerce و EDD
  - `includes/admin/settings.php` — صفحه تنظیمات کامل ادمین
  - `includes/woocommerce/functions.php` — منطق ساخت لایسنس WooCommerce
  - `includes/woocommerce/admin-product.php` — تب اسپات پلیر در صفحه محصول
  - `includes/woocommerce/admin-order.php` — متا باکس اسپات پلیر در صفحه سفارش
  - `includes/woocommerce/shop.php` — نمایش frontend و شورت‌کد WooCommerce
  - `includes/edd/functions.php` — منطق ساخت لایسنس EDD
  - `includes/edd/admin.php` — بخش ادمین EDD
  - `includes/edd/shop.php` — نمایش frontend EDD
  - `includes/frontend/css.php` — لود کردن استایل‌ها
  - `includes/frontend/display.php` — نمایش پلیر، شورت‌کد و URL handler
  - `includes/bulk/create-orders.php` — ایجاد سفارش و لایسنس دسته‌ای از CSV
  - `includes/bulk/manage-licenses.php` — غیرفعال‌سازی و ایجاد لایسنس دسته‌ای
- فایل‌های asset به ساختار منظم منتقل شدند:
  - `assets/css/` — فایل‌های استایل ادمین و فرانت‌اند
  - `assets/fonts/` — فونت اختصاصی افزونه
  - `assets/svg/` — آیکون‌های SVG
- مسیر فونت در فایل‌های CSS اصلاح شد
- دو ثابت `SPOTPLAYER_DIR` و `SPOTPLAYER_URL` برای مدیریت مسیرها تعریف شد
- هیچ تغییری در عملکرد افزونه ایجاد نشده است
