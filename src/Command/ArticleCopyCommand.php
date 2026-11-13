<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\RecordCopier;

#[AsCommand(name: 'contao:article:copy', description: 'Copy a Contao article with its content elements, as the back end does: behind the source, --after an article or --to a page')]
class ArticleCopyCommand extends AbstractWriteCommand implements CreatesRecord
{
    use CopiesRecord;

    public function __construct(
        protected readonly ContaoFramework $framework,
        private readonly RecordCopier $recordCopier,
    ) {
        parent::__construct();
    }

    protected function copier(): RecordCopier
    {
        return $this->recordCopier;
    }

    protected function copyTable(): string
    {
        return 'tl_article';
    }

    protected function entityName(): string
    {
        return 'Article';
    }

    /**
     * Contao resets `author` to its closure default, the acting user — a default the
     * copier cannot resolve without a back-end request, so it is given here. `alias`
     * stays empty and `published` off, as after a copy in the back end: Contao makes
     * the alias when the edit form is saved, and links the article by its ID until then.
     */
    protected function copyOverrides(array $source, array $position): array
    {
        return ['author' => $this->resolveAuthorId()];
    }
}
