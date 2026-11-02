<?php
declare(strict_types=1);

// Every AJAX endpoint: signed in, POST, valid CSRF token.
require_once dirname(__DIR__) . '/includes/applications.php';

startAppSession();
if (!isLoggedIn() || !($AJAX_USER = getCurrentUser())) jsonResponse(['ok' => false, 'error' => 'Please sign in again.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValid()) jsonResponse(['ok' => false, 'error' => 'Session expired — refresh the page.'], 419);
