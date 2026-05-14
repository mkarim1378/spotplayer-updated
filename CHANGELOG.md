# تاریخچه تغییرات افزونه اسپات پلیر

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
