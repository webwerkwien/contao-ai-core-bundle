<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:news:update', description: 'Update a news entry')]
class NewsUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsModel::class; }
    protected function entityName(): string { return 'News entry'; }

    /**
     * tl_news.headline is a Contao input-unit field stored as a serialized
     * array `{"unit": "h1"|"h2"|…, "value": "<text>"}`. NewsCreateCommand
     * already wraps the plain string on insert; updates went through as raw
     * strings until 2026-05-01 (live-test finding) which broke the listing
     * renderer. Wrap on write so create and update produce identical column
     * shapes; keep the existing unit if a previous serialized value is found.
     */
    protected function preProcessFields(array $fields, object $record): array
    {
        if (\array_key_exists('headline', $fields) && \is_string($fields['headline'])) {
            $unit = 'h1';
            $current = $record->headline ?? null;
            if (\is_string($current) && '' !== $current) {
                $decoded = @unserialize($current, ['allowed_classes' => false]);
                if (\is_array($decoded) && isset($decoded['unit']) && \is_string($decoded['unit'])) {
                    $unit = $decoded['unit'];
                }
            }
            $fields['headline'] = serialize(['unit' => $unit, 'value' => $fields['headline']]);
        }
        return $fields;
    }
}
