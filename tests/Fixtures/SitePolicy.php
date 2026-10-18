<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Tests\Fixtures;

use AlfaSix\AlfakitAnalytics\Models\Site;

class SitePolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Site $site): bool
    {
        return $site->name !== 'Hidden';
    }
}
