<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * A command that needs an optional Contao bundle must be registered conditionally.
 *
 * `services.yaml` auto-discovers `../src`, and excludes the commands that depend
 * on `news-bundle`, `calendar-bundle`, `faq-bundle` or `comments-bundle` by
 * filename pattern. `ContaoAiCoreBundle::loadExtension()` then loads
 * `services_<bundle>.yaml` only when that bundle is actually installed, so an
 * installation without news does not get a command whose model class is absent.
 *
 * 🔴 **The patterns are filenames, and that is exactly how it broke.** On
 * 2026-08-31 four commands for `tl_calendar` were added as `Calendar*Command`
 * — the exclusion for the calendar bundle reads `Event*.php`, because that is
 * what its existing commands are called. The four slipped through the filter
 * and were registered on every installation, including ones with no calendar
 * bundle at all. The live check found it: of twelve new commands, exactly four
 * were registered, and those four were the wrong ones.
 *
 * The eight that were correctly excluded were then registered nowhere, which is
 * the harmless direction to fail in — but neither half is visible from reading
 * the YAML.
 *
 * So the rule is not "remember to add the pattern": a command referencing a
 * plugin-only model must be excluded from auto-discovery **and** listed in the
 * matching `services_*.yaml`, and this test says so by name.
 */
class PluginCommandRegistrationTest extends TestCase
{
    /**
     * Models that only exist when their optional bundle is installed.
     *
     * @var array<string, list<string>> services file suffix => model class names
     */
    private const PLUGIN_MODELS = [
        'news'     => ['NewsModel', 'NewsArchiveModel'],
        'calendar' => ['CalendarModel', 'CalendarEventsModel', 'CalendarFeedModel'],
        'faq'      => ['FaqModel', 'FaqCategoryModel'],
        'comments' => ['CommentsModel'],
        // NewsletterModel steht bewusst hinter den Kanal- und Empfänger-Modellen:
        // die Erkennung nimmt den ersten Treffer, und NewsletterCreateCommand
        // referenziert beide. Für die Zuordnung zum Suffix ist das egal — alle
        // drei zeigen auf services_newsletter.yaml.
        'newsletter' => ['NewsletterChannelModel', 'NewsletterRecipientsModel', 'NewsletterDenyListModel', 'NewsletterModel'],
    ];

    private function commandDir(): string
    {
        return __DIR__ . '/../../src/Command';
    }

    private function configDir(): string
    {
        return __DIR__ . '/../../config';
    }

    /**
     * @return list<string> exclude patterns from services.yaml, as basenames
     */
    private function excludePatterns(): array
    {
        $yaml     = (string) file_get_contents($this->configDir() . '/services.yaml');
        $patterns = [];

        preg_match_all('#^\s*-\s*\.\./src/Command/(\S+\.php)\s*$#m', $yaml, $matches);
        foreach ($matches[1] as $pattern) {
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    private function isExcluded(string $basename): bool
    {
        foreach ($this->excludePatterns() as $pattern) {
            if (fnmatch($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> basename => services file suffix
     */
    private function pluginDependentCommands(): array
    {
        $files = glob($this->commandDir() . '/*Command.php');
        $this->assertIsArray($files);

        $found = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::PLUGIN_MODELS as $suffix => $models) {
                foreach ($models as $model) {
                    if (str_contains($source, 'Contao\\' . $model)) {
                        $found[basename($file)] = $suffix;
                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    public function testEveryPluginDependentCommandIsExcludedFromAutoDiscovery(): void
    {
        $leaked = [];
        foreach ($this->pluginDependentCommands() as $basename => $suffix) {
            if (!$this->isExcluded($basename)) {
                $leaked[] = "$basename (needs $suffix-bundle)";
            }
        }

        $this->assertSame([], $leaked, \sprintf(
            "These commands reference a model from an optional Contao bundle but are not\n"
            . "excluded in config/services.yaml, so auto-discovery registers them on every\n"
            . "installation — including ones where that bundle is absent.\n"
            . 'Add a matching pattern under the exclude: key.',
        ));
    }

    public function testEveryExcludedCommandIsRegisteredInItsPluginServicesFile(): void
    {
        $missing = [];
        foreach ($this->pluginDependentCommands() as $basename => $suffix) {
            $file = $this->configDir() . "/services_$suffix.yaml";
            $this->assertFileExists($file);

            $class = str_replace('.php', '', $basename);
            if (!str_contains((string) file_get_contents($file), $class)) {
                $missing[] = "$basename -> services_$suffix.yaml";
            }
        }

        $this->assertSame([], $missing, \sprintf(
            "These commands are excluded from auto-discovery and registered nowhere, so\n"
            . 'they exist on disk and cannot be called. Add them to their services file.',
        ));
    }

    /**
     * A scan that finds nothing passes just as quietly as a scan that finds
     * everything — the lesson from 2026-08-30, applied to this one too.
     */
    public function testTheScanFoundBothCommandsAndPatterns(): void
    {
        $this->assertGreaterThanOrEqual(
            25,
            \count($this->pluginDependentCommands()),
            'Plugin-dependent commands were not found — the model detection has drifted.',
        );
        $this->assertGreaterThanOrEqual(
            4,
            \count($this->excludePatterns()),
            'No exclude patterns parsed out of services.yaml — the format has changed.',
        );
    }
}
