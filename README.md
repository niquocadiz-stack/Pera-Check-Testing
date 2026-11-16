# FinTrack – Personal Financial Expense Tracker (PHP + MySQL)

Pure PHP (PDO) backend, MySQL, HTML/CSS, vanilla JavaScript and Chart.js. No frameworks.

## Setup on XAMPP

1. Copy the `financial-expense-tracker` folder to `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open http://localhost/phpmyadmin → **Import** → choose `database/financial_tracker.sql` → Go.
   (This creates the `financial_tracker` database and all tables.)
4. If your MySQL user/password differ from `root` / *(empty)*, edit `config/database.php`.
5. Open http://localhost/financial-expense-tracker/ and click **Create an account**.
   Default categories (Salary, Food, …) are created automatically for every new user.

Charts load Chart.js from the jsDelivr CDN, so the browser needs internet access.
For fully offline use, download `chart.umd.min.js` into `assets/js/` and change the `<script>` URL in `includes/footer.php`.

## How the numbers work

- **Total Balance** = total income − total expenses − money currently set aside in savings goals.
  Adding money to a goal lowers the balance; withdrawing or deleting a goal returns it.
- **Remaining Budget** (dashboard) = this month's overall budget minus all spending, or, if you only set
  category budgets, the sum of those budgets minus spending in those categories.
- **Budget alerts** are created once at 75%, 90% and 100%. Savings-goal and "spending higher than last month"
  alerts are also created once.
- Changing the currency in Settings changes display only; amounts are not converted.

## Security measures

`password_hash`/`password_verify`, PDO prepared statements everywhere, per-user ownership checks on every query,
CSRF tokens on every POST (logout included), output escaping with `htmlspecialchars`, hardened session cookie
(HttpOnly, SameSite, strict mode, ID regenerated on login), idle timeout, simple login lockout, CSP and other
security headers, validated image uploads (MIME + image check, random file name, script execution blocked in the
uploads folder by `.htaccess`), CSV formula-injection protection on export.

## Structure

```
config/database.php        DB credentials, PDO connection
includes/                  functions.php, header/sidebar/footer, txn_page.php (shared income/expense CRUD)
auth/                      login, register, logout
pages/                     dashboard, income, expenses, budgets, savings, transactions, reports, notifications, settings
assets/css, assets/js      style.css, app.js (modals, charts)
database/financial_tracker.sql
```
