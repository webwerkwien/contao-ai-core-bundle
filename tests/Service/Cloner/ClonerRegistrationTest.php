<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use PHPUnit\Framework\TestCase;

/**
 * A cloner for a plugin's table is registered only where that plugin is installed.
 *
 * PluginCommandRegistrationTest guards the commands; the cloners had no such guard —
 * deleting the line in services_newsletter.yaml would have left the suite green
 * (Fable review before v1.8.0, the same gap as NewsArchiveCloner before it).
 */
class ClonerRegistrationTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../config/';
    private const CLONERS = __DIR__ . '/../../../src/Service/Cloner/';

    /** Tables of optional Contao bundles, and the services file that registers their cloner. */
    private const PLUGIN_TABLES = [
        'tl_news_archive'       => 'services_news.yaml',
        'tl_calendar'           => 'services_calendar.yaml',
        'tl_faq_category'       => 'services_faq.yaml',
        'tl_newsletter_channel' => 'services_newsletter.yaml',
    ];

    /**
     * @return array<string, string> basename => supported table, for every concrete cloner
     */
    private function cloners(): array
    {
        $found = [];

        foreach (glob(self::CLONERS . '*Cloner.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match('/^(final )?class \w+/m', $source)) {
                continue; // abstract base, interface or trait
            }

            preg_match("/'(tl_[a-z_]+)' === \\\$table|return '(tl_[a-z_]+)';/", $source, $match, PREG_UNMATCHED_AS_NULL);
            $found[basename($file)] = $match[1] ?? $match[2] ?? '';
        }

        return $found;
    }

    private function isExcluded(string $basename): bool
    {
        preg_match_all('#^\s*-\s*\.\./src/Service/Cloner/(\S+\.php)\s*$#m', (string) file_get_contents(self::CONFIG . 'services.yaml'), $matches);

        foreach ($matches[1] as $pattern) {
            if (fnmatch($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }

    public function testThePluginClonersAreExcludedAndRegisteredWithTheirPlugin(): void
    {
        $checked = 0;

        foreach ($this->cloners() as $basename => $table) {
            $file = self::PLUGIN_TABLES[$table] ?? null;

            if (null === $file) {
                $this->assertFalse($this->isExcluded($basename), "$basename handles a core table and must stay auto-discovered");
                continue;
            }

            $this->assertTrue($this->isExcluded($basename), "$basename handles $table and must not be auto-discovered");

            $yaml = (string) file_get_contents(self::CONFIG . $file);
            $this->assertStringContainsString('\\Service\\Cloner\\' . substr($basename, 0, -4) . ': ~', $yaml, "$basename is not registered in $file");
            $this->assertStringContainsString("tags: ['contao_ai.entity_cloner']", $yaml, "$file does not tag its cloners");
            ++$checked;
        }

        $this->assertSame(\count(self::PLUGIN_TABLES), $checked, 'one cloner per optional bundle was found');
    }

    public function testTheScanFoundTheCoreClonersToo(): void
    {
        $cloners = $this->cloners();

        foreach (['PageCloner.php' => 'tl_page', 'FormCloner.php' => 'tl_form', 'ModuleCloner.php' => 'tl_module', 'LayoutCloner.php' => 'tl_layout'] as $basename => $table) {
            $this->assertSame($table, $cloners[$basename] ?? null);
        }

        $this->assertArrayNotHasKey('AbstractDcaCloner.php', $cloners);
    }
}
