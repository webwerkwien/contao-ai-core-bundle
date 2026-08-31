<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A multi-value field is stored as a serialized array, not as a bare value.
 *
 * `tl_module.news_archives` holds `a:1:{i:0;s:1:"1";}`. Until 2026-08-31 a
 * `--set news_archives=1` wrote the string `1`, and nothing complained:
 * `StringUtil::deserialize()` hands a non-array straight back, so Contao
 * iterated a string and found no archives. The module was configured and did
 * nothing — the quietest kind of wrong.
 *
 * The same shape applies well beyond modules: `tl_page.groups`,
 * `tl_module.pages`, `cal_calendar`, `faq_categories`, `nl_channels`. The
 * conversion is DCA-driven for that reason and now runs on every update.
 *
 * Reached through ImageSizeUpdateCommand because the method under test lives on
 * AbstractWriteCommand and any concrete command inherits it unchanged.
 */
class MultipleFieldConversionTest extends TestCase
{
    private function subject(): ImageSizeUpdateCommand
    {
        return new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function convert(array $fields): array
    {
        $method = new \ReflectionMethod($this->subject(), 'convertMultipleFields');

        return $method->invoke($this->subject(), 'tl_test', $fields);
    }

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'archives' => ['inputType' => 'checkbox', 'eval' => ['multiple' => true]],
            'title'    => ['inputType' => 'text'],
            'images'   => ['inputType' => 'fileTree', 'eval' => ['multiple' => true]],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
        parent::tearDown();
    }

    public function testASingleValueBecomesAOneElementList(): void
    {
        $out = $this->convert(['archives' => '1']);

        $this->assertSame(['1'], unserialize($out['archives'], ['allowed_classes' => false]));
    }

    public function testACommaSeparatedValueBecomesAList(): void
    {
        $out = $this->convert(['archives' => '1,3,7']);

        $this->assertSame(['1', '3', '7'], unserialize($out['archives'], ['allowed_classes' => false]));
    }

    public function testSpacesAroundTheCommasAreTolerated(): void
    {
        $out = $this->convert(['archives' => '1, 3 , 7']);

        $this->assertSame(['1', '3', '7'], unserialize($out['archives'], ['allowed_classes' => false]));
    }

    public function testASingleValueFieldIsUntouched(): void
    {
        $out = $this->convert(['title' => 'News, latest']);

        $this->assertSame('News, latest', $out['title'], 'A comma in prose is not a list separator.');
    }

    public function testAValueAlreadyInContaosFormatIsLeftAlone(): void
    {
        $stored = serialize(['1', '3']);

        $out = $this->convert(['archives' => $stored]);

        $this->assertSame($stored, $out['archives'], 'Re-running has to be a no-op.');
    }

    /**
     * An unset multiple is `''` in the database, not an empty array. Inventing
     * one would be a change nobody asked for.
     */
    public function testAnEmptyValueStaysEmpty(): void
    {
        $out = $this->convert(['archives' => '']);

        $this->assertSame('', $out['archives']);
    }

    /**
     * fileTree fields are serialized by convertFileTreeFields(), which also has
     * to convert the UUIDs; doing it twice would corrupt them.
     */
    public function testFileTreeFieldsAreLeftToTheirOwnConversion(): void
    {
        $out = $this->convert(['images' => 'a-uuid-string']);

        $this->assertSame('a-uuid-string', $out['images']);
    }

    public function testAFieldTheDcaDoesNotKnowIsUntouched(): void
    {
        $out = $this->convert(['unknown' => '1,2']);

        $this->assertSame('1,2', $out['unknown']);
    }
}
