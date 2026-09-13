<?php

namespace mindseekermedia\craftrelatedelements\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\helpers\Json;

class Relations
{
    public const TYPES = [
        'entries' => Entry::class,
        'categories' => Category::class,
        'assets' => Asset::class,
        'tags' => Tag::class,
    ];

    public function page(Element $element, string $section, array $enabledTypes, ?string $cursor, int $limit, bool $includeNested = true): array
    {
        $enabledTypes = array_values(array_intersect(array_keys(self::TYPES), $enabledTypes));
        $offset = $this->decodeCursor($cursor);

        if (!$enabledTypes) {
            return ['items' => [], 'cursor' => null, 'hasMore' => false];
        }

        $query = $section === 'references'
            ? $this->referencesQuery($element, $enabledTypes, $includeNested)
            : $this->referencedByQuery($element, $enabledTypes);

        $rows = $query->offset($offset)->limit($limit + 1)->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int)$row['displayId'];
            $related = Craft::$app->getElements()->getElementById((int)$id, null, $element->siteId)
                ?? Craft::$app->getElements()->getElementById((int)$id);
            if (!$related || $related->id === $element->id || !Craft::$app->getElements()->canView($related)) {
                continue;
            }
            $type = $this->typeHandle($related);
            if ($type !== null && in_array($type, $enabledTypes, true)) {
                $items[] = [
                    'element' => $related,
                    'type' => $type,
                    'fieldLabel' => isset($row['sourceId']) && (int)$row['sourceId'] !== 0
                        ? $this->nestedFieldLabel((int)$row['sourceId'], $element->id)
                        : null,
                ];
            }
        }

        usort($items, static fn(array $a, array $b): int => [isset($a['fieldLabel']), $a['fieldLabel'] ?? '', $a['element']->id]
            <=> [isset($b['fieldLabel']), $b['fieldLabel'] ?? '', $b['element']->id]);

        return [
            'items' => $items,
            'cursor' => $hasMore ? $this->encodeCursor($offset + count($rows)) : null,
            'hasMore' => $hasMore,
        ];
    }

    private function referencesQuery(Element $element, array $enabledTypes, bool $includeNested): Query
    {
        $sourceIds = $includeNested ? $this->ownedElementIds($element->id) : [];
        $sourceIds[] = $element->id;

        return (new Query())
            ->select([
                'displayId' => 'COALESCE([[displayElements.canonicalId]], [[displayElements.id]])',
                'sourceId' => "MIN(CASE WHEN [[r.sourceId]] = {$element->id} THEN 0 ELSE [[r.sourceId]] END)",
            ])
            ->from(['r' => '{{%relations}}'])
            ->innerJoin(['displayElements' => '{{%elements}}'], '[[displayElements.id]] = [[r.targetId]]')
            ->where([
                'r.sourceId' => array_values(array_unique($sourceIds)),
                'displayElements.type' => array_values(array_intersect_key(self::TYPES, array_flip($enabledTypes))),
                'displayElements.dateDeleted' => null,
            ])
            ->andWhere(['or', ['r.sourceSiteId' => $element->siteId], ['r.sourceSiteId' => null]])
            ->groupBy(['displayId'])
            ->orderBy(['displayId' => SORT_ASC]);
    }

    private function referencedByQuery(Element $element, array $enabledTypes): Query
    {
        $primaryOwnerExpression = 'COALESCE([[entrySource.primaryOwnerId]], [[r.sourceId]])';
        $query = (new Query())
            ->distinct()
            ->from(['r' => '{{%relations}}'])
            ->leftJoin(['entrySource' => '{{%entries}}'], '[[entrySource.id]] = [[r.sourceId]]');

        if (Craft::$app->getDb()->getSchema()->getTableSchema('{{%neoblocks}}', true) !== null) {
            $query->leftJoin(['neoSource' => '{{%neoblocks}}'], '[[neoSource.id]] = [[r.sourceId]]');
            $primaryOwnerExpression = 'COALESCE([[entrySource.primaryOwnerId]], [[neoSource.primaryOwnerId]], [[r.sourceId]])';
        }

        return $query
            ->innerJoin(['displayElements' => '{{%elements}}'], "[[displayElements.id]] = $primaryOwnerExpression")
            ->select(['displayId' => 'COALESCE([[displayElements.canonicalId]], [[displayElements.id]])'])
            ->where([
                'r.targetId' => $element->getCanonicalId(),
                'displayElements.type' => array_values(array_intersect_key(self::TYPES, array_flip($enabledTypes))),
                'displayElements.dateDeleted' => null,
            ])
            ->andWhere(['or', ['r.sourceSiteId' => $element->siteId], ['r.sourceSiteId' => null]])
            ->andWhere(['not', ['displayElements.id' => $element->id]])
            ->orderBy(['displayId' => SORT_ASC]);
    }

    private function ownedElementIds(int $ownerId): array
    {
        $found = [];
        $owners = [$ownerId];

        while ($owners) {
            $children = (new Query())
                ->select(['elementId'])
                ->from('{{%elements_owners}}')
                ->where(['ownerId' => $owners])
                ->column();
            $children = array_values(array_diff(array_map('intval', $children), $found));
            if (!$children) {
                break;
            }
            array_push($found, ...$children);
            $owners = $children;
        }

        return $found;
    }

    private function nestedFieldLabel(int $sourceId, int $topLevelOwnerId): ?string
    {
        $labels = [];
        $currentId = $sourceId;
        $visited = [];

        while ($currentId !== $topLevelOwnerId && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $fieldId = (new Query())->select(['fieldId'])->from('{{%entries}}')->where(['id' => $currentId])->scalar();
            if ($fieldId === false && Craft::$app->getDb()->getSchema()->getTableSchema('{{%neoblocks}}', true) !== null) {
                $fieldId = (new Query())->select(['fieldId'])->from('{{%neoblocks}}')->where(['id' => $currentId])->scalar();
            }
            if ($fieldId !== false) {
                $field = Craft::$app->getFields()->getFieldById((int)$fieldId);
                if ($field !== null) {
                    $labels[] = $field->name;
                }
            }

            $ownerId = (new Query())->select(['ownerId'])->from('{{%elements_owners}}')->where(['elementId' => $currentId])->scalar();
            if ($ownerId === false) {
                break;
            }
            $currentId = (int)$ownerId;
        }

        return $labels ? implode(' → ', array_reverse($labels)) : null;
    }

    private function typeHandle(Element $element): ?string
    {
        foreach (self::TYPES as $handle => $class) {
            if ($element instanceof $class) {
                return $handle;
            }
        }
        return null;
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode(Json::encode(['offset' => $offset])), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): int
    {
        if (!$cursor) {
            return 0;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return 0;
        }

        try {
            $data = Json::decode($decoded);
            return max(0, (int)($data['offset'] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }
}
