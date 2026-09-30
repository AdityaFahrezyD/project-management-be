<?php

return [
    'login' => (int) env('RATE_LIMIT_LOGIN', 5),
    'login_ip' => (int) env('RATE_LIMIT_LOGIN_IP', 20),
    'read' => (int) env('RATE_LIMIT_READ', 120),
    'write' => (int) env('RATE_LIMIT_WRITE', 30),
    'recovery' => (int) env('RATE_LIMIT_RECOVERY', 5),
    'verification' => (int) env('RATE_LIMIT_VERIFICATION', 1),
    'summary_enabled' => (bool) env('SUMMARY_CACHE_ENABLED', true),
    'summary_ttl' => (int) env('SUMMARY_CACHE_TTL', 60),
];
