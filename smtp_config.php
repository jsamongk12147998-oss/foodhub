<?php
// smtp_config.php

// ✅ Only define constants if they are not already defined
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'limhansjomel@gmail.com'); // Your real Gmail
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'strileckavrxylyi'); // Your real App Password
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'limhansjomel@gmail.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'UMAK FoodHub');
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'tls');
?>