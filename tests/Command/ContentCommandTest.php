<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

class ContentCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function vm(): VersionManager
    {
        return $this->createMock(VersionManager::class);
    }

    // --- ContentCreateCommand ---

    public function testCreateRequiresType(): void
    {
        $cmd = new ContentCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--pid' => '1']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsStringIgnoringCase('type', $out['message']);
    }

    public function testCreateRequiresPid(): void
    {
        $cmd = new ContentCreateCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['--type' => 'text']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- ContentReadCommand ---

    public function testReadReturnsErrorForMissingRecord(): void
    {
        $this->skipWithoutContaoContainer();
        $cmd = new ContentReadCommand($this->fw());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- ContentDeleteCommand ---

    public function testDeleteReturnsErrorForMissingRecord(): void
    {
        $this->skipWithoutContaoContainer();
        $cmd = new ContentDeleteCommand($this->fw());
        $cmd->setLogger($this->logger());
        $cmd->setVersionManager($this->vm());
        $tester = new CommandTester($cmd);
        $tester->execute(['id' => '0']);
        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
    }

    // --- ContentUpdateCommand ---

    public function testUpdateCommandName(): void
    {
        $cmd = new ContentUpdateCommand($this->fw());
        $this->assertSame('contao:content:update', $cmd->getName());
    }

    // --- fileTree UUID conversion (singleSRC) ---

    /**
     * Exposes the protected convertFileTreeFields() helper for direct testing.
     * DCA is seeded in $GLOBALS so no DataContainer / DB access is required.
     */
    private function converter(): object
    {
        return new class($this->fw()) extends ContentCreateCommand {
            public function expose(string $table, array $fields): array
            {
                return $this->convertFileTreeFields($table, $fields);
            }
        };
    }

    public function testSingleSrcStringUuidConvertedToBinary(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields']['singleSRC'] = ['inputType' => 'fileTree'];
        $uuid = '10dad2f0-8cc0-11ec-a8a3-0242ac120002';

        $out = $this->converter()->expose('tl_content', ['singleSRC' => $uuid]);

        $this->assertSame(\Contao\StringUtil::uuidToBin($uuid), $out['singleSRC']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testMultiSrcListConvertedToSerializedBinaryArray(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields']['multiSRC'] = [
            'inputType' => 'fileTree',
            'eval'      => ['multiple' => true],
        ];
        $a = '10dad2f0-8cc0-11ec-a8a3-0242ac120002';
        $b = '20dad2f0-8cc0-11ec-a8a3-0242ac120003';

        $out = $this->converter()->expose('tl_content', ['multiSRC' => "$a, $b"]);

        $this->assertSame(
            serialize([\Contao\StringUtil::uuidToBin($a), \Contao\StringUtil::uuidToBin($b)]),
            $out['multiSRC']
        );
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testNonUuidValueLeftUntouched(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields']['singleSRC'] = ['inputType' => 'fileTree'];

        $out = $this->converter()->expose('tl_content', ['singleSRC' => 'not-a-uuid']);

        $this->assertSame('not-a-uuid', $out['singleSRC']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testNonFileTreeFieldLeftUntouched(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields']['text'] = ['inputType' => 'textarea'];
        $uuid = '10dad2f0-8cc0-11ec-a8a3-0242ac120002';

        $out = $this->converter()->expose('tl_content', ['text' => $uuid]);

        $this->assertSame($uuid, $out['text']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    // --- inputUnit headline conversion (unit + value) ---

    private function unitConv(): object
    {
        return new class($this->fw()) extends ContentCreateCommand {
            public function expose(string $t, array $f, string $d = 'h2', ?object $r = null): array
            {
                return $this->convertInputUnitFields($t, $f, $d, $r);
            }
        };
    }

    private function seedHeadlineDca(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields']['headline'] = [
            'inputType' => 'inputUnit',
            'options'   => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
        ];
    }

    public function testHeadlineDefaultUnitCanonicalOrder(): void
    {
        $this->seedHeadlineDca();
        $out = $this->unitConv()->expose('tl_content', ['headline' => 'Titel']);
        // canonical Contao order: value first, then unit
        $this->assertSame(serialize(['value' => 'Titel', 'unit' => 'h2']), $out['headline']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testHeadlineCompanionUnitConsumed(): void
    {
        $this->seedHeadlineDca();
        $out = $this->unitConv()->expose('tl_content', ['headline' => 'Titel', 'headline_unit' => 'h1']);
        $this->assertSame(serialize(['value' => 'Titel', 'unit' => 'h1']), $out['headline']);
        $this->assertArrayNotHasKey('headline_unit', $out, 'companion key must not reach the model');
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testHeadlineJsonValue(): void
    {
        $this->seedHeadlineDca();
        $out = $this->unitConv()->expose('tl_content', ['headline' => '{"unit":"h3","value":"Titel"}']);
        $this->assertSame(serialize(['value' => 'Titel', 'unit' => 'h3']), $out['headline']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testHeadlineInvalidUnitFallsBackToDefault(): void
    {
        $this->seedHeadlineDca();
        $out = $this->unitConv()->expose('tl_content', ['headline' => 'Titel', 'headline_unit' => 'h9']);
        $this->assertSame(serialize(['value' => 'Titel', 'unit' => 'h2']), $out['headline']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }

    public function testHeadlineUnitPreservedOnUpdate(): void
    {
        $this->seedHeadlineDca();
        $record = (object) ['headline' => serialize(['value' => 'Alt', 'unit' => 'h4'])];
        $out = $this->unitConv()->expose('tl_content', ['headline' => 'Neu'], 'h2', $record);
        $this->assertSame(serialize(['value' => 'Neu', 'unit' => 'h4']), $out['headline']);
        unset($GLOBALS['TL_DCA']['tl_content']);
    }
}
