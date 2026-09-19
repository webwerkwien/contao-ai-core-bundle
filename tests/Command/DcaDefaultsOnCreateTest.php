<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A created record gets the DCA `default` of every column it does not set,
 * as `DC_Table::create()` gives it (v0.28.0).
 *
 * Practical test 2026-09-19: a subscribe module created through the CLI had no
 * `nl_subscribe` mail text — the DCA default the back end fills in — and the
 * first subscription in the front end ended in a TypeError, HTTP 500.
 */
class DcaDefaultsOnCreateTest extends TestCase
{
    private function subject(array $columns): ImageSizeUpdateCommand
    {
        return new class($this->createMock(ContaoFramework::class), $columns) extends ImageSizeUpdateCommand {
            public function __construct(ContaoFramework $framework, private readonly array $columns)
            {
                parent::__construct($framework);
            }

            protected function tableColumns(string $table): array
            {
                return $this->columns;
            }
        };
    }

    private function defaults(array $set, array $columns = ['text', 'modules', 'canonical', 'author', 'stamp', 'nothing', 'plain']): array
    {
        $subject = $this->subject($columns);

        return (new \ReflectionMethod($subject, 'dcaDefaults'))->invoke($subject, 'tl_test', $set);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'text'      => ['default' => 'Bitte bestätigen Sie ##link##'],
            'modules'   => ['default' => [['mod' => 0, 'col' => 'main', 'enable' => 1]]],
            'canonical' => ['default' => true],
            'author'    => ['default' => static fn () => throw new \RuntimeException('no back end user on the console')],
            'stamp'     => ['default' => static fn (): int => 42],
            'nothing'   => ['default' => null],
            'plain'     => ['inputType' => 'text'],
            'virtual'   => ['default' => 'not a column'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    public function testMissingColumnsGetTheirDefault(): void
    {
        $this->assertSame([
            'text'      => 'Bitte bestätigen Sie ##link##',
            'modules'   => serialize([['mod' => 0, 'col' => 'main', 'enable' => 1]]),
            'canonical' => true,
            'stamp'     => 42,
        ], $this->defaults([]));
    }

    public function testWhatTheRecordSetsIsNotOverwritten(): void
    {
        $defaults = $this->defaults(['text' => 'Eigener Text', 'modules' => null]);

        $this->assertArrayNotHasKey('text', $defaults);
        $this->assertArrayNotHasKey('modules', $defaults, 'an explicit null is set, not missing');
    }

    public function testNoColumnListMeansNoGuessing(): void
    {
        $this->assertSame([], $this->defaults([], []));
    }

    public function testOnlyCreatesGetDefaults(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');
        preg_match('/function preparedFields\(.*?\n    }\n/s', $source, $m);

        $this->assertStringContainsString('null === $excludeId ? $fields + $this->dcaDefaults($table, $fields) : $fields', $m[0] ?? '');
    }

    public function testEveryCreateCommandWithDcaDefaultsGoesThroughPreparedFields(): void
    {
        $without = [];

        foreach (glob(__DIR__ . '/../../src/Command/*CreateCommand.php') ?: [] as $file) {
            if (!str_contains((string) file_get_contents($file), 'preparedFields(')) {
                $without[] = basename($file);
            }
        }

        // Folders and versions have no DCA defaults to take; everything else must.
        $this->assertSame(['FolderCreateCommand.php', 'VersionCreateCommand.php'], $without);
    }
}
