<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Config;
use Contao\Controller;
use Contao\FrontendUser;
use Contao\MemberModel;
use Contao\System;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\RecordDataContainer;

/**
 * A front end member's password, handled the way Contao's password field handles it.
 *
 * Used by `contao:member:create` and `contao:member:password` (v0.26.0). Until then
 * no command could set a member password at all: the CLI offered `member create`, but
 * `contao:member:create` never existed, and `member:update` refuses `password` on
 * purpose, because a value written straight into the column would skip the hashing.
 *
 * **The password only arrives on standard input, never as an option.** An option is an
 * argument of the process, and on the server any user can read another process's
 * command line — the reason `contao:user:password` reads from its prompt and the CLI
 * sends it over stdin (audit 2026-09-02, H3). One line is read; only the line break is
 * stripped, because a password may begin or end with a space.
 *
 * What Contao's `Password` widget does, in its order (`Widget\Password::validator()`):
 * minimum length from the field's `minlength`, else `minPasswordLength`; not the same
 * as the username (the widget compares to `$GLOBALS['TL_USERNAME']`); then the hash.
 * The widget asks for the `BackendUser` hasher for both tables; this asks for the
 * `FrontendUser` one, which the front end login verifies against. Under Contao's
 * `Contao\User: auto` configuration both resolve to the same hasher.
 *
 * On a terminal, `--password-stdin` waits for a line and shows what is typed — it is
 * not a hidden prompt. Pipe the password in.
 * Afterwards the field's `save_callback`s run with the hash, which is how the back end
 * fires the `setNewPassword` hook (`tl_member::setNewPassword()`). The general write
 * path does not run save callbacks; this one field does, because a hook that syncs
 * passwords elsewhere must not miss a change made here.
 */
trait MemberPassword
{
    abstract protected function passwordHasherFactory(): PasswordHasherFactoryInterface;

    /**
     * The password from standard input, or null with the reason in $error.
     */
    protected function readPasswordFromStdin(InputInterface $input, ?string &$error): ?string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= \defined('STDIN') ? STDIN : null;

        $line     = null !== $stream ? fgets($stream) : false;
        $password = false === $line ? '' : rtrim($line, "\r\n");

        // An empty line is no password either — not a password of length 0.
        if ('' === $password) {
            $error = 'No password on standard input. Pipe it in, one line: '
                . 'printf \'%s\n\' "…" | … --password-stdin. It is never taken as an option.';

            return null;
        }

        return $password;
    }

    /**
     * Contao's own checks for a new password, or null when it passes.
     */
    protected function passwordProblem(string $password, string $username): ?string
    {
        if (!isset($GLOBALS['TL_DCA']['tl_member']['fields'])) {
            Controller::loadDataContainer('tl_member');
        }

        $minLength = (int) ($GLOBALS['TL_DCA']['tl_member']['fields']['password']['eval']['minlength'] ?? 0)
            ?: (int) Config::get('minPasswordLength');

        if (mb_strlen($password) < $minLength) {
            return \sprintf('The password is too short: at least %d characters (minPasswordLength). Nothing was written.', $minLength);
        }

        if ($password === $username) {
            return 'The password may not be the same as the username. Nothing was written.';
        }

        return null;
    }

    protected function hashMemberPassword(string $password): string
    {
        return $this->passwordHasherFactory()->getPasswordHasher(FrontendUser::class)->hash($password);
    }

    /**
     * Run `tl_member.password`'s save callbacks, as the back end does when the field is saved.
     *
     * They get a DataContainer, as in the back end — not the model: Contao's own
     * `setNewPassword()` accepts both, an extension's callback may declare only
     * `DataContainer`. `RecordDataContainer` is the one the other callback paths use.
     *
     * @return string the value to store — a callback may change it
     */
    protected function runPasswordSaveCallbacks(string $hash, MemberModel $member): string
    {
        $dc = RecordDataContainer::create('tl_member', (int) $member->id, $member->row());

        foreach ($GLOBALS['TL_DCA']['tl_member']['fields']['password']['save_callback'] ?? [] as $callback) {
            if (\is_array($callback)) {
                $hash = (string) System::importStatic($callback[0])->{$callback[1]}($hash, $dc);
            } elseif (\is_callable($callback)) {
                $hash = (string) $callback($hash, $dc);
            }
        }

        return $hash;
    }
}
