<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException as SecurityAccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
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

        $act = (string) $request->query->get('act');

        if (\in_array($act, self::LOCKED_ACTIONS, true)) {
            $ids = [(int) $request->query->get('id')];
        } elseif (\in_array($act, self::LOCKED_BULK_ACTIONS, true)) {
            // The select mode keeps its IDs in the session (`CURRENT.IDS`), set by a
            // POST the list's filter does not stand in front of (review K2).
            $current = $request->hasSession() ? $request->getSession()->get('CURRENT') : null;
            $ids     = array_map('intval', (array) (\is_array($current) ? ($current['IDS'] ?? []) : []));
        } else {
            return;
        }

        $own = (int) $this->connection->fetchOne('SELECT id FROM tl_user WHERE username = ?', [CliUser::USERNAME]);

        if ($own > 0 && \in_array($own, $ids, true)) {
            throw new AccessDeniedException(self::REFUSAL);
        }
    }

    /**
     * Nobody switches into it (review K1). Contao checks `disable` only at login, not
     * when an admin switches user, and changes made as `contao-ai` would carry its name
     * in versions and the system log — a trail that points at the bundle, not at the
     * person.
     */
    #[AsEventListener]
    public function refuseSwitch(SwitchUserEvent $event): void
    {
        if (CliUser::USERNAME === $event->getTargetUser()->getUserIdentifier()) {
            throw new SecurityAccessDeniedException(self::REFUSAL);
        }
    }

    private const REFUSAL = CliUser::REFUSAL;

    private const LOCKED_BULK_ACTIONS = ['editAll', 'overrideAll', 'deleteAll', 'copyAll', 'cutAll'];
}
