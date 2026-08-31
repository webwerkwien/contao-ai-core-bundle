<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserGroupModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a back end user group — the record that carries the permissions.
 *
 * `tl_user_group` is the permission table: which back end modules a user sees,
 * which page and file mounts they reach, which fields they may edit, which
 * tables they may create in. A user without a group can log in and do almost
 * nothing, so this is the record that makes an editor account useful.
 *
 * Only `--name` is required, and that mirrors the DCA: `name` is the single
 * mandatory field, everything else is a permission that defaults to "not
 * granted". A group created with nothing but a name is a valid, harmless group
 * — which is the right default for a permission record.
 *
 * Every permission field goes through `--set` and is a comma-separated list:
 *
 *   --set modules=page,article,files
 *   --set pagemounts=1,7          (page IDs)
 *   --set filemounts=<uuid>,<uuid>
 *   --set fop=f1,f2,f3            (file operations)
 *   --set cud=tl_news::create,tl_news::update
 *   --set alexf=tl_news::headline,tl_news::teaser
 *
 * All of them are stored as serialized arrays, and `convertFields()` does that
 * conversion from the DCA — including the binary UUIDs `filemounts` needs, and
 * including `cud`, whose widget stores a list without declaring `multiple`.
 *
 * `contao:user-group:options` lists the accepted values for each of these.
 * Without it they are discoverable only by guessing and reading the result,
 * and a wrong value in a permission field fails silently: the permission is
 * simply never granted.
 */
#[AsCommand(name: 'contao:user-group:create', description: 'Create a back end user group')]
class UserGroupCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Group name shown in the back end');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $name = (string) $this->input->getOption('name');

        if ('' === $name) {
            return $this->outputError('--name is required');
        }

        if ($this->valueTaken(UserGroupModel::class, 'name', $name)) {
            return $this->outputError(\sprintf(
                'A user group named "%s" already exists. Group names are unique in Contao.',
                $name,
            ));
        }

        $fields = $this->convertFields('tl_user_group', $fields);

        $group         = new UserGroupModel();
        $group->tstamp = time();
        $group->name   = $name;

        foreach ($fields as $key => $value) {
            $group->$key = $value;
        }

        $group->save();
        $this->createVersion('tl_user_group', (int) $group->id);

        $this->outputSuccess([
            'id'   => (int) $group->id,
            'name' => $name,
        ]);

        return Command::SUCCESS;
    }
}
