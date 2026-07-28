# PrintFlow - Hostinger Deployment Guide

## Prerequisites
- Hostinger hosting account
- Domain: mrandmrsprintflow.com
- FTP/File Manager access
- MySQL database access

## Step 1: Prepare Database

1. Log in to Hostinger control panel (hPanel)
2. Go to **MySQL Databases**
3. Create a new database:
   - Database name: `u123456789_printflow` (or similar)
   - Username: `u123456789_admin` (or similar)
   - Password: Create a strong password
   - **Save these credentials!**

4. Import your database:
   - Go to **phpMyAdmin**
   - Select your database
   - Click **Import**
   - Upload your SQL dump file
   - Click **Go**

## Step 2: Configure Database Connection

1. Create a `.env` file in the root directory with:

```
PRINTFLOW_DB_HOST=localhost
PRINTFLOW_DB_USER=u123456789_admin
PRINTFLOW_DB_PASS=your_database_password
PRINTFLOW_DB_NAME=u123456789_printflow
PRINTFLOW_DB_PORT=3306
```

**OR** create `includes/db.local.php`:

```php
<?php
return [
    'host' => 'localhost',
    'user' => 'u123456789_admin',
    'pass' => 'your_database_password',
    'name' => 'u123456789_printflow',
    'port' => 3306,
];
```

## Step 2A: Configure Payment Receipt OCR

The primary hosted provider is OCR.Space. The application expects the exact
variable `PAYMENT_OCR_API_KEY`; `OCR_SPACE_API_KEY` is accepted only for legacy
deployments. Keep the private key in `public_html/.env`, which is ignored by Git
and blocked by the root `.htaccess`.

```dotenv
PAYMENT_OCR_PROVIDER=auto
PAYMENT_OCR_API_KEY=your_private_ocr_space_key
PAYMENT_OCR_API_URL=https://api.ocr.space/parse/image
PAYMENT_OCR_LANGUAGE=eng
PAYMENT_OCR_TIMEOUT_SECONDS=25
```

A free API key can be requested at `https://ocr.space/ocrapi/freekey`. Paid PRO
plans are optional. Do not use the public `helloworld` demonstration key in
production.

`auto` uses OCR.Space when the key is present and falls back to local Tesseract.
If the hosting account already provides Tesseract, add its absolute path:

```dotenv
PAYMENT_OCR_TESSERACT_PATH=/absolute/path/to/tesseract
```

Hostinger shared hosting normally does not permit system package installation.
When no Tesseract executable is available to PHP, use the OCR.Space key above.
Docker deployments install `tesseract-ocr`, English language data, PHP cURL,
GD, and EXIF automatically from the project `Dockerfile`.

After uploading the changed files, clear the Hostinger cache and sign in as an
Admin or online Staff user. Open this diagnostic before re-scanning submission
#35:

```text
https://mrandmrsprintflow.com/staff/api_payment_verification.php?action=ocr_diagnostic&submission_id=35
```

Confirm `file_readable` is `true` and either `env_api_key_set` or
`tesseract_available` is `true`. Then open Payment Verification and use
**Re-scan OCR**. A failed provider leaves the proof visible and the submission
in **Needs Review**.

## Step 3: Upload Files

### Option A: Using File Manager (Recommended)
1. Compress your `printflow` folder as ZIP
2. Log in to Hostinger hPanel
3. Go to **File Manager**
4. Navigate to `public_html`
5. Upload the ZIP file
6. Extract it
7. Move all files from `printflow` folder to `public_html` root
8. Delete the empty `printflow` folder

### Option B: Using FTP
1. Download FileZilla or use any FTP client
2. Connect using credentials from Hostinger
3. Navigate to `public_html`
4. Upload all files from your `printflow` folder to `public_html`

## Step 4: Set Permissions

Set the following folder permissions to **755**:
- `uploads/`
- `uploads/designs/`
- `uploads/products/`
- `uploads/payments/`
- `public/assets/uploads/`

Set the following folder permissions to **777** (if needed):
- `uploads/` (if 755 doesn't work)

## Step 5: Update Configuration

The system will automatically detect production environment based on domain.

If you need manual configuration, edit `config.php`:

```php
$is_production = true; // Force production mode
```

## Step 6: Test Your Site

1. Visit: https://mrandmrsprintflow.com
2. You should see the landing page
3. Test login: https://mrandmrsprintflow.com/public/login.php
4. Test admin: https://mrandmrsprintflow.com/admin/dashboard.php

## Step 7: SSL Certificate (HTTPS)

1. In Hostinger hPanel, go to **SSL**
2. Enable **Free SSL Certificate**
3. Wait 10-15 minutes for activation
4. Your site will automatically use HTTPS

## Step 8: Email Configuration (Optional)

For email notifications to work:

1. In Hostinger, go to **Email Accounts**
2. Create an email: `noreply@mrandmrsprintflow.com`
3. Update `includes/smtp_config.php`:

```php
<?php
return [
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 587,
    'smtp_user' => 'noreply@mrandmrsprintflow.com',
    'smtp_pass' => 'your_email_password',
    'smtp_secure' => 'tls',
    'from_email' => 'noreply@mrandmrsprintflow.com',
    'from_name' => 'PrintFlow'
];
```

## Troubleshooting

### Issue: "Database connection failed"
- Check database credentials in `.env` or `includes/db.local.php`
- Verify database exists in phpMyAdmin
- Ensure database user has all privileges

### Issue: "404 Not Found"
- Check `.htaccess` file is uploaded
- Verify Apache mod_rewrite is enabled (usually enabled on Hostinger)
- Check file permissions

### Issue: "500 Internal Server Error"
- Check PHP error logs in hPanel
- Verify file permissions (755 for folders, 644 for files)
- Check `.htaccess` syntax

### Issue: Images not loading
- Check `uploads/` folder permissions (755 or 777)
- Verify image paths in database
- Check file ownership

### Issue: Can't login
- Clear browser cache and cookies
- Check database connection
- Verify users table has admin account
- Check session configuration in PHP

## Important Notes

1. **Backup regularly**: Use Hostinger's backup feature
2. **Keep credentials secure**: Never commit `.env` or `db.local.php` to Git
3. **Monitor error logs**: Check hPanel > Error Logs regularly
4. **Update PHP version**: Use PHP 8.0 or higher for best performance
5. **Enable OPcache**: In hPanel > PHP Configuration for better performance

## Support

If you encounter issues:
1. Check Hostinger knowledge base
2. Contact Hostinger support (24/7 live chat)
3. Check PHP error logs in hPanel
4. Review Apache error logs

## Post-Deployment Checklist

- [ ] Database imported successfully
- [ ] Can access homepage
- [ ] Can login as admin
- [ ] Can login as customer
- [ ] Images loading correctly
- [ ] File uploads working
- [ ] Email notifications working (if configured)
- [ ] SSL certificate active
- [ ] All pages accessible
- [ ] Mobile responsive working
- [ ] PWA installable

## Security Recommendations

1. Change default admin password immediately
2. Use strong database password
3. Keep `.env` file secure (not web-accessible)
4. Enable Hostinger's security features
5. Regular backups
6. Monitor access logs
7. Keep PHP and dependencies updated

---

**Deployment Date**: _____________
**Database Name**: _____________
**Admin Email**: _____________
**Notes**: _____________
