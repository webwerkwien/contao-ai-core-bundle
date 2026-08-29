<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\ModelWriter;
use Webwerkwien\ContaoAiCoreBundle\Service\Writer\RecordWriterInterface;

/**
 * The writer is a required collaborator, and a missing one has to say so.
 *
 * When the write path moved behind RecordWriterInterface, no existing test broke:
 * every command test exercises an error path — "not found", "no fields given" —
 * that returns before anything is written. That is convenient and also a trap,
 * because the first test to assert a *successful* write would meet a null.
 *
 * These two tests pin the contract so that failure arrives as a sentence.
 */
class WriterInjectionTest extends TestCase
{
    private function command(): PageUpdateCommand
    {
        $cmd = new PageUpdateCommand($this->createMock(ContaoFramework::class));
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    public function testAMissingWriterExplainsItselfInsteadOfFailingOnNull(): void
    {
        $writer = (new \ReflectionMethod(PageUpdateCommand::class, 'writer'))
            ->getClosure($this->command());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/setRecordWriter/');

        $writer();
    }

    public function testAnInjectedWriterIsHandedBack(): void
    {
        $cmd    = $this->command();
        $writer = $this->createMock(RecordWriterInterface::class);
        $cmd->setRecordWriter($writer);

        $accessor = (new \ReflectionMethod(PageUpdateCommand::class, 'writer'))->getClosure($cmd);

        $this->assertSame($writer, $accessor());
    }

    /**
     * The implementation the container wires in. If this ever stops holding, the
     * commands are writing through something other than the model layer and the
     * behaviour notes in RecordWriterInterface no longer describe reality.
     */
    public function testTheShippedImplementationSatisfiesTheInterface(): void
    {
        $this->assertContains(
            RecordWriterInterface::class,
            class_implements(ModelWriter::class) ?: [],
        );
    }
}
