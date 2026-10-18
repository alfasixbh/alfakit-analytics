<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Resources\SiteResource\Pages;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitAnalytics\Resources\SiteResource;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    public static function getResource(): string
    {
        return SiteResource::getPlugin()->getSiteResource();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->icon('plus')];
    }
}
