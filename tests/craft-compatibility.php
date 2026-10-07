<?php

// Run with: php tests/craft-compatibility.php /path/to/craft/project
require $argv[1] . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;

Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin(true)->one());
Craft::$app->set('request', new craft\web\Request([
    'scriptUrl' => '/index.php',
    'scriptFile' => CRAFT_BASE_PATH . '/web/index.php',
    'hostInfo' => 'http://localhost',
]));
$view = Craft::$app->getView();
$view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
$assetPath = Craft::$app->getPath()->getRuntimePath() . '/qa-assets';
craft\helpers\FileHelper::createDirectory($assetPath);
Craft::$app->getAssetManager()->basePath = $assetPath;
Craft::$app->getAssetManager()->baseUrl = '/qa-assets';
Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
$transaction = Craft::$app->getDb()->beginTransaction();
try {
    $assert = static function(bool $condition, string $label): void {
        if (!$condition) {
            throw new RuntimeException($label);
        }
        echo "PASS: $label\n";
    };
    if (version_compare(Craft::$app->getVersion(), '5.8.0', '<')) {
        $assert(!class_exists('craft\\fields\\ContentBlock'), 'Content Block class is absent');
        $assert(Craft::$app->getDb()->getSchema()->getTableSchema('{{%contentblocks}}') === null, 'Content Block table is absent');
    }
    $suffix = bin2hex(random_bytes(4));
    $entries = Craft::$app->getEntries();
    $relation = new Entries(['name' => 'QA links', 'handle' => 'qaLinks' . $suffix]);
    $assert(Craft::$app->getFields()->saveField($relation), 'save relational field');
    $layout = static function(array $fields): FieldLayout {
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements(array_map(static fn($field) => new CustomField($field), $fields));
        $layout->setTabs([$tab]);
        return $layout;
    };
    $nestedType = new EntryType(['name' => 'QA block', 'handle' => 'qaBlock' . $suffix]);
    $nestedType->setFieldLayout($layout([$relation]));
    $assert($entries->saveEntryType($nestedType), 'save Matrix entry type');
    $matrix = new Matrix(['name' => 'QA matrix', 'handle' => 'qaMatrix' . $suffix]);
    $matrix->setEntryTypes([$nestedType]);
    $assert(Craft::$app->getFields()->saveField($matrix), 'save Matrix field');
    $type = new EntryType(['name' => 'QA page', 'handle' => 'qaPage' . $suffix]);
    $type->setFieldLayout($layout([$relation, $matrix]));
    $assert($entries->saveEntryType($type), 'save section entry type');
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $section = new Section(['name' => 'QA section', 'handle' => 'qaSection' . $suffix, 'type' => Section::TYPE_CHANNEL]);
    $section->setEntryTypes([$type]);
    $section->setSiteSettings([$siteId => new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => false])]);
    $assert($entries->saveSection($section), 'save section');
    $targets = [];
    for ($i = 0; $i < 3; $i++) {
        $target = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'siteId' => $siteId, 'title' => 'QA target ' . $i]);
        $assert(Craft::$app->getElements()->saveElement($target, false, false), 'save target ' . $i);
        $targets[] = $target;
    }
    $owner = new Entry(['sectionId' => $section->id, 'typeId' => $type->id, 'siteId' => $siteId, 'title' => 'QA owner']);
    $owner->setFieldValue($relation->handle, [$targets[0]->id, $targets[1]->id]);
    $assert(Craft::$app->getElements()->saveElement($owner, false, false), 'save direct relationships');
    $nested = new Entry(['fieldId' => $matrix->id, 'typeId' => $nestedType->id, 'siteId' => $siteId, 'title' => 'QA nested']);
    $nested->setOwner($owner);
    $nested->setFieldValue($relation->handle, [$targets[2]->id]);
    $assert(Craft::$app->getElements()->saveElement($nested, false, false), 'save nested Matrix relationship');

    $plugin = Craft::$app->getPlugins()->getPlugin('related-elements');
    $service = $plugin->relations;
    $page = $service->page($owner, 'references', ['entries'], null, 2);
    $assert(count($page['items']) === 2 && $page['hasMore'], 'paginate real relationships');
    $next = $service->page($owner, 'references', ['entries'], $page['cursor'], 2);
    $assert(count($next['items']) === 2 && !$next['hasMore'], 'paginate nested relationships');
    $ids = array_map(static fn($item) => $item['element']->id, array_merge($page['items'], $next['items']));
    $assert(in_array($nested->id, $ids, true) && in_array($targets[2]->id, $ids, true), 'include Matrix entry and nested target');
    $incoming = $service->page($targets[2], 'referencedBy', ['entries'], null, 10);
    $assert(count($incoming['items']) === 1 && $incoming['items'][0]['element']->id === $owner->id, 'resolve real Matrix source to owner');
    $assert(count($service->page($owner, 'references', ['entries'], null, 10, false)['items']) === 2, 'disable nested relationships');
    $assert(count($service->page($owner, 'references', ['assets'], null, 10)['items']) === 0, 'filter element types');
    $settingsHtml = (new ReflectionMethod($plugin, 'settingsHtml'))->invoke($plugin);
    $assert(str_contains($settingsHtml, 'All entry types'), 'render plugin settings');
    $sidebar = $owner->getSidebarHtml(false);
    if (!str_contains($sidebar, 'related-elements-sidebar')) {
        throw new RuntimeException('Sidebar event not attached: ' . json_encode([
            'enabled' => $plugin->isSidebarEnabled($owner),
            'hasHandler' => $owner->hasEventHandlers(Entry::EVENT_DEFINE_SIDEBAR_HTML),
            'htmlBytes' => strlen($sidebar),
        ]));
    }
    $assert(str_contains($sidebar, 'related-elements-sidebar') && str_contains($sidebar, 'class="chip '), 'render real sidebar and native chips');
    $assert(str_contains($view->getBodyHtml(), 'relatedElementsSelectionListenerAdded'), 'register sidebar JavaScript');
    $assert(str_contains($owner->getSidebarHtml(false), 'QA matrix'), 'label Matrix relationships without Content Block table');
    $plugin->getSettings()->enabledEntryTypeUids = ['qa-no-match'];
    $assert(!$plugin->isSidebarEnabled($owner), 'respect entry type scope');
    $plugin->getSettings()->enabledEntryTypeUids = [];
    $plugin->getSettings()->enabledSectionUids = ['qa-no-match'];
    $assert(!$plugin->isSidebarEnabled($owner), 'respect section scope');
    echo 'Craft ' . Craft::$app->getVersion() . ': compatibility smoke checks passed.' . PHP_EOL;
} finally {
    $transaction->rollBack();
}
