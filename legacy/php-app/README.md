# PHP POS Starter

A lightweight PHP POS for XAMPP that monitors stock/inventory, sales & profits, product search, and a credit section (plus expenses + reports).

## Quick Start (XAMPP on Windows/Mac/Linux)

1. Open **phpMyAdmin** and run `migrate.sql` to create the database and tables.
2. Copy the `php-pos-starter` folder into your XAMPP `htdocs` directory.
3. Edit `config.php` if your MySQL username/password differ.
4. Visit: `http://localhost/php-pos-starter/public/?page=dashboard`

## Features
- Products: add items, update stock.
- Sales: add line items, automatic stock decrement, cash/credit.
- Credits: track outstanding balances, record payments.
- Expenses: log business expenses.
- Reports: daily sales and profit; date filter.
- Search: quick product search bar in the top nav.

## Notes
- This is a minimal starter meant for local/offline use. Add authentication, validations, and roles before production.
- Prices and currency labels use UGX by default (change in templates).
