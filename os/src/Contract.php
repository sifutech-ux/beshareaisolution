<?php
declare(strict_types=1);

namespace BesharOs;

final class Contract
{
    public const VERSION = '0001';
    public const NAME = 'foundation';
    public const DATABASE = 'u879723783_beshare_os';
    public const LOCK = 'beshare_os_foundation_0001';
    public const CONFIRM = '--confirm-foundation-0001';

    public const TABLES = [
        'schema_migrations',
        'users',
        'businesses',
        'business_users',
        'whatsapp_connections',
        'onboarding_sessions',
        'webhook_events',
        'audit_logs',
    ];

    public const DROP_TABLES = [
        'webhook_events',
        'onboarding_sessions',
        'whatsapp_connections',
        'audit_logs',
        'business_users',
        'businesses',
        'users',
        'schema_migrations',
    ];

    public const TRIGGERS = [
        'bi_businesses_active_owner',
        'bi_business_users_owner',
        'bu_business_users_owner',
        'bd_business_users_owner',
        'bu_businesses_active_owner',
    ];
}
