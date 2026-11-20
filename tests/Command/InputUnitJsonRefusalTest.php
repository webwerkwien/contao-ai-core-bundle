<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * An `inputUnit` value that starts like a JSON object but is not the
 * {"value", "unit"} form is refused (v1.18.2).
 *
 * 🔴 Measured with Windows PowerShell 5.1: the shell drops the inner double
 * quotes of `--set 'headline={"value":"Titel","unit":"h1"}'` when it calls a
 * native program, so `headline={value:Titel,unit:h1}` arrives. Until v1.18.1 the
 * conversion stored that whole string as the headline text with the stored or
 * default unit, and the command answered ok. Structured array fields have refused
 * such values since v0.26.0; `inputUnit` has its own conversion and was left out.
 */
class InputUnitJsonRefusalTest extends TestCase
{
    private ImageSizeUpdateCommand $subject;

    protected function setUp(): void
    {
        $this->subject = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));

        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'headline' => [
                'inputType' => 'inputUnit',
                'options'   => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                'sql'       => "text NULL default 'a:2:{s:5:\"value\";s:0:\"\";s:4:\"unit\";s:2:\"h2\";}'",
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convert(array $fields): array
    {
        return (new \ReflectionMethod($this->subject, 'convertInputUnitFields'))
            ->invoke($this->subject, 'tl_test', $fields);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedValues(): iterable
    {
        yield 'quotes dropped by PowerShell 5.1' => ['{value:Allgemeine Bedingungen,unit:h1}'];
        yield 'leading whitespace' => ['  {value:Titel,unit:h1}'];
        yield 'cut off' => ['{"value":"Titel","unit":"h1"'];
        yield 'valid JSON without value' => ['{"unit":"h1"}'];
    }

    #[DataProvider('refusedValues')]
    public function testAValueThatStartsLikeJsonButIsNotThePairIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not valid JSON for tl_test: headline=');
        $this->expectExceptionMessage('Nothing was written');

        $this->convert(['headline' => $value]);
    }

    public function testTheJsonPairIsStillConverted(): void
    {
        $fields = $this->convert(['headline' => '{"value":"Allgemeine Bedingungen","unit":"h1"}']);

        $this->assertSame(serialize(['value' => 'Allgemeine Bedingungen', 'unit' => 'h1']), $fields['headline']);
    }

    /**
     * On update the mangled value kept the record's unit; it is refused there too.
     */
    public function testTheUpdatePathRefusesAsWell(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not valid JSON for tl_test: headline=');

        $record = (object) ['headline' => serialize(['value' => 'Alt', 'unit' => 'h1'])];

        (new \ReflectionMethod($this->subject, 'convertInputUnitFields'))
            ->invoke($this->subject, 'tl_test', ['headline' => '{value:Neu,unit:h3}'], null, $record);
    }

    /**
     * A unit on its own carries the stored text over as {"value": …} JSON, so a
     * stored text that starts with a brace is not refused.
     */
    public function testAUnitAloneKeepsAStoredTextThatStartsWithABrace(): void
    {
        $record = (object) ['headline' => serialize(['value' => '{Entwurf} Titel', 'unit' => 'h2'])];

        $fields = (new \ReflectionMethod($this->subject, 'convertInputUnitFields'))
            ->invoke($this->subject, 'tl_test', ['headline_unit' => 'h1'], null, $record);

        $this->assertSame(['headline' => serialize(['value' => '{Entwurf} Titel', 'unit' => 'h1'])], $fields);
    }

    public function testATextThatStartsWithABraceGoesInTheJsonForm(): void
    {
        $fields = $this->convert(['headline' => '{"value":"{Entwurf} Titel","unit":"h1"}']);

        $this->assertSame(serialize(['value' => '{Entwurf} Titel', 'unit' => 'h1']), $fields['headline']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainTexts(): iterable
    {
        yield 'insert tag at the start' => ['{{iflng::de}}Titel{{iflng}}'];
        yield 'brace inside the text' => ['Titel {Entwurf}'];
        yield 'bracket at the start' => ['[Entwurf] Titel'];
    }

    #[DataProvider('plainTexts')]
    public function testPlainTextIsStoredAsText(string $value): void
    {
        $fields = $this->convert(['headline' => $value]);

        $this->assertSame(serialize(['value' => $value, 'unit' => 'h2']), $fields['headline']);
    }
}
