<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;

/**
 * The other language versions of a page, article, news entry, event or FAQ, as
 * terminal42/contao-changelanguage links them (v1.11.0). Reads only.
 *
 * Answers what an agent has to know before it says anything about other languages:
 * which versions are linked, in which language, under which root — and when none are,
 * which other roots exist, because a version nobody linked is still a version.
 */
#[AsCommand(name: 'contao:language:versions', description: 'The other language versions of a page, article, news entry, event or FAQ (changelanguage); reads only')]
class LanguageVersionsCommand extends AbstractReadCommand
{
    public function __construct(
        private readonly LanguageVersions $versions,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'One of ' . implode(', ', array_keys(LanguageVersions::TABLES)));
        $this->addArgument('id', InputArgument::REQUIRED, 'The record id');
    }

    protected function doExecute(): int
    {
        $table = (string) $this->input->getArgument('table');
        $id    = (int) $this->input->getArgument('id');

        if (!isset(LanguageVersions::TABLES[$table])) {
            return $this->outputError(\sprintf('Language versions exist for %s, not for "%s".', implode(', ', array_keys(LanguageVersions::TABLES)), $table));
        }
        if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
            return $this->outputError(\sprintf('Table %s is not installed here.', $table));
        }
        if ('tl_page' === $table && 'root' === $this->connection->fetchOne('SELECT type FROM tl_page WHERE id = ?', [$id])) {
            return $this->outputError(\sprintf('Page %d is a website root. Roots are not linked through languageMain but as fallback roots of different domains (languageRoot); `language check` reports their trees.', $id));
        }

        $found = $this->versions->of($table, $id);
        if (null === $found) {
            return $this->outputError(\sprintf('%s:%d not found.', $table, $id));
        }

        $own   = $found['record']['root'];
        $roots = array_values(array_filter($this->versions->roots(), static fn (array $r): bool => $r['id'] !== $own));

        $answer = ['table' => $table] + $found + ['otherRoots' => $roots];
        if (!$found['linked'] && [] !== $roots) {
            $answer['note'] = 'No version is linked to this record. The other website roots listed may still hold one without a link — do not say there is none; `language check` lists unlinked pages.';
        }

        $this->outputRecord($answer);

        return self::SUCCESS;
    }
}
