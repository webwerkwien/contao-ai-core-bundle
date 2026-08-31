<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\SettingsUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * The Settings module — the one back end entry with no table behind it.
 *
 * `tl_settings` is a `DC_File`: the values live in
 * `system/config/localconfig.php` as `$GLOBALS['TL_CONFIG'][…]`. That is why
 * `record:list tl_settings` answers "No readable columns" rather than
 * returning rows, and why this needed a command of its own.
 *
 * The refusals are the interesting part, because writing this file has no
 * safety net at all:
 *
 *  - **An unknown key is refused.** `Config::persist()` writes any key given to
 *    it and nothing ever reads it back or complains. A typo would put a dead
 *    variable into `localconfig.php` permanently, in a file nobody opens by
 *    hand. Contao is protected by the form only offering real fields; here it
 *    has to be checked.
 *  - **A mandatory setting cannot be emptied.** An empty `dateFormat` breaks
 *    every date on the site, and the back end refuses it too.
 *  - **An unchanged value does not rewrite the file**, compared loosely because
 *    `Config::get()` answers `30` where `--set` always arrives as `"30"`.
 *
 * The successful write is verified live rather than here: it ends in a file,
 * and a test for it would assert against a stand-in for the filesystem.
 */
class SettingsCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function updater(): SettingsUpdateCommand
    {
        $cmd = new SettingsUpdateCommand($this->createMock(ContaoFramework::class));
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommand(array $input): array
    {
        $tester = new CommandTester($this->updater());
        $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded, 'The command must answer with JSON.');

        return $decoded;
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_settings']['fields'] = [
            'adminEmail'     => ['inputType' => 'text', 'eval' => ['mandatory' => true]],
            'dateFormat'     => ['inputType' => 'text', 'eval' => ['mandatory' => true]],
            'resultsPerPage' => ['inputType' => 'text', 'eval' => ['mandatory' => true]],
            'allowedTags'    => ['inputType' => 'text'],
            'defaultChmod'   => ['inputType' => 'chmod'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_settings']);
        parent::tearDown();
    }

    public function testNothingToChangeIsAnErrorRatherThanASilentSuccess(): void
    {
        $out = $this->runCommand([]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--set', $out['message']);
    }

    /**
     * The one that matters most: `Config::persist()` would write this key and
     * nothing would ever read it back.
     */
    public function testAnUnknownKeyIsRefusedAndNothingIsWritten(): void
    {
        $out = $this->runCommand(['--set' => ['adminEmial=a@b.c']]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('adminEmial', $out['message']);
        $this->assertStringContainsString('Nothing was written', $out['message']);
    }

    public function testOneUnknownKeyRejectsTheWholeCall(): void
    {
        $out = $this->runCommand(['--set' => ['allowedTags=<p>', 'nonsense=1']]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('nonsense', $out['message']);
        $this->assertStringNotContainsString('allowedTags', $out['message']);
    }

    public function testAMandatorySettingCannotBeEmptied(): void
    {
        $out = $this->runCommand(['--set' => ['dateFormat=']]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('dateFormat', $out['message']);
    }

    /**
     * The mandatory check must not catch fields that are not mandatory.
     *
     * ⚠️ This asserts the refusal does *not* happen, not that a write happened:
     * `allowedTags` is already empty in this harness, so the command takes the
     * "every value already matched" branch and never reaches the file. That is
     * the honest scope of a unit test here — the write ends in
     * `localconfig.php`, and it is verified live instead.
     */
    public function testClearingAnOptionalSettingIsNotRefused(): void
    {
        $out = $this->runCommand(['--set' => ['allowedTags=']]);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(['allowedTags'], $out['unchanged']);
    }

    /**
     * `Config::get()` answers with the typed value while `--set` always arrives
     * as a string. A strict comparison would report every unchanged numeric
     * setting as changed and rewrite the file for nothing.
     *
     * @dataProvider looseComparisons
     */
    public function testValuesAreComparedLoosely(mixed $stored, string $incoming, bool $same): void
    {
        $method = new \ReflectionMethod($this->updater(), 'sameValue');

        $this->assertSame($same, $method->invoke($this->updater(), $stored, $incoming));
    }

    /**
     * @return array<string, array{mixed, string, bool}>
     */
    public static function looseComparisons(): array
    {
        return [
            'int against its string'   => [30, '30', true],
            'int against another'      => [30, '50', false],
            'true against "1"'         => [true, '1', true],
            'true against "0"'         => [true, '0', false],
            'false against "0"'        => [false, '0', true],
            'string against itself'    => ['d.m.Y', 'd.m.Y', true],
            'string against another'   => ['d.m.Y', 'Y-m-d', false],
            'array against its form'   => [['u1', 'u2'], 'a:2:{i:0;s:2:"u1";i:1;s:2:"u2";}', true],
            'array against a shorter'  => [['u1', 'u2'], 'a:1:{i:0;s:2:"u1";}', false],
        ];
    }
}
