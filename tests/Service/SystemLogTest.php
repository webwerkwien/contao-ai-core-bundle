<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\SystemLog;

class SystemLogTest extends TestCase
{
    /**
     * Regression: without a ContaoContext in the log context,
     * ContaoTableHandler::handle() returns early and the entry never reaches
     * tl_log. That is why every CLI write was invisible in the back end.
     */
    public function testPassesAContaoContextSoTheEntryReachesTlLog(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context) use (&$captured): void {
                $captured = $context;
            })
        ;

        (new SystemLog($logger))->write('contao:page:update {"id":1}', 'contao:page:update', 'webwerkwien');

        $this->assertInstanceOf(ContaoContext::class, $captured['contao'] ?? null);
    }

    /**
     * On the console there is no request and no security token, so
     * ContaoTableProcessor would fill in username=N/A and source=FE. Both have
     * to be set here or the entry lands unattributable.
     */
    public function testFillsTheColumnsTheConsoleHasNoRequestToFill(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$captured): void {
                $captured = $context['contao'];
            }
        );

        (new SystemLog($logger))->write('text', 'contao:news:create', 'webwerkwien', ContaoContext::FILES);

        $this->assertSame('CLI', $captured->getSource());
        $this->assertSame('webwerkwien', $captured->getUsername());
        $this->assertSame('contao:news:create', $captured->getFunc());
        $this->assertSame(ContaoContext::FILES, $captured->getAction());
    }

    public function testDefaultsToTheGeneralAction(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$captured): void {
                $captured = $context['contao'];
            }
        );

        (new SystemLog($logger))->write('text', 'contao:page:update', 'webwerkwien');

        $this->assertSame(ContaoContext::GENERAL, $captured->getAction());
    }

    /**
     * ContaoContext throws on an empty func, and an empty username would make
     * the entry anonymous. Neither should be able to turn a missing operator
     * into a fatal or a blank row.
     */
    public function testNeverWritesAnEmptyFuncOrUsername(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$captured): void {
                $captured = $context['contao'];
            }
        );

        (new SystemLog($logger))->write('text', '', '');

        $this->assertNotSame('', $captured->getFunc());
        $this->assertSame('cli-agent', $captured->getUsername());
    }

    public function testLogsTheTextVerbatim(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('info')
            ->with('contao:content:update {"id":5}', $this->anything())
        ;

        (new SystemLog($logger))->write('contao:content:update {"id":5}', 'contao:content:update', 'cli');
    }
}
