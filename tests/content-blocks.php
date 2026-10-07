<?php

// Run with: php tests/content-blocks.php /path/to/craft/project
require $argv[1] . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require_once dirname(__DIR__) . '/src/services/Relations.php';

use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ContentBlock as ContentBlockField;
use craft\fields\Entries;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use mindseekermedia\craftrelatedelements\services\Relations;

if (!class_exists(ContentBlockField::class)) {
    throw new RuntimeException('This integration test requires Craft 5.8 or newer.');
}

$owner = Entry::find()->section('*')->status(null)->one();
if (!$owner) {
    throw new RuntimeException('This integration test requires an existing top-level entry.');
}

// No fixture data or project-config YAML is retained.
$config = Craft::$app->getProjectConfig();
$config->writeYamlAutomatically = false;
$transaction = Craft::$app->getDb()->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $target = new Entry([
        'sectionId' => $owner->sectionId,
        'typeId' => $owner->typeId,
        'siteId' => $owner->siteId,
        'title' => 'QA Content Block target ' . $suffix,
        'slug' => 'qa-content-block-' . $suffix,
    ]);
    if (!Craft::$app->getElements()->saveElement($target, false, false)) {
        throw new RuntimeException(json_encode($target->getErrors()));
    }
    $relation = new Entries(['name' => 'QA relation', 'handle' => 'qaRelation' . $suffix]);
    $fields = Craft::$app->getFields();
    $save = static function($field) use ($fields): void {
        if (!$fields->saveField($field)) {
            throw new RuntimeException(json_encode($field->getErrors()));
        }
    };
    $layout = static function(array $fields): FieldLayout {
        $layout = new FieldLayout(['type' => ContentBlock::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements(array_map(static fn($field) => new CustomField($field), $fields));
        $layout->setTabs([$tab]);
        return $layout;
    };
    $save($relation);
    $innerField = new ContentBlockField(['name' => 'QA inner block', 'handle' => 'qaInner' . $suffix]);
    $innerField->setFieldLayout($layout([$relation]));
    $save($innerField);
    $outerField = new ContentBlockField(['name' => 'QA outer block', 'handle' => 'qaOuter' . $suffix]);
    $outerField->setFieldLayout($layout([$innerField]));
    $save($outerField);

    $outer = new ContentBlock(['fieldId' => $outerField->id, 'siteId' => $owner->siteId]);
    $outer->setOwner($owner);
    if (!Craft::$app->getElements()->saveElement($outer, false, false)) {
        throw new RuntimeException(json_encode($outer->getErrors()));
    }
    $inner = new ContentBlock(['fieldId' => $innerField->id, 'siteId' => $owner->siteId]);
    $inner->setOwner($outer);
    $inner->setFieldValue($relation->handle, [$target->id]);
    if (!Craft::$app->getElements()->saveElement($inner, false, false)) {
        throw new RuntimeException(json_encode($inner->getErrors()));
    }

    $service = new Relations();
    $outgoing = (new ReflectionMethod($service, 'referencesQuery'))->invoke($service, $owner, ['entries'], true)->all();
    $row = array_values(array_filter($outgoing, static fn($row) => (int)$row['displayId'] === $target->id && (int)$row['sourceId'] === $inner->id));
    if (!$row) {
        throw new RuntimeException('Craft-saved nested Content Block relation was not found.');
    }
    echo "PASS: outgoing relation saved through real Content Block fields\n";
    $label = (new ReflectionMethod($service, 'nestedFieldLabel'))->invoke($service, $inner->id, $owner->id);
    if ($label !== 'QA outer block → QA inner block') {
        throw new RuntimeException('Incorrect nested Content Block label: ' . $label);
    }
    echo "PASS: nested Content Block field label\n";
    $incoming = (new ReflectionMethod($service, 'referencedByQuery'))->invoke($service, $target, ['entries'])->column();
    if (!in_array($owner->id, array_map('intval', $incoming), true)) {
        throw new RuntimeException('Incoming Content Block relation did not resolve to the owner.');
    }
    echo "PASS: incoming relation saved through real Content Block fields\n";
} finally {
    $transaction->rollBack();
}
