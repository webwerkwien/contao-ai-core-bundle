<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Contao\BackendUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

/**
 * `contao-ai` is the user of a callback run on the console, and only of that (v1.4.0).
 */
class CliUserTest extends TestCase
{
    private function provider(?BackendUser $user): UserProviderInterface
    {
        $provider = $this->createMock(UserProviderInterface::class);

        if (null === $user) {
            $provider->method('loadUserByIdentifier')->willThrowException(new UserNotFoundException());
        } else {
            $provider->method('loadUserByIdentifier')->with(CliUser::USERNAME)->willReturn($user);
        }

        return $provider;
    }

    private function cliUser(): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ADMIN']);

        return $user;
    }

    public function testOnTheConsoleTheCallbacksSeeContaoAiAndNobodyAfterwards(): void
    {
        $tokens = new TokenStorage();
        $user   = $this->cliUser();
        $seen   = null;

        $result = (new CliUser($tokens, $this->provider($user)))->asCurrentUser(static function () use ($tokens, &$seen): string {
            $seen = $tokens->getToken()?->getUser();

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame($user, $seen);
        $this->assertNull($tokens->getToken());
    }

    public function testALoggedInEditorIsLeftInPlace(): void
    {
        // The chat: the callbacks see the editor, never contao-ai.
        $editor = $this->createMock(BackendUser::class);
        $editor->method('getRoles')->willReturn(['ROLE_USER']);
        $token  = new UsernamePasswordToken($editor, 'contao_backend', ['ROLE_USER']);

        $tokens = new TokenStorage();
        $tokens->setToken($token);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->never())->method('loadUserByIdentifier');

        (new CliUser($tokens, $provider))->asCurrentUser(function () use ($tokens, $token): void {
            $this->assertSame($token, $tokens->getToken());
        });

        $this->assertSame($token, $tokens->getToken());
    }

    public function testWithoutTheUserTheCallbacksRunForNobodyAsBefore(): void
    {
        // The migration has not run yet.
        $tokens = new TokenStorage();
        $ran    = false;

        (new CliUser($tokens, $this->provider(null)))->asCurrentUser(function () use ($tokens, &$ran): void {
            $this->assertNull($tokens->getToken());
            $ran = true;
        });

        $this->assertTrue($ran);
    }

    public function testTheTokenIsRemovedEvenWhenACallbackThrows(): void
    {
        $tokens = new TokenStorage();

        try {
            (new CliUser($tokens, $this->provider($this->cliUser())))->asCurrentUser(static function (): void {
                throw new \RuntimeException('refused');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull($tokens->getToken());
    }
}
