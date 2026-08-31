<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Every create command that accepts `--set` must convert the fields it stores.
 *
 * 🎯 This test exists because the same omission happened twice, in the same
 * shape, six days apart. `convertFileTreeFields()` arrived with v0.2.15 and
 * `convertMultipleFields()` with v0.2.18, and both were wired into the update
 * path and then into whichever create command was being written that day. On
 * 2026-08-31 the count was: eleven create commands take `--set`, four converted
 * fileTree values, one converted multi-value fields.
 *
 * The consequence is invisible at the call site. A UUID written as a string
 * into a binary column and a list written as a bare string both report success
 * and store something Contao reads as nothing.
 *
 * So the rule is not "remember to call convertFields()" — it is this test.
 * A new create command that applies `--set` fields to a record and does not
 * convert them fails here by name.
 *
 * It scans source rather than behaviour on purpose: the alternative is a live
 * write per command against a mocked model layer, which would test the mock.
 * What actually goes wrong is a missing line, and a missing line is what this
 * looks for.
 */
class CreateCommandConversionTest extends TestCase
{
    /**
     * @return array<string, string> file name => source
     */
    private function createCommandSources(): array
    {
        $files = glob(__DIR__ . '/../../src/Command/*CreateCommand.php');
        $this->assertIsArray($files);

        $sources = [];
        foreach ($files as $file) {
            $sources[basename($file)] = (string) file_get_contents($file);
        }

        return $sources;
    }

    /**
     * A command is in scope when it does something with `--set` fields beyond
     * declaring them: the signature mentions `$fields` once, so anything that
     * also writes them mentions it more than once.
     *
     * FolderCreateCommand is the deliberate exception this rule expresses
     * without naming it — it creates a directory, not a record, and touches
     * `$fields` only in its signature.
     *
     * @return list<string>
     */
    private function inScope(array $sources): array
    {
        $names = [];
        foreach ($sources as $name => $source) {
            if (!str_contains($source, 'doExecute(array $fields)')) {
                continue;
            }
            if (substr_count($source, '$fields') < 2) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    public function testEveryCreateCommandThatStoresSetFieldsConvertsThem(): void
    {
        $sources = $this->createCommandSources();
        $inScope = $this->inScope($sources);

        $missing = array_values(array_filter(
            $inScope,
            static fn (string $name): bool => !str_contains($sources[$name], '$this->convertFields('),
        ));

        $this->assertSame([], $missing, \sprintf(
            "These create commands write --set fields without converting them.\n"
            . "Add \$fields = \$this->convertFields('tl_…', \$fields); before the field loop.\n"
            . 'Without it a fileTree UUID is stored as a string and a multi-value field '
            . 'as a bare value — both report success and read back as nothing.',
        ));
    }

    /**
     * A scan that finds nothing passes just as quietly as a scan that finds
     * everything. On 2026-08-31 a sweep over zero tables reported "no failures"
     * for exactly this reason, so the count is asserted, not assumed.
     */
    public function testTheScanActuallyFoundCommands(): void
    {
        $sources = $this->createCommandSources();

        $this->assertGreaterThanOrEqual(11, \count($sources), 'Create commands were not found on disk.');
        $this->assertGreaterThanOrEqual(
            10,
            \count($this->inScope($sources)),
            'The in-scope filter matched almost nothing — it has drifted away from how the commands are written.',
        );
    }

    /**
     * The update path takes the same call, so the two halves cannot drift into
     * converting different things.
     */
    public function testTheUpdatePathUsesTheSameEntryPoint(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractModelUpdateCommand.php');

        $this->assertStringContainsString('$this->convertFields(', $source);
    }
}
