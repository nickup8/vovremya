<?php

namespace App\Enums;

enum PlatformPermission: string
{
    case DashboardView = 'dashboard.view';

    case UsersView = 'users.view';
    case UsersBlock = 'users.block';

    case SubscriptionsExtend = 'subscriptions.extend';

    case ImpersonationUse = 'impersonation.use';

    case PlansView = 'plans.view';
    case PlansUpdate = 'plans.update';

    case AuditView = 'audit.view';

    case PlatformAdminsManage = 'platform_admins.manage';
}
