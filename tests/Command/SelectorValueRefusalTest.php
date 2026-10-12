<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Webwerkwien\ContaoAiCoreBundle\Command\AbstractWriteCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;

/**
 * A palette selector only takes a value Contao offers (v1.6.1).
 *
 * 🔴 **Measured on 2026-10-12:** `content create --type texxt` stored a visible element
 * of a type that does not exist (c5, 5.7, removed again), `page update --set type=foo`
 * a page type nobody registered (c5, 6.0). The selectors of both take their options
 * from a callback, which refuseInvalidOptions() does not ask. Through the real method;
 * the options resolver and the stored row are stand-ins.
 */
class SelectorValueRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_probe_sel'] = [
            'palettes' => ['__selector__' => ['type', 'addImage', 'size']],
            'fields'   => [
                'type'     => ['inputType' => 'select', 'options_callback' => ['x', 'y']],
                'addImage' => ['inputType' => 'checkbox'],
                'size'     => ['inputType' => 'select', 'options' => ['s', 'm']],
                'title'    => ['inputType' => 'text'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_probe_sel']);
    }

    /**
     * @param list<string>|null    $values
     * @param array<string, mixed> $fields
     */
    private function refuse(?array $values, array $fields, ?int $id = null): void
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->method('values')->willReturn($values);

        $command = new SelectorProbeCommand();
        $command->setOptionsResolver($resolver);

        (new \ReflectionMethod($command, 'refuseUnknownSelectorValues'))->invoke($command, 'tl_probe_sel', $fields, $id);
    }

    public function testAnInventedTypeIsRefusedAndTheOffersAndLookupNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tl_probe_sel\.type: texxt\. Nothing was written\. Available: text, headline\..*contao:dca:options tl_probe_sel type/s');

        $this->refuse(['text', 'headline'], ['type' => 'texxt', 'title' => 'x']);
    }

    public function testAnOfferedTypePasses(): void
    {
        $this->refuse(['text', 'headline'], ['type' => 'headline']);
        $this->addToAssertionCount(1);
    }

    public function testAnUnresolvableListRefusesNothing(): void
    {
        $this->refuse(null, ['type' => 'whatever']);
        $this->refuse([], ['type' => 'whatever']);
        $this->addToAssertionCount(1);
    }

    public function testOnlySelectorsWithCallbackOptionsAreAsked(): void
    {
        // A checkbox selector is a flag, a declared list is refuseInvalidOptions()'s,
        // a field that selects nothing is not this check's.
        $this->refuse(['nothing'], ['addImage' => '1', 'size' => 'xl', 'title' => 'free']);
        $this->addToAssertionCount(1);
    }

    public function testAnUpdateAsksWithTheStoredRecordAndItsId(): void
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->expects($this->once())->method('values')
            ->with('tl_probe_sel', 'type', ['id' => 5, 'type' => 'headline', 'ptable' => 'tl_news'], 5)
            ->willReturn(['text', 'headline']);

        $command = new SelectorProbeCommand();
        $command->setOptionsResolver($resolver);

        (new \ReflectionMethod($command, 'refuseUnknownSelectorValues'))->invoke($command, 'tl_probe_sel', ['type' => 'headline'], 5);
    }

    /** Fable review: the value a record already has is kept, as the back end keeps it. */
    public function testTheValueTheRecordAlreadyHasIsNotAsked(): void
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->expects($this->never())->method('values');

        $command = new SelectorProbeCommand();
        $command->setOptionsResolver($resolver);

        // The probe's stored row has type=text.
        (new \ReflectionMethod($command, 'refuseUnknownSelectorValues'))->invoke($command, 'tl_probe_sel', ['type' => 'text'], 5);
    }

    public function testABlankIsClearedWhereTheFieldOffersOneAndAListIsNoChoice(): void
    {
        $GLOBALS['TL_DCA']['tl_probe_sel']['fields']['type']['eval'] = ['includeBlankOption' => true];
        $this->refuse(['text'], ['type' => '']);

        $GLOBALS['TL_DCA']['tl_probe_sel']['fields']['type']['eval'] = ['multiple' => true];
        $this->refuse(['text'], ['type' => 'a,b']);

        $this->addToAssertionCount(1);
    }

    public function testABlankWithoutABlankOptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->refuse(['text'], ['type' => '']);
    }

    /**
     * The call itself, inside convertFields(): the method body up to the next method.
     * (A first version matched the method's own declaration and could not fail.)
     */
    public function testConvertFieldsRunsTheCheck(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php');

        $this->assertMatchesRegularExpression('/function convertFields\(.*?\n    }\n/s', $source);
        preg_match('/function convertFields\(.*?\n    }\n/s', $source, $body);
        $this->assertStringContainsString('$this->refuseUnknownSelectorValues($table, $fields, $excludeId);', $body[0]);
    }
}

final class SelectorProbeCommand extends AbstractWriteCommand
{
    protected function configure(): void
    {
        $this->setName('test:probe:selector');
        parent::configure();
    }

    protected function doExecute(array $fields): int
    {
        return Command::SUCCESS;
    }

    protected function storedRow(string $table, ?int $id): array
    {
        return null === $id ? [] : ['id' => $id, 'type' => 'text', 'ptable' => 'tl_news'];
    }
}
