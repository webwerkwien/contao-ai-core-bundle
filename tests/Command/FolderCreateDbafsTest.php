<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\FolderCreateCommand;

/**
 * A folder created here gets a UUID and its place in the DBAFS tree — and one that
 * lacks them is repaired, not left as it is.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.12.0)**, read with
 * SQL because the CLI could not show it (see the binary(16) tests in
 * FileTreeReadConversionTest):
 *
 * | id | path | pid | uuid |
 * |---|---|---|---|
 * | 1311 | `files/conpai` (folder, via `file folder-create`) | 16 zero bytes | **empty** |
 * | 1312 | `files/conpai/wortmarke-hell.svg` (via `file write`) | **empty** | set |
 *
 * `FolderCreateCommand` built `new FilesModel()` by hand and never set a UUID; for a
 * top-level folder it wrote `''` as pid, which a binary(16) column stores as zeros.
 * `file write` then called `Dbafs::addResource()` correctly, found a parent without a
 * UUID and linked the file to nothing. `contao:filesync` answered "No changes".
 *
 * ## The two paths
 *
 * - **A new folder** goes through `Dbafs::addResource()`, exactly as `file write`
 *   does and as the back end does: UUID, parent entries, contents.
 * - **An existing record without a UUID** is repaired in place: UUID assigned, pid
 *   corrected, direct children without a parent link re-attached. Deleting and
 *   re-adding would not do — `addResource()` would then create a second record for
 *   every child, and a child's UUID may already be referenced elsewhere.
 *
 * The repair itself needs the database and is verified live; what is pinned here is
 * the decision it rests on and that the hand-built model is gone.
 */
class FolderCreateDbafsTest extends TestCase
{
    /**
     * @dataProvider missing
     */
    public function testAMissingUuidIsRecognised(mixed $value): void
    {
        $this->assertTrue($this->isMissingUuid($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function missing(): array
    {
        return [
            'null'           => [null],
            'empty string'   => [''],
            'sixteen zeros'  => [str_repeat("\0", 16)],
            'wrong length'   => ['abc'],
        ];
    }

    public function testAPresentUuidIsNotMissing(): void
    {
        $this->assertFalse($this->isMissingUuid(hex2bin('b10615e1b1c011f1b9e4525400338fcc')));
    }

    public function testNewFoldersGoThroughTheDbafs(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('Dbafs::addResource(', $source);
        $this->assertStringNotContainsString(
            'new FilesModel()',
            $source,
            'a hand-built FilesModel is what left folders without a UUID',
        );
    }

    public function testExistingRecordsAreRepairedNotReturnedAsTheyAre(): void
    {
        $this->assertStringContainsString('repairFolder(', $this->source());
    }

    private function isMissingUuid(mixed $value): bool
    {
        return (new \ReflectionMethod(FolderCreateCommand::class, 'isMissingUuid'))->invoke(null, $value);
    }

    private function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/Command/FolderCreateCommand.php');
    }
}
