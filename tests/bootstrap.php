<?php declare(strict_types=1);

/**
 * Test bootstrap with two modes.
 *
 * WITHOUT `CONTAO_ROOT` (plain `vendor/bin/phpunit`):
 *   Only seeds the table→model map. Contao normally builds it while booting
 *   the framework, but the command tests mock the framework away, so
 *   Model::findByPk() would fail with "no class for table". Tests that merely
 *   check argument handling and output shape pass in this mode. Tests that
 *   actually resolve a model do not — Model::findByPk() reaches DcaExtractor,
 *   which needs a real Symfony container and a database. They error out; see
 *   the second mode.
 *
 * WITH `CONTAO_ROOT` pointing at a Contao installation:
 *   Boots that installation's kernel and initialises the framework, giving the
 *   suite a real container and database. The full suite passes in this mode.
 *
 *   CONTAO_ROOT=/var/www/.../web vendor/bin/phpunit
 *
 * Verified on c5.axeltest.at (Contao 5.7.11): 114 tests, 183 assertions,
 * 0 errors, 0 failures, 4 incomplete.
 */

// Absent when the tests are run from inside a Contao installation (where the
// bundle is loaded from that installation's vendor/ instead).
$ownAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($ownAutoload)) {
    require_once $ownAutoload;
}

$contaoRoot = getenv('CONTAO_ROOT') ?: null;

if (null !== $contaoRoot && is_file($contaoRoot . '/vendor/autoload.php')) {
    require_once $contaoRoot . '/vendor/autoload.php';

    $kernel = \Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput(
        $contaoRoot,
        new \Symfony\Component\Console\Input\ArgvInput(['console', '--env=prod'])
    );
    $kernel->boot();

    $container = $kernel->getContainer();
    \Contao\System::setContainer($container);
    $container->get('contao.framework')->initialize();

    return;
}

$models = [
    'tl_article'          => Contao\ArticleModel::class,
    'tl_calendar_events'  => Contao\CalendarEventsModel::class,
    'tl_comments'         => Contao\CommentsModel::class,
    'tl_content'          => Contao\ContentModel::class,
    'tl_faq'              => Contao\FaqModel::class,
    'tl_files'            => Contao\FilesModel::class,
    'tl_layout'           => Contao\LayoutModel::class,
    'tl_member'           => Contao\MemberModel::class,
    'tl_news'             => Contao\NewsModel::class,
    'tl_page'             => Contao\PageModel::class,
    'tl_user'             => Contao\UserModel::class,
];

foreach ($models as $table => $class) {
    if (class_exists($class)) {
        $GLOBALS['TL_MODELS'][$table] = $class;
    }
}
