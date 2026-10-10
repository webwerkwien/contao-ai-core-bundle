<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoAiCoreBundle\Service\CliUser;

/**
 * Keeps the `contao-ai` user out of the back end's user management (v1.4.0).
 *
 * Not listed under Users, and edit, delete, copy and toggle refuse it — for admins as
 * well. A voter cannot do this: Contao decides affirmatively, and its own voter grants
 * an admin every record. The DCA can: a list filter and an onload check of the request.
 * The user exists for the bundle (see CliUser); whoever changes it in the back end
 * would only break the callbacks on the console, and a deleted one comes back with the
 * next migration.
 */
class CliUserProtectionListener
{
    private const LOCKED_ACTIONS = ['edit', 'delete', 'copy', 'toggle', 'cut'];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsHook('loadDataContainer')]
    public function hide(string $table): void
    {
        if ('tl_user' !== $table) {
            return;
        }

        $GLOBALS['TL_DCA']['tl_user']['list']['sorting']['filter'][] = ['username!=?', CliUser::USERNAME];
    }

    #[AsCallback(table: 'tl_user', target: 'config.onload')]
    public function lock(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        $id = (int) $request->query->get('id');

        if ($id < 1 || !\in_array((string) $request->query->get('act'), self::LOCKED_ACTIONS, true)) {
            return;
        }

        if ($id === (int) $this->connection->fetchOne('SELECT id FROM tl_user WHERE username = ?', [CliUser::USERNAME])) {
            throw new AccessDeniedException('The user "' . CliUser::USERNAME . '" belongs to contao-ai-core-bundle: it is what DCA callbacks see on the console, and it cannot log in.');
        }
    }
}
