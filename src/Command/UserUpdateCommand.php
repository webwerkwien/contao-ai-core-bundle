<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:user:update', description: 'Update a backend user field')]
class UserUpdateCommand extends AbstractWriteCommand
{
    // 'admin' and 'password' deliberately excluded to prevent privilege escalation
    private const ALLOWED_FIELDS = [
        'username', 'name', 'email', 'language', 'backendTheme', 'fullscreen',
        'description', 'groups', 'inherit', 'modules', 'themes',
        'elements', 'fields', 'pagemounts', 'alpty', 'filemounts',
        'fop', 'forms', 'formp', 'disable', 'start', 'stop',
    ];

    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED, 'Username of the user to update');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $username = $this->input->getArgument('username');

        $user = UserModel::findByUsername($username);
        if ($user === null) {
            return $this->outputError("User not found: $username");
        }

        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        $disallowedFields = array_diff(array_keys($fields), self::ALLOWED_FIELDS);
        if (!empty($disallowedFields)) {
            return $this->outputError('Field(s) not allowed: ' . implode(', ', $disallowedFields));
        }

        foreach ($fields as $key => $value) {
            $user->$key = $value;
        }
        $user->tstamp = time();
        $user->save();

        $this->outputSuccess(['username' => $username, 'updated' => array_keys($fields)]);
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
