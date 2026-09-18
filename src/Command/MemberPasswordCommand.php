<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Set a front end member's password (v0.26.0) — the counterpart to Contao's own
 * `contao:user:password`, which exists only for back end users.
 *
 * `contao:member:update` refuses `password` on purpose: written as a field, the value
 * would land in the column unhashed. Until v0.26.0 that left no way at all to reset a
 * member's password from the console. The password comes on standard input only,
 * checked, hashed and passed through the field's save callbacks as in the back end —
 * see MemberPassword. The change is versioned like every other write.
 */
#[AsCommand(name: 'contao:member:password', description: 'Set a front end member\'s password (password on stdin)')]
class MemberPasswordCommand extends AbstractWriteCommand
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
        $this->addArgument('username', InputArgument::REQUIRED, 'Username of the member');
        $this->addOption('password-stdin', null, InputOption::VALUE_NONE,
            'Read the new password from standard input (one line). The only way to pass it. Pipe it in — on a terminal the input is not hidden.');
    }

    protected function doExecute(array $fields): int
    {
        $username = (string) $this->input->getArgument('username');

        if ([] !== $fields) {
            return $this->outputError('This command takes no --set fields; use contao:member:update for those. Nothing was written.');
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

        $problem = $this->passwordProblem($password, $username);
        if (null !== $problem) {
            return $this->outputError($problem);
        }

        $member = MemberModel::findByUsername($username);
        if (null === $member) {
            return $this->outputError("Member not found: $username. Nothing was written.");
        }

        // Before the write, as in the back end: the save callback sees the row as it
        // was and the new value, and what it returns is what is stored.
        $hash = $this->runPasswordSaveCallbacks($this->hashMemberPassword($password), $member);

        $updated = $this->writer()->update(
            MemberModel::getTable(),
            (int) $member->id,
            ['password' => $hash],
            $this->resolveOperator(),
        );

        // null: the record went away between the lookup and the write (review before v0.26.0).
        if (null === $updated) {
            return $this->outputError("Member not found: $username. Nothing was written.");
        }

        $this->outputSuccess(['username' => $username, 'updated' => ['password']]);

        return Command::SUCCESS;
    }
}
