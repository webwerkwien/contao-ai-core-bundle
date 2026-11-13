<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Copier\RecordCopier;

#[AsCommand(name: 'contao:content:copy', description: 'Copy a content element with its nested elements, as the back end does: behind the source, --after a sibling or --to a parent')]
class ContentCopyCommand extends AbstractWriteCommand implements CreatesRecord
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
        return 'tl_content';
    }

    protected function entityName(): string
    {
        return 'Content element';
    }

    protected function hasPtableOption(): bool
    {
        return true;
    }
}
