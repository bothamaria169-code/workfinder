<?php
// WorkFinder secrets — copy to config/secrets.php and fill in.
// config/secrets.php is in .gitignore: NEVER commit it. Upload it once to the
// server with cPanel File Manager; git deploys leave it alone.
return [
    // Live database (cPanel → MySQL Databases)
    'db_host' => 'localhost',
    'db_name' => 'swiftfusionco_workfinder',
    'db_user' => 'swiftfusionco_workfinder',
    'db_pass' => 'CHANGE_ME',

    // Local XAMPP database (used automatically on localhost)
    'local_db_name' => 'swiftfusionco_workfinder',
    'local_db_user' => 'root',
    'local_db_pass' => '',

    // Public address of the live app, no trailing slash
    'app_url' => 'https://workfinder.swiftfusion.co.za',

    // Admin account: created on first page load if it doesn't exist yet.
    // Every sign-up is also copied to this address.
    'admin_email'         => 'admin@swiftfusion.co.za',
    'admin_seed_password' => 'CHANGE_ME',

    // Locked out of admin? Set a new password here and load any page once.
    // It's applied one time (and also makes the addresses below admins).
    'admin_reset_password' => '',
    'admin_reset_emails'   => [], // e.g. ['you@gmail.com']

    // Mailbox that sends applications, alerts and system emails (cPanel → Email Accounts).
    // Leave smtp_pass blank to use the server's PHP mail() instead.
    'smtp_host' => 'mail.swiftfusion.co.za',
    'smtp_port' => 465,
    'smtp_user' => 'admin@swiftfusion.co.za',
    'smtp_pass' => '',

    // Long random string: signs CV links and email-open pixels.
    'app_secret' => 'make-a-long-random-string',
    // Random string for the URL cron (cron.php?key=...)
    'cron_key'   => 'make-a-random-string',
];
