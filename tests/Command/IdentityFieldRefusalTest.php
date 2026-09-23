<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * `--set id=…` renumbered the row. On every table, since long before v1.1.0.
 *
 * Contao's `Model::save()` has explicit handling for a changed primary key —
 * *"Track primary key changes"* — and keeps the OLD key for the `WHERE` while
 * writing the new one into the `SET`. `ModelWriter::update()` assigns every
 * given field to the model, so nothing stood in the way. Measured on c5 against
 * the released v1.0.0:
 *
 *     member-group update 12 --set id=9012
 *     -> {"status":"ok","id":12,"updated":["id"]}        … and the row is 9012
 *
 * 🎯 **On `tl_user` that is privilege escalation.** Page ownership is
 * `$cuser === $user->id`, falling back to `Config defaultUser` — user 1 almost
 * everywhere. Move the admin off id 1, move another account onto it, and that
 * account inherits the rights. No password involved, two ordinary updates.
 *
 * The back end cannot do any of this: `id` appears in no palette. The commands
 * that happened to be safe were `member:update` and `user:update`, and only
 * because they carried allow lists of their own — which is the accident this
 * guard replaces with a rule.
 *
 * `tstamp` is refused for the quieter reason: `ModelWriter::update()` overwrites
 * it after the field loop, so `--set tstamp=0` reported success and never wrote.
 */
class IdentityFieldRefusalTest extends TestCase
{
    private function check(array $fields): void
    {
        $subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
        (new \ReflectionMethod($subject, 'refuseIdentityFields'))->invoke($subject, $fields);
    }

    public function testAnOrdinaryFieldPasses(): void
    {
        $this->expectNotToPerformAssertions();
        $this->check(['title' => 'x', 'published' => '1']);
    }

    /**
     * @dataProvider identitySpellings
     */
    public function testTheRowsIdentityIsRefused(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Not settable/');
        $this->check([$field => '9012']);
    }

    /**
     * MySQL column names are not case-sensitive, so `ID` addresses the same
     * column as `id` — only the spelling would have differed. The leading space
     * is what `--set " id=9012"` produces; `parseSetOptions()` does not trim.
     */
    public static function identitySpellings(): array
    {
        return [
            'id'             => ['id'],
            'ID'             => ['ID'],
            'Id'             => ['Id'],
            'id with space'  => [' id'],
            'tstamp'         => ['tstamp'],
            'TSTAMP'         => ['TSTAMP'],
        ];
    }

    /**
     * The offending key is reported as the caller spelled it, not normalised —
     * otherwise the message names a field the caller never typed.
     */
    public function testTheMessageNamesTheCallersSpelling(): void
    {
        $this->expectExceptionMessageMatches('/ID/');
        $this->expectException(\InvalidArgumentException::class);
        $this->check(['ID' => '9012']);
    }

    public function testItSaysNothingWasWritten(): void
    {
        $this->expectExceptionMessageMatches('/Nothing was written/');
        $this->expectException(\InvalidArgumentException::class);
        $this->check(['id' => '1']);
    }

    /**
     * 🎯 **Without this, deleting the call from convertFields() leaves the whole
     * suite green** — every test above invokes the guard directly, so they prove
     * the method works and nothing proves it runs. The second pre-release review
     * of v1.1.0 found exactly that gap; UnknownFieldRefusalTest pins its own
     * call site the same way, for the same reason.
     *
     * A lexical assertion rather than a behavioural one because reaching
     * convertFields() through a command needs a booted framework and a database.
     */
    public function testTheSharedEntryPointRunsIt(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../src/Command/AbstractWriteCommand.php',
        );

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/function convertFields\(.*?\$this->refuseIdentityFields\(/s',
            $source,
            'convertFields() must call refuseIdentityFields() — it is the one entry point '
            . 'every create and every update passes, see Issue #72',
        );
    }

    /**
     * The guard has to sit BEFORE the column check: `id` is a real column, so
     * refuseUnknownFields() would wave it through and the order is what makes
     * the difference.
     */
    public function testItRunsBeforeTheColumnCheck(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../src/Command/AbstractWriteCommand.php',
        );

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/refuseIdentityFields\(\$fields\);\s*\n\s*\$this->refuseUnknownFields\(/',
            $source,
        );
    }
}
