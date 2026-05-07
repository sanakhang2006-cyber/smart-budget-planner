# 💰 SmartBudget Pro

> A smart personal finance manager — track income, expenses, savings goals, and bill reminders all in one place.

---

## 📋 About

**SmartBudget Pro** is a PHP-based personal finance management system that helps you track daily income and expenses, set savings goals, manage bill reminders, and monitor your monthly budget. It also supports Google Sign-In for quick and easy login.

---

## ✨ Features

- 🔐 **User Authentication** — Login via email/password or Google Sign-In
- 💵 **Income Tracking** — Record income across multiple categories (Salary, Freelance, Investments, etc.)
- 💸 **Expense Tracking** — Log expenses by cash, card, or bank transfer
- 📁 **Custom Categories** — Create your own income/expense categories with icons and colors
- 👛 **Wallets** — Track cash, card, and bank balances separately
- 🎯 **Savings Goals** — Set goals and monitor progress (e.g. Emergency Fund, Laptop, Vacation)
- 📅 **Monthly Budgets** — Set a spending budget for each month
- 🔁 **Recurring Transactions** — Automatically handle monthly recurring income and expenses
- 🔔 **Reminders** — Set bill payment reminders so you never miss a due date
- 📧 **Email Notifications** — Budget alerts and reminders sent via Gmail SMTP (PHPMailer)
- 👑 **Admin Panel** — User management via admin role

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 7.4+ |
| Database | MySQL / MariaDB |
| Email | PHPMailer (Gmail SMTP) |
| Auth | Google OAuth 2.0 (OpenID Connect) |
| Frontend | HTML, CSS, JavaScript |
| Default Currency | PKR |

---

## ⚙️ Installation

### 1. Requirements

- PHP 7.4 or higher (cURL extension required)
- MySQL 5.7+ or MariaDB
- Web server running on **localhost:8080** (XAMPP, WAMP, or similar)
- Gmail account (for email notifications)

### 2. Database Setup

```sql
-- Run this in your MySQL client:
source database.sql
```

This will automatically create all tables and insert demo data.

### 3. Config File

Update `includes/config.php` with your settings:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'smartbudget');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');

// Google OAuth
define('GOOGLE_CLIENT_ID', 'your-client-id.apps.googleusercontent.com');

// Email (Gmail SMTP)
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_USER', 'your@gmail.com');
define('MAIL_PASS', 'your_app_password');
```

### 4. Google Sign-In Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project
3. Generate an **OAuth 2.0 Client ID**
4. Add the following to **Authorized redirect URIs**:
   ```
   http://localhost:8080/smartbudget/index.php
   ```
5. Paste the Client ID into `config.php`

### 5. PHPMailer Setup

```bash
composer install
```

Or manually place the `phpmailer/src/` folder inside your project directory.

---

## 🚀 Running the App

Once installed, open your browser and navigate to:

```
http://localhost:8080/smartbudget/index.php
```

---

## 🔑 Demo Credentials

| Role | Email | Password |
|------|-------|----------|
| User | `demo@budget.com` | `demo123` |
| Admin | `admin@smartbudget.com` | `demo123` |

---

## 📁 Project Structure

```
smartbudget/
├── index.php              # Main application file
├── database.sql           # Database schema + demo data
├── diagnose.php           # Google Sign-In diagnostic tool
├── google-test.html       # Google OAuth test page
├── test_email.php         # Email sending test
├── includes/
│   └── config.php         # Database & API configuration
└── phpmailer/
    └── src/               # PHPMailer library
```

---

## 🐛 Troubleshooting

**Google Sign-In not working?**
Open `http://localhost:8080/smartbudget/diagnose.php` in your browser — it will show you exactly what is misconfigured.

**Emails not sending?**
Run `test_email.php` to test your mail setup. If you use Gmail with 2FA enabled, you must generate an **App Password** — your regular Gmail password will not work.

**Database error on startup?**
Verify that `database.sql` was imported successfully and that the credentials in `config.php` are correct.

---

## 📄 License

Free for personal use. PHPMailer is distributed under the [LGPL 2.1 license](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html).

---

> SmartBudget Pro v4.0
