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
 *
 * ⚠️ **Sharpened on 2026-09-01: the call has to be `preparedFields()`.**
 * `convertFields()` sees only the `--set` pairs. Every create command also has
 * options of its own — `--name`, `--title`, `--email` — and those were assigned
 * straight onto the model afterwards, past every rule. `theme create --name
 * "Contao Official Demo"` made a second theme under a name `eval.unique`
 * forbids, one day after the unique check was added and believed to cover
 * creates. `preparedFields()` is the entry point that takes both halves.
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
            static fn (string $name): bool => !str_contains($sources[$name], '$this->preparedFields('),
        ));

        $this->assertSame([], $missing, \sprintf(
            "These create commands do not run their fields through preparedFields().\n"
            . "Use \$fields = \$this->preparedFields('tl_…', ['field' => \$value, …], \$fields);\n"
            . "so the command's own options are judged and converted with the --set pairs.\n"
            . 'Calling convertFields() directly is not enough: it sees only --set, and an '
            . 'option assigned straight onto the model skips every DCA rule — that is how '
            . '`theme create --name <existing>` made a duplicate on 2026-09-01.',
        ));
    }

    /**
     * Nothing may reach the record except through the prepared field set.
     *
     * 🎯 **The lexical rule above can be satisfied and still leave the hole
     * open.** A command can call `preparedFields()` for its `--set` pairs and
     * then assign `$model->name = $name;` underneath — which is exactly what
     * every create command did until 2026-09-01, and why `theme create --name
     * <existing>` produced a duplicate. So the check is not "does it call the
     * entry point" but "is there any other way in".
     *
     * `tstamp` is the one exception: it is the write timestamp, not caller
     * input, and every command sets it.
     */
    public function testNothingIsAssignedToTheRecordOutsideThePreparedFields(): void
    {
        $leaks = [];

        foreach ($this->createCommandSources() as $name => $source) {
            if (!\in_array($name, $this->inScope([$name => $source]), true)) {
                continue;
            }

            // `\r?$` and not `$`: a working copy with CRLF endings puts a
            // carriage return between the semicolon and the newline, and the
            // pattern silently matched nothing. Caught by mutating a command
            // and watching this test stay green — the same CRLF trap that made
            // an md5 comparison disagree on 2026-08-31.
            preg_match_all('/^[ \t]+\$\w+->(\w+)\s*=\s*(?!\$value\b)[^\r\n]+;\r?$/m', $source, $matches);

            foreach ($matches[1] as $field) {
                if ('tstamp' !== $field) {
                    $leaks[] = $name . '::$record->' . $field;
                }
            }
        }

        $this->assertSame([], $leaks, \sprintf(
            "These values are written onto the record without passing preparedFields(),\n"
            . "so no DCA rule sees them — not rgxp, not unique, not the column check.\n"
            . 'Move them into the array handed to preparedFields() instead.',
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
