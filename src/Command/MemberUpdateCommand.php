<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:member:update', description: 'Update a frontend member field')]
class MemberUpdateCommand extends AbstractWriteCommand
{
    // 'password' deliberately excluded to prevent credential manipulation.
    // 'disable' and 'login' are intentionally allowed — sysadmin CLI is trusted to manage account state.
    private const ALLOWED_FIELDS = [
        'firstname', 'lastname', 'email', 'phone', 'mobile',
        'dateOfBirth', 'gender', 'language', 'company', 'street',
        'postal', 'city', 'state', 'country', 'website',
        'groups', 'login', 'disable', 'start', 'stop',
    ];

    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED, 'Username of the member');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $username = $this->input->getArgument('username');

        $member = MemberModel::findByUsername($username);
        if ($member === null) {
            return $this->outputError("Member not found: $username");
        }

        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        $disallowedFields = array_diff(array_keys($fields), self::ALLOWED_FIELDS);
        if (!empty($disallowedFields)) {
            return $this->outputError('Field(s) not allowed: ' . implode(', ', $disallowedFields));
        }

        foreach ($fields as $key => $value) {
            $member->$key = $value;
        }
        $member->tstamp = time();
        $member->save();

        $this->outputSuccess(['username' => $username, 'updated' => array_keys($fields)]);
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
