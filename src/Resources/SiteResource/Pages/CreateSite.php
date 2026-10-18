<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Resources\SiteResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Resources\SiteResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates the site for the current tenant, then opens its edit page with the snippet.
 */
class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    public static function getResource(): string
    {
        return SiteResource::getPlugin()->getSiteResource();
    }

    public static function canCreateAnother(): bool
    {
        return false;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SiteResource::normalizeFormData($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $site = new Site;
        $site->fill($data);
        $site->setTenant(SiteResource::getPlugin()->getOwningTenant());
        $site->save();

        return $site;
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getCreatedRecord();
        $resource = static::getResource();

        return $record !== null && $resource::canEdit($record) ? $resource::getUrl('edit', ['record' => $record]) : $resource::getUrl();
    }
}
