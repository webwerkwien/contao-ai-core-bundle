<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\ContentModel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'contao:content:update', description: 'Update a content element')]
class ContentUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return ContentModel::class; }
    protected function entityName(): string { return 'Content element'; }

    /**
     * tl_content.headline is the same input-unit field shape as tl_news.headline
     * (see NewsUpdateCommand). Wrap a raw string into the canonical
     * `{unit, value}` serialized form, preserving any existing unit.
     */
    protected function preProcessFields(array $fields, object $record): array
    {
        if (\array_key_exists('headline', $fields) && \is_string($fields['headline'])) {
            $unit = 'h2';
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
