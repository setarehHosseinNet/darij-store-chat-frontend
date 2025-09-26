# Darij – Store Chat Frontend Console

نمایش و مدیریت **صندوق گفتگوها (Inbox)** و **صفحه پاسخ (Reply)** در فرانت‌اند وردپرس.
این افزونه مخصوص سایت‌هایی است که با المنتور یا گوتنبرگ ساخته شده‌اند و می‌خواهند گفتگوی کاربران با مدیر/اپراتور را در فرانت‌اند نمایش و مدیریت کنند.

---

## ✨ ویژگی‌ها

* 📥 نمایش صندوق ورودی گفتگوها در یک جدول
* 👤 ستون جداگانه برای نام کاربری (User Login)
* 📩 نمایش آخرین پیام هر گفتگو
* 🔔 برچسب «خوانده نشده» برای نخ‌های جدید
* 💬 نمایش تاریخچه کامل گفتگوها در صفحه پاسخ
* 📝 فرم پاسخ سریع با AJAX (REST API + Nonce)
* 🎨 استایل RTL و هماهنگ با فونت‌های سایت
* ⚡ قابل استفاده در المنتور / گوتنبرگ با شورتکدها

---

## 🛠️ نصب و فعال‌سازی

1. کل ریپوزیتوری را کلون کنید یا فایل ZIP دانلود کنید:

   ```bash
   git clone https://github.com/setarehHosseinNet/darij-store-chat-frontend.git
   ```
2. پوشه را در مسیر:

   ```
   wp-content/plugins/darij-store-chat-frontend/
   ```

   قرار دهید.
3. از پیشخوان وردپرس → افزونه‌ها → **Darij – Store Chat Frontend Console** را فعال کنید.

---

## 🚀 استفاده

### شورتکدها

* `[drj_chat_inbox reply_page="/drj_chat_reply/"]`
  نمایش صندوق ورودی گفتگوها. (reply_page = مسیر یا URL برگه پاسخ)

* `[drj_chat_reply thread="3073"]`
  نمایش گفتگو و فرم پاسخ برای نخ خاص (اختیاری: اگر thread داده نشود، انتخاب‌گر نمایش داده می‌شود).

### مثال در المنتور

* یک بخش جدید بسازید.
* ویجت "کد کوتاه" اضافه کنید.
* شورتکد `[drj_chat_inbox]` یا `[drj_chat_reply]` را وارد کنید.

---

## 📂 ساختار

```
darij-store-chat-frontend/
│
├── darij-store-chat-frontend.php   # فایل اصلی افزونه
├── README.md                       # مستندات
└── LICENSE                         # لایسنس GPLv2
```

---

## 🔐 دسترسی

* فقط کاربران لاگین شده با نقش **مدیر (Administrator)** یا **مدیر فروشگاه (Shop Manager)** می‌توانند:

  * صندوق ورودی را ببینند
  * پیام‌ها را پاسخ دهند

---

## 📸 پیش‌نمایش

(اینجا می‌توانید بعدا اسکرین‌شات‌های پلاگین را اضافه کنید)

---

## 📜 لایسنس

GPLv2 or later – Free & Open Source

---

## 🌍 English Version

### Darij – Store Chat Frontend Console

A lightweight **WordPress plugin** to display and manage **chat inbox** and **reply console** on the **frontend**.
Perfect for Elementor / Gutenberg sites where operators need to handle user chats outside the WordPress admin panel.

#### Features

* 📥 Chat inbox table with latest messages
* 👤 User login displayed as thread title
* 🔔 "Unread" badge for new messages
* 💬 Full conversation history in reply page
* 📝 Quick reply form with AJAX (REST API + Nonce)
* 🎨 RTL-friendly styles, matches site fonts
* ⚡ Easy to use via shortcodes

#### Installation

```bash
git clone https://github.com/setarehHosseinNet/darij-store-chat-frontend.git
```

Upload into:

```
wp-content/plugins/darij-store-chat-frontend/
```

Then activate in **WordPress Admin → Plugins**.

#### Shortcodes

* `[drj_chat_inbox reply_page="/drj_chat_reply/"]` → Show chat inbox.
* `[drj_chat_reply thread="3073"]` → Show a specific thread or allow selection.

---

👨‍💻 Developed by [hossein setareh](https://github.com/hosseinsetarh)
