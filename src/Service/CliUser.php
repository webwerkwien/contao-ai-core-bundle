<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

use Contao\BackendUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The back-end user DCA callbacks see on the console (v1.4.0).
 *
 * Contao's callbacks assume a logged-in user. On the console there is none, and
 * `BackendUser::getInstance()` answers with an empty one: RecordPreviewListener warned
 * "Undefined array key admin" on every delete, generateArticleForPage gave a new page
 * no article, the 5.3 adjustPermissions built a query from no groups (c5, 2026-10-10).
 *
 * `contao-ai` is an admin that cannot log in — disabled and without a password, both
 * checked only at login by Contao's UserChecker — and is hidden and locked in the back
 * end (CliUserProtectionListener). It is put into the token storage for the duration
 * of a callback run and taken out again. **It changes nothing about what the console
 * may do**: the bundle's commands check no permissions, now as before; the callbacks
 * only stop running for nobody. Created by CliUserMigration.
 *
 * Where a user is logged in — the chat — nothing is swapped: the callbacks see the
 * editor. Where `contao-ai` does not exist yet (migration not run), they run without a
 * user, as before.
 */
class CliUser
{
    public const USERNAME = 'contao-ai';

    /** The answer wherever someone tries to change, delete or become it. */
    public const REFUSAL = 'The user "' . self::USERNAME . '" belongs to contao-ai-core-bundle: it is what DCA callbacks see on the console, and it cannot log in. Nothing was changed.';

    /**
     * @param UserProviderInterface<BackendUser> $users
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'contao.security.backend_user_provider')]
        private readonly UserProviderInterface $users,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function asCurrentUser(\Closure $work): mixed
    {
        if (null !== $this->tokenStorage->getToken()) {
            return $work();
        }

        try {
            $user = $this->users->loadUserByIdentifier(self::USERNAME);
        } catch (UserNotFoundException) {
            return $work();
        }

        $this->forgetAnonymousInstance();
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'contao_backend', $user->getRoles()));

        try {
            return $work();
        } finally {
            $this->tokenStorage->setToken(null);
        }
    }

    /**
     * `BackendUser::getInstance()` keeps the empty user it made once without a token
     * in a static property and answers with it from then on, token or not (5.3, 5.7
     * and 6.0 alike). An empty one is dropped, so the next call reads the token; a real
     * user found through the session is left alone.
     */
    private function forgetAnonymousInstance(): void
    {
        if (!property_exists(BackendUser::class, 'objInstance')) {
            return;
        }

        $property = new \ReflectionProperty(BackendUser::class, 'objInstance');
        $instance = $property->getValue();

        // getData(), not ->id: the magic getter of an empty user warns.
        if ($instance instanceof BackendUser && empty($instance->getData()['id'])) {
            $property->setValue(null, null);
        }
    }
}
