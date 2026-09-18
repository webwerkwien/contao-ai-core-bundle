<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Create a front end member (v0.26.0).
 *
 * 🔴 **Until v0.26.0 this command did not exist**, although the CLI offered `member
 * create` since the start and pointed at it: every call ended in "contao:member:create
 * not available. Update contao-ai-core-bundle to v1.x+". Found in the regression run
 * before 1.0 (2026-09-18) — no test had ever called it, so nothing turned red.
 *
 * - **The password comes on standard input** (`--password-stdin`), never as an option,
 *   checked and hashed as Contao's password field does it — see MemberPassword.
 * - **Every value passes preparedFields()**, so the DCA judges it: `username` and
 *   `email` unique, `email` a valid address, the columns known.
 * - **`login` is set** — a member created with a username and a password is created to
 *   log in; in the back end the two fields only appear once `login` is ticked. `--set
 *   login=` overrides it.
 * - **`dateAdded` is now**, what `tl_member::storeDateAdded()` stores on the first save.
 * - **Further fields with --set**, the same list `contao:member:update` accepts
 *   (`groups=1,2`, `disable`, `start`, address fields …). `password` is not among them.
 *
 * The answer never contains the password or its hash.
 */
#[AsCommand(name: 'contao:member:create', description: 'Create a front end member (password on stdin)')]
class MemberCreateCommand extends AbstractWriteCommand
{
    use MemberPassword;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
        parent::__construct();
    }

    protected function passwordHasherFactory(): PasswordHasherFactoryInterface
    {
        return $this->hasherFactory;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('username', null, InputOption::VALUE_REQUIRED, 'Login name, unique');
        $this->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'First name');
        $this->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'Last name');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail address, unique');
        $this->addOption('password-stdin', null, InputOption::VALUE_NONE,
            'Read the password from standard input (one line). The only way to pass it. Pipe it in — on a terminal the input is not hidden.');
    }

    protected function doExecute(array $fields): int
    {
        $own = [];
        foreach (['username', 'firstname', 'lastname', 'email'] as $option) {
            $own[$option] = trim((string) $this->input->getOption($option));

            if ('' === $own[$option]) {
                return $this->outputError(\sprintf('--%s is required. Nothing was written.', $option));
            }
        }

        // Before anything else: shape of the request, no database needed.
        $disallowed = array_diff(array_keys($fields), MemberUpdateCommand::ALLOWED_FIELDS, ['login']);
        if ([] !== $disallowed) {
            return $this->outputError(\sprintf(
                'Field(s) not allowed: %s. The password only comes through --password-stdin. Nothing was written.',
                implode(', ', $disallowed),
            ));
        }

        if (!$this->input->getOption('password-stdin')) {
            return $this->outputError('--password-stdin is required: the password is read from standard '
                . 'input, never taken as an option. Nothing was written.');
        }

        $error    = null;
        $password = $this->readPasswordFromStdin($this->input, $error);
        if (null === $password) {
            return $this->outputError($error . ' Nothing was written.');
        }

        $this->framework->initialize();

        $problem = $this->passwordProblem($password, $own['username']);
        if (null !== $problem) {
            return $this->outputError($problem);
        }

        $fields = $this->preparedFields('tl_member', $own + [
            'login'     => '1',
            'dateAdded' => (string) time(),
            'password'  => $this->hashMemberPassword($password),
        ], $fields);

        $member         = new MemberModel();
        $member->tstamp = time();

        foreach ($fields as $key => $value) {
            $member->$key = $value;
        }

        $member->save();
        $this->createVersion('tl_member', (int) $member->id, created: true);

        // After the save, like the back end, where the record exists before its
        // password field is submitted — so `setNewPassword` finds the row. Unlike the
        // back end, the row it re-reads already holds the new hash; the hook's own
        // argument is the same either way.
        //
        // After the version too: a callback that throws must not leave a member that
        // exists without a version, and the error has to say that it exists (review
        // before v0.26.0).
        try {
            $hash = $this->runPasswordSaveCallbacks((string) $member->password, $member);
        } catch (\Throwable $e) {
            return $this->outputError(\sprintf(
                'Member created (id %d, username %s) with its password, but a save callback of '
                . 'tl_member.password failed: %s. The setNewPassword hook may not have completed.',
                (int) $member->id,
                $own['username'],
                $e->getMessage(),
            ));
        }

        if ($hash !== $member->password) {
            $member->password = $hash;
            $member->save();
        }

        $this->outputSuccess([
            'id'       => (int) $member->id,
            'username' => $own['username'],
            'login'    => (bool) $member->login,
        ]);

        return Command::SUCCESS;
    }
}
