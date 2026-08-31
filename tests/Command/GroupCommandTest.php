<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberGroupModel;
use Contao\UserGroupModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberGroupCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberGroupDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberGroupReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\MemberGroupUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UserGroupCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UserGroupDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UserGroupReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\UserGroupUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * The two permission tables.
 *
 * `tl_user_group` decides what a back end editor can see and change;
 * `tl_member_group` is what protected front end content points at. Both were
 * readable through the generic record group and writable nowhere until
 * 2026-08-31.
 *
 * What is specific to them, and therefore tested here:
 *
 *  - the permission fields are lists, including two — `cud` and `chmod` —
 *    that are lists without saying so in `eval.multiple`
 *  - `jumpTo` is mandatory only once `redirect` is on, because it lives in a
 *    subpalette. The same idea as the module palette rule, one level down.
 *
 * The uniqueness of the group name is deliberately not unit-tested: it goes
 * through `Model::countBy()`, a static on Contao's model layer, and a test for
 * it would assert against a stand-in rather than the rule. It is verified live
 * instead.
 */
class GroupCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function userGroupCreator(): UserGroupCreateCommand
    {
        $cmd = new UserGroupCreateCommand($this->fw());
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    private function memberGroupCreator(): MemberGroupCreateCommand
    {
        $cmd = new MemberGroupCreateCommand($this->fw());
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommand(object $command, array $input): array
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded, 'The command must answer with JSON.');

        return $decoded;
    }

    // --- the name is the one thing both insist on ---

    public function testUserGroupCreateRefusesAMissingName(): void
    {
        $out = $this->runCommand($this->userGroupCreator(), []);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--name', $out['message']);
    }

    public function testMemberGroupCreateRefusesAMissingName(): void
    {
        $out = $this->runCommand($this->memberGroupCreator(), []);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--name', $out['message']);
    }

    // --- the subpalette rule ---

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    private function missing(array $fields): array
    {
        return $this->memberGroupCreator()->missingSubpaletteFields('tl_test_group', $fields);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test_group'] = [
            'subpalettes' => ['redirect' => 'jumpTo'],
            'fields'      => [
                'name'     => ['eval' => ['mandatory' => true]],
                'redirect' => ['inputType' => 'checkbox'],
                'jumpTo'   => ['eval' => ['mandatory' => true]],
            ],
        ];

        // Seeded so the command reads the rule from here instead of loading the
        // real DCA — same shape as Contao's own tl_member_group.
        $GLOBALS['TL_DCA']['tl_member_group'] = $GLOBALS['TL_DCA']['tl_test_group'];

        $GLOBALS['TL_DCA']['tl_perm'] = [
            'fields' => [
                'modules' => ['inputType' => 'checkbox', 'eval' => ['multiple' => true]],
                'cud'        => ['inputType' => 'cud'],
                'pagemounts' => ['inputType' => 'pageTree', 'eval' => ['multiple' => true]],
                'picked'     => ['inputType' => 'picker', 'eval' => ['multiple' => true]],
                'chmod'   => ['inputType' => 'chmod'],
                'name'    => ['inputType' => 'text'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['TL_DCA']['tl_test_group'],
            $GLOBALS['TL_DCA']['tl_member_group'],
            $GLOBALS['TL_DCA']['tl_perm'],
        );
        parent::tearDown();
    }

    public function testASubpaletteFieldIsNotRequiredWhileItsSelectorIsOff(): void
    {
        $this->assertSame([], $this->missing([]));
    }

    public function testASubpaletteFieldIsNotRequiredWhenTheSelectorIsExplicitlyZero(): void
    {
        $this->assertSame([], $this->missing(['redirect' => '0']));
    }

    public function testTurningTheSelectorOnMakesItRequired(): void
    {
        $this->assertSame(['jumpTo'], $this->missing(['redirect' => '1']));
    }

    public function testSupplyingItSatisfiesTheRule(): void
    {
        $this->assertSame([], $this->missing(['redirect' => '1', 'jumpTo' => '7']));
    }

    public function testAnEmptyValueDoesNotCountAsSupplied(): void
    {
        $this->assertSame(['jumpTo'], $this->missing(['redirect' => '1', 'jumpTo' => '']));
    }

    public function testMemberGroupCreateRefusesRedirectWithoutATarget(): void
    {
        $out = $this->runCommand($this->memberGroupCreator(), [
            '--name' => 'Test',
            '--set'  => ['redirect=1'],
        ]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('jumpTo', $out['message']);
    }

    // --- the permission widgets are lists without saying so ---

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convert(array $fields): array
    {
        $command = $this->userGroupCreator();
        $method  = new \ReflectionMethod($command, 'convertMultipleFields');

        return $method->invoke($command, 'tl_perm', $fields);
    }

    public function testCudIsStoredAsAListEvenThoughItHasNoMultipleFlag(): void
    {
        $out = $this->convert(['cud' => 'tl_news::create,tl_news::update']);

        $this->assertSame(
            ['tl_news::create', 'tl_news::update'],
            unserialize($out['cud'], ['allowed_classes' => false]),
        );
    }

    public function testChmodIsStoredAsAList(): void
    {
        $out = $this->convert(['chmod' => 'u1,u2,g4']);

        $this->assertSame(['u1', 'u2', 'g4'], unserialize($out['chmod'], ['allowed_classes' => false]));
    }

    public function testAnOrdinaryMultipleFieldStillWorks(): void
    {
        $out = $this->convert(['modules' => 'page,article']);

        $this->assertSame(['page', 'article'], unserialize($out['modules'], ['allowed_classes' => false]));
    }

    /**
     * `PageTree::validator()` runs `array_map('\intval', …)`, so Contao's own
     * back end writes `a:1:{i:0;i:1;}` for a page mount. A record this bundle
     * writes should not be distinguishable from one the back end wrote.
     */
    public function testPageMountsAreStoredAsIntegersTheWayContaoStoresThem(): void
    {
        $out = $this->convert(['pagemounts' => '1,7']);

        $this->assertSame([1, 7], unserialize($out['pagemounts'], ['allowed_classes' => false]));
    }

    /**
     * `Picker::validator()` casts only on its single-value branch — a
     * comma-separated Picker list keeps its strings. Contao's inconsistency,
     * mirrored on purpose rather than tidied up.
     */
    public function testAPickerListKeepsItsStringsBecauseContaoDoes(): void
    {
        $out = $this->convert(['picked' => '4,5']);

        $this->assertSame(['4', '5'], unserialize($out['picked'], ['allowed_classes' => false]));
    }

    public function testAPlainTextFieldIsUntouched(): void
    {
        $out = $this->convert(['name' => 'Editors, senior']);

        $this->assertSame('Editors, senior', $out['name']);
    }

    // --- each command is bound to the right model ---

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function modelBindings(): array
    {
        return [
            'user group read'     => [UserGroupReadCommand::class, UserGroupModel::class],
            'user group update'   => [UserGroupUpdateCommand::class, UserGroupModel::class],
            'user group delete'   => [UserGroupDeleteCommand::class, UserGroupModel::class],
            'member group read'   => [MemberGroupReadCommand::class, MemberGroupModel::class],
            'member group update' => [MemberGroupUpdateCommand::class, MemberGroupModel::class],
            'member group delete' => [MemberGroupDeleteCommand::class, MemberGroupModel::class],
        ];
    }

    /**
     * @dataProvider modelBindings
     */
    public function testTheCommandPointsAtTheRightModel(string $commandClass, string $modelClass): void
    {
        $command = new $commandClass($this->fw());
        $method  = new \ReflectionMethod($command, 'modelClass');

        $this->assertSame($modelClass, $method->invoke($command));
    }
}
