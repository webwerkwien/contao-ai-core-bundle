<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Routing\ScopeMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

        (new SystemLog($logger, new RequestStack(), $this->scopeMatcher(false)))->write('contao:page:update {"id":1}', 'contao:page:update', 'webwerkwien');

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

        (new SystemLog($logger, new RequestStack(), $this->scopeMatcher(false)))->write('text', 'contao:news:create', 'webwerkwien', ContaoContext::FILES);

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

        (new SystemLog($logger, new RequestStack(), $this->scopeMatcher(false)))->write('text', 'contao:page:update', 'webwerkwien');

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

        (new SystemLog($logger, new RequestStack(), $this->scopeMatcher(false)))->write('text', '', '');

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

        (new SystemLog($logger, new RequestStack(), $this->scopeMatcher(false)))->write('contao:content:update {"id":5}', 'contao:content:update', 'cli');
    }

    /**
     * Regression (v0.2.12): contao-ai-backend-bundle runs these commands
     * in-process during a back-end request. Stamping those CLI attributed an
     * editor's change to the console.
     */
    public function testHandsTheColumnBackToContaoForABackEndRequest(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$captured): void {
                $captured = $context['contao'];
            }
        );

        $stack = new RequestStack();
        $stack->push(Request::create('/contao'));

        (new SystemLog($logger, $stack, $this->scopeMatcher(true)))
            ->write('text', 'contao:page:update', 'webwerkwien');

        // null lets ContaoTableProcessor read the request and write BE.
        $this->assertNull($captured->getSource());
    }

    /**
     * Regression (v0.2.13): a request alone is not the test. The macro bridge
     * posts to /_ai_cli/macro, deliberately outside /contao/*, so it carries no
     * backend scope - the processor would have called the CLI "FE".
     */
    public function testStillClaimsCliForANonBackendRequest(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$captured): void {
                $captured = $context['contao'];
            }
        );

        $stack = new RequestStack();
        $stack->push(Request::create('/_ai_cli/macro', 'POST'));

        (new SystemLog($logger, $stack, $this->scopeMatcher(false)))
            ->write('text', 'contao:record:clone', 'cli');

        $this->assertSame('CLI', $captured->getSource());
    }

    private function scopeMatcher(bool $isBackend): ScopeMatcher
    {
        $matcher = $this->createMock(ScopeMatcher::class);
        $matcher->method('isBackendRequest')->willReturn($isBackend);

        return $matcher;
    }
}
