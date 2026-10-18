<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Resources\SiteResource\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Resources\SiteResource;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    public static function getResource(): string
    {
        return SiteResource::getPlugin()->getSiteResource();
    }

    protected function getHeaderActions(): array
    {
        $page = SiteResource::getPlugin()->getPage();

        return [
            Action::make('dashboard')
                ->label(__('alfakit-analytics::analytics.sites.open_dashboard'))
                ->icon('chart-line')
                ->button()
                ->url(fn (): string => $page::getUrl(['site' => $this->getRecord()?->getKey()])),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SiteResource::normalizeFormData($data);
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        // Turning sharing off and on again issues a new link.
        if ($record instanceof Site && $record->wasChanged('is_shared') && ! $record->is_shared) {
            $record->rotateShareToken();
        }
    }
}
