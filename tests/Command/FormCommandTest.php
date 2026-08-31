<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FormFieldModel;
use Contao\FormModel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FormCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormFieldCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormFieldDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormFieldReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormFieldUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\FormUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * The form generator — the last content module that could be read and not written.
 *
 * `form list` and `form fields` have existed for a while; neither half could be
 * created. And `tl_form_field` is `tl_module` in miniature: twenty types with a
 * palette each, and mandatory fields that apply to some of them only. A
 * `submit` needs `slabel`, a `select` needs `name` and `options`, an
 * `explanation` needs neither.
 *
 * Three things are specific enough to test here:
 *
 *  - the per-type requirement rule, which is the same trap the module command
 *    walked into first
 *  - the `options` short form, without which three of the twenty types cannot
 *    be created at all
 *  - the alias guards on `tl_form`, because a duplicate alias does not fail —
 *    it routes to whichever record comes back first
 */
class FormCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function wire(object $command): object
    {
        $command->setLogger($this->createMock(LoggerInterface::class));
        $command->setVersionManager($this->createMock(VersionManager::class));

        return $command;
    }

    private function formCreator(int $aliasCount = 0): FormCreateCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($aliasCount);

        return $this->wire(new FormCreateCommand($this->fw(), $connection));
    }

    private function fieldCreator(): FormFieldCreateCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        return $this->wire(new FormFieldCreateCommand($this->fw(), $connection));
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

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_form'] = [
            'palettes'    => ['default' => '{title_legend},title,alias,jumpTo;{email_legend},sendViaEmail;{store_legend:hide},storeValues'],
            'subpalettes' => [
                'sendViaEmail' => 'mailerTransport,recipient,subject,format,skipEmpty',
                'storeValues'  => 'targetTable',
            ],
            'fields' => [
                'title'        => ['eval' => ['mandatory' => true]],
                'alias'        => ['inputType' => 'text'],
                'sendViaEmail' => ['inputType' => 'checkbox'],
                'recipient'    => ['eval' => ['mandatory' => true]],
                'subject'      => ['eval' => ['mandatory' => true]],
                'storeValues'  => ['inputType' => 'checkbox'],
                'targetTable'  => ['inputType' => 'select'],
            ],
        ];

        $GLOBALS['TL_DCA']['tl_form_field'] = [
            'palettes' => [
                'text'        => '{type_legend},type,name,label;{fconfig_legend},mandatory',
                'select'      => '{type_legend},type,name,label;{options_legend},options',
                'submit'      => '{type_legend},type,slabel',
                'explanation' => '{type_legend},type;{text_legend},text',
            ],
            'fields' => [
                'type'    => ['inputType' => 'select'],
                'name'    => ['eval' => ['mandatory' => true]],
                'label'   => ['inputType' => 'text'],
                'options' => ['inputType' => 'optionWizard', 'eval' => ['mandatory' => true]],
                'slabel'  => ['eval' => ['mandatory' => true]],
                'text'    => ['inputType' => 'textarea'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_form'], $GLOBALS['TL_DCA']['tl_form_field']);
        parent::tearDown();
    }

    // --- tl_form: conditional requirements ---

    public function testFormCreateRefusesAMissingTitle(): void
    {
        $out = $this->runCommand($this->formCreator(), []);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--title', $out['message']);
    }

    /**
     * A form that only stores its values needs neither recipient nor subject.
     * Reading the mandatory flags alone would refuse it.
     */
    public function testAFormWithoutEmailNeedsNoRecipient(): void
    {
        $missing = $this->formCreator()->missingMandatoryFields(
            'tl_form',
            'default',
            [],
            ['title', 'alias'],
        );

        $this->assertSame([], $missing);
    }

    public function testTurningOnEmailRequiresRecipientAndSubject(): void
    {
        $missing = $this->formCreator()->missingMandatoryFields(
            'tl_form',
            'default',
            ['sendViaEmail' => '1'],
            ['title', 'alias'],
        );

        $this->assertSame(['recipient', 'subject'], $missing);
    }

    // --- tl_form: the alias guards ---

    /**
     * `tl_form::generateAlias` throws on this, because Contao cannot tell a
     * numeric alias apart from a record ID.
     */
    public function testAPurelyNumericAliasIsRefused(): void
    {
        $out = $this->runCommand($this->formCreator(), ['--title' => 'Contact', '--alias' => '42']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('numeric', $out['message']);
    }

    /**
     * A duplicate alias does not fail at request time — it routes to whichever
     * record the query returns first, which is the worst kind of wrong.
     */
    public function testATakenAliasIsRefused(): void
    {
        $out = $this->runCommand(
            $this->formCreator(aliasCount: 1),
            ['--title' => 'Contact', '--alias' => 'contact'],
        );

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('already in use', $out['message']);
    }

    // --- tl_form_field: the per-type rule ---

    public function testFieldCreateRefusesAMissingPidOrType(): void
    {
        $out = $this->runCommand($this->fieldCreator(), ['--type' => 'text']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--pid', $out['message']);
    }

    public function testAnUnknownTypeIsNamedAlongWithTheKnownOnes(): void
    {
        $out = $this->runCommand($this->fieldCreator(), ['--pid' => '1', '--type' => 'nonsense']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('nonsense', $out['message']);
        $this->assertStringContainsString('select', $out['message']);
    }

    /**
     * `slabel` is mandatory and belongs to `submit` alone. A `text` field must
     * not be asked for it, and a `submit` must not be asked for `name`.
     */
    public function testEachTypeIsAskedOnlyForItsOwnMandatoryFields(): void
    {
        $command = $this->fieldCreator();

        $this->assertSame(
            ['name'],
            $command->missingMandatoryFields('tl_form_field', 'text', [], ['type']),
        );
        $this->assertSame(
            ['slabel'],
            $command->missingMandatoryFields('tl_form_field', 'submit', [], ['type']),
        );
        $this->assertSame(
            ['name', 'options'],
            $command->missingMandatoryFields('tl_form_field', 'select', [], ['type']),
        );
        $this->assertSame(
            [],
            $command->missingMandatoryFields('tl_form_field', 'explanation', [], ['type']),
            'An explanation needs nothing but its type.',
        );
    }

    // --- the options short form ---

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convertOptions(array $fields): array
    {
        $command = $this->fieldCreator();
        $method  = new \ReflectionMethod($command, 'convertOptionFields');

        return $method->invoke($command, 'tl_form_field', $fields);
    }

    public function testValueAndLabelPairsBecomeContaosStructure(): void
    {
        $out = $this->convertOptions(['options' => 'mrs=Mrs.|mr=Mr.']);

        $this->assertSame(
            [
                ['value' => 'mrs', 'label' => 'Mrs.'],
                ['value' => 'mr', 'label' => 'Mr.'],
            ],
            unserialize($out['options'], ['allowed_classes' => false]),
        );
    }

    public function testAPlainListUsesEachEntryAsBothValueAndLabel(): void
    {
        $out = $this->convertOptions(['options' => 'red|green|blue']);

        $this->assertSame(
            [
                ['value' => 'red', 'label' => 'red'],
                ['value' => 'green', 'label' => 'green'],
                ['value' => 'blue', 'label' => 'blue'],
            ],
            unserialize($out['options'], ['allowed_classes' => false]),
        );
    }

    public function testSpacesAroundTheSeparatorsAreTolerated(): void
    {
        $out = $this->convertOptions(['options' => ' mrs = Mrs. | mr = Mr. ']);

        $this->assertSame(
            [
                ['value' => 'mrs', 'label' => 'Mrs.'],
                ['value' => 'mr', 'label' => 'Mr.'],
            ],
            unserialize($out['options'], ['allowed_classes' => false]),
        );
    }

    /**
     * A label may legitimately contain "=" once the value is separated off.
     */
    public function testOnlyTheFirstEqualsSignSeparates(): void
    {
        $out = $this->convertOptions(['options' => 'eq=a = b']);

        $this->assertSame(
            [['value' => 'eq', 'label' => 'a = b']],
            unserialize($out['options'], ['allowed_classes' => false]),
        );
    }

    public function testAValueAlreadyInContaosFormatIsLeftAlone(): void
    {
        $stored = serialize([['value' => 'a', 'label' => 'A']]);

        $out = $this->convertOptions(['options' => $stored]);

        $this->assertSame($stored, $out['options'], 'Re-running has to be a no-op.');
    }

    public function testAFieldThatIsNotAnOptionWizardIsUntouched(): void
    {
        $out = $this->convertOptions(['label' => 'a|b']);

        $this->assertSame('a|b', $out['label']);
    }

    // --- model bindings ---

    /**
     * @dataProvider modelBindings
     */
    public function testTheCommandPointsAtTheRightModel(string $commandClass, string $modelClass): void
    {
        $command = new $commandClass($this->fw());
        $method  = new \ReflectionMethod($command, 'modelClass');

        $this->assertSame($modelClass, $method->invoke($command));
    }

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function modelBindings(): array
    {
        return [
            'form read'    => [FormReadCommand::class, FormModel::class],
            'form update'  => [FormUpdateCommand::class, FormModel::class],
            'form delete'  => [FormDeleteCommand::class, FormModel::class],
            'field read'   => [FormFieldReadCommand::class, FormFieldModel::class],
            'field update' => [FormFieldUpdateCommand::class, FormFieldModel::class],
            'field delete' => [FormFieldDeleteCommand::class, FormFieldModel::class],
        ];
    }
}
