<?php

// Run with: php tests/nested-relations.php /path/to/craft/project
require $argv[1] . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require_once dirname(__DIR__) . '/src/services/Relations.php';

use craft\elements\Entry;
use mindseekermedia\craftrelatedelements\services\Relations;

$db = Craft::$app->getDb();
// Warm the field cache before switching to isolated fixture tables.
Craft::$app->getFields()->getAllFields();
$element = new Entry();
$originalPrefix = $db->tablePrefix;
$db->tablePrefix = 'related_elements_test_' . bin2hex(random_bytes(6)) . '_';

try {
    foreach ([
        'elements' => 'id int PRIMARY KEY, canonicalId int NULL, type varchar(255), dateDeleted timestamp NULL, draftId int NULL, revisionId int NULL',
        'elements_owners' => 'elementId int, ownerId int',
        'relations' => 'sourceId int, targetId int, fieldId int, sourceSiteId int NULL',
    ] as $table => $columns) {
        $db->createCommand("CREATE TABLE {{%$table}} ($columns)")->execute();
    }
    $db->createCommand()->batchInsert('{{%elements}}', ['id', 'canonicalId', 'type', 'dateDeleted'], [
        [1, null, Entry::class, null],
        [2, null, Entry::class, null], // CKEditor/Matrix entry without relations
        [3, null, 'benf\\neo\\elements\\Block', null],
        [4, null, Entry::class, null], // entry inside Neo inside an entry
        [5, null, Entry::class, null], // relation target
        [6, null, Entry::class, null], // shared owner
        [7, null, Entry::class, '2026-01-01 00:00:00'],
        [8, null, 'craft\\elements\\ContentBlock', null],
        [9, null, 'craft\\elements\\ContentBlock', null],
        [10, null, Entry::class, null],
        [11, 1, Entry::class, null],
        [12, null, Entry::class, null],
        [13, 5, Entry::class, null],
    ])->execute();
    $db->createCommand()->batchInsert('{{%elements_owners}}', ['elementId', 'ownerId'], [
        [2, 1], [3, 2], [4, 3], [2, 6], [7, 1], [8, 1], [9, 8], [10, 7],
    ])->execute();
    $db->createCommand()->batchInsert('{{%relations}}', ['sourceId', 'targetId', 'fieldId', 'sourceSiteId'], [
        [4, 5, 1000000, 1], [1, 5, 1000000, 1], [3, 5, 1000000, 2],
        [9, 5, 1000000, 1], [10, 5, 1000000, 1], [11, 5, 1000000, 1],
        [11, 12, 1000000, 1], [10, 12, 1000000, 1], [7, 12, 1000000, 1],
        [1, 11, 1000000, 1], [5, 5, 1000000, 1],
    ])->execute();
    $db->createCommand()->update('{{%elements}}', ['draftId' => 1], ['id' => 11])->execute();

    $service = new Relations();
    foreach (['references', 'referencedBy'] as $direction) {
        $page = $service->page($element, $direction, ['entries'], null, 10);
        if ($page !== ['items' => [], 'cursor' => null, 'hasMore' => false]) {
            throw new RuntimeException('Unsaved element must have an empty relation page.');
        }
        echo "PASS: unsaved element $direction\n";
    }
    $element->id = 1;
    $element->siteId = 1;
    $references = new ReflectionMethod($service, 'referencesQuery');
    $incoming = new ReflectionMethod($service, 'referencedByQuery');
    $assertIds = static function(array $expected, $query, string $label): void {
        $actual = array_map('intval', array_column($query->all(), 'displayId'));
        if ($actual !== $expected) {
            throw new RuntimeException("$label: expected " . json_encode($expected) . ', got ' . json_encode($actual));
        }
        echo "PASS: $label\n";
    };
    $assertIds([2, 4, 5], $references->invoke($service, $element, ['entries'], true), 'nested entries and deduplicated relations');
    $assertIds([5], $references->invoke($service, $element, ['entries'], false), 'nested setting disabled');
    $assertIds([4], $references->invoke($service, $element, ['entries'], true)->offset(1)->limit(1), 'pagination after combining ownership and relations');
    $assertIds([], $references->invoke($service, $element, ['assets'], true), 'type filtering');
    $element->id = 5;
    $assertIds([1, 6], $incoming->invoke($service, $element, ['entries']), 'incoming relations traverse all levels and shared owners');
    $element->id = 13;
    $element->canonicalId = 5;
    $assertIds([1, 6], $incoming->invoke($service, $element, ['entries']), 'draft excludes its canonical self');
    $element->id = 5;
    $element->canonicalId = null;
    $db->createCommand()->delete('{{%relations}}', ['sourceId' => [1, 4, 10, 11]])->execute();
    $assertIds([1], $incoming->invoke($service, $element, ['entries']), 'incoming nested Content Block relationship');
    $element->id = 1;
    $assertIds([2, 4, 5], $references->invoke($service, $element, ['entries'], true), 'outgoing nested Content Block relationship excludes deleted subtrees');
    $db->createCommand()->batchInsert('{{%relations}}', ['sourceId', 'targetId', 'fieldId', 'sourceSiteId'], [
        [4, 5, 1000000, 1], [1, 5, 1000000, 1], [11, 5, 1000000, 1],
    ])->execute();
    $element->id = 5;
    $assertIds([1, 6], $incoming->invoke($service, $element, ['entries']), 'incoming excludes historical draft relations');
    $element->id = 12;
    $assertIds([], $incoming->invoke($service, $element, ['entries']), 'draft-only and deleted-container relations do not expose canonical owners');
    $element->id = 10;
    $assertIds([], $incoming->invoke($service, $element, ['entries']), 'nested entry under deleted owner does not expose ancestors');
    $element->id = 4;
    $assertIds([1, 6], $incoming->invoke($service, $element, ['entries']), 'nested entry lists owners without a relation row');
    $element->id = 1;
    $db->createCommand()->insert('{{%elements_owners}}', ['elementId' => 2, 'ownerId' => 4])->execute();
    $assertIds([2, 4, 5], $references->invoke($service, $element, ['entries'], true), 'outgoing traversal terminates with an ownership cycle');
    $element->id = 5;
    $assertIds([1, 6], $incoming->invoke($service, $element, ['entries']), 'incoming traversal terminates with an ownership cycle');

    // Match the 50,000-relation issue without hydrating 50,000 elements.
    for ($start = 100; $start < 50100; $start += 500) {
        $elements = $relations = [];
        for ($id = $start; $id < $start + 500; $id++) {
            $elements[] = [$id, Entry::class];
            $relations[] = [$id, 12, 1000000, 1];
        }
        $db->createCommand()->batchInsert('{{%elements}}', ['id', 'type'], $elements)->execute();
        $db->createCommand()->batchInsert('{{%relations}}', ['sourceId', 'targetId', 'fieldId', 'sourceSiteId'], $relations)->execute();
    }
    $element->id = 12;
    $assertIds(range(100, 110), $incoming->invoke($service, $element, ['entries'])->limit(11), '50,000 incoming relationships retain a bounded first page');
    $assertIds(range(50090, 50099), $incoming->invoke($service, $element, ['entries'])->offset(49990)->limit(11), '50,000 incoming relationships retain a bounded final page');
} finally {
    foreach (['relations', 'elements_owners', 'elements'] as $table) {
        $db->createCommand("DROP TABLE IF EXISTS {{%$table}}")->execute();
    }
    $db->tablePrefix = $originalPrefix;
}
