<?php declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The command tests drive real Contao model classes through a mocked
 * ContaoFramework. Because `initialize()` is mocked away, the table→model map
 * that Contao normally builds from each bundle's `config/config.php` is never
 * populated, and `Model::findByPk()` fails with
 * "There is no class for table "tl_x" registered in $GLOBALS['TL_MODELS']".
 *
 * Seeding the map here keeps that plumbing out of the individual test classes —
 * they only care about command behaviour, not about how Contao wires models.
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
