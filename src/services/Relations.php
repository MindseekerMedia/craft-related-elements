<?php

namespace mindseekermedia\craftrelatedelements\services;

use Craft;
use craft\base\Element;
use craft\base\Field;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\helpers\Json;
use yii\db\Expression;

class Relations
{
    public const TYPES = [
        'entries' => Entry::class,
        'categories' => Category::class,
        'assets' => Asset::class,
        'tags' => Tag::class,
    ];

    private array $nestedOwners = [];

    public function page(Element $element, string $section, array $enabledTypes, ?string $cursor, int $limit, bool $includeNested = true): array
    {
        $enabledTypes = array_values(array_intersect(array_keys(self::TYPES), $enabledTypes));
        $limit = max(1, min(100, $limit));
        $offset = $this->decodeCursor($cursor);

        if (!$element->id || !$enabledTypes) {
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
            if (!$related || $related->getCanonicalId() === $element->getCanonicalId() || !Craft::$app->getElements()->canView($related)) {
                continue;
            }
            $type = $this->typeHandle($related);
            if ($type !== null && in_array($type, $enabledTypes, true)) {
                $items[] = [
                    'element' => $related,
                    'type' => $type,
                    'fieldLabel' => isset($row['sourceId']) && (int)$row['sourceId'] !== 0
                        ? $this->nestedFieldLabel((int)$row['sourceId'], $element->id)
                        : $this->ckeditorFieldLabel(isset($row['directFieldId']) ? (int)$row['directFieldId'] : null),
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

        $query = (new Query())
            ->select([
                'displayId' => 'COALESCE([[displayElements.canonicalId]], [[displayElements.id]])',
                'sourceId' => "MIN(CASE WHEN [[r.sourceId]] = {$element->id} THEN 0 ELSE [[r.sourceId]] END)",
                'directFieldId' => "MIN(CASE WHEN [[r.sourceId]] = {$element->id} THEN [[r.fieldId]] ELSE NULL END)",
            ])
            ->from(['r' => '{{%relations}}'])
            ->innerJoin(['displayElements' => '{{%elements}}'], '[[displayElements.id]] = [[r.targetId]]')
            ->innerJoin(['sourceElements' => '{{%elements}}'], '[[sourceElements.id]] = [[r.sourceId]]')
            ->where([
                'r.sourceId' => array_values(array_unique($sourceIds)),
                'displayElements.type' => array_values(array_intersect_key(self::TYPES, array_flip($enabledTypes))),
                'displayElements.dateDeleted' => null,
                'sourceElements.dateDeleted' => null,
            ])
            ->andWhere(['<>', new Expression('COALESCE([[displayElements.canonicalId]], [[displayElements.id]])'), $element->getCanonicalId()])
            ->andWhere(['or', ['r.sourceSiteId' => $element->siteId], ['r.sourceSiteId' => null]])
            ->groupBy(['displayId'])
            ->orderBy(['displayId' => SORT_ASC]);

        if (!$includeNested) {
            $ckeditorFieldIds = [];
            foreach (Craft::$app->getFields()->getAllFields() as $field) {
                if ($this->isCkeditorField($field)) {
                    $ckeditorFieldIds[] = $field->id;
                }
            }
            if ($ckeditorFieldIds) {
                $query->andWhere(['not', ['r.fieldId' => $ckeditorFieldIds]]);
            }
        }

        if ($includeNested && in_array('entries', $enabledTypes, true)) {
            // Ownership is separate from relations: CKEditor and Matrix entries
            // must also appear when they contain no relational fields.
            $nested = (new Query())
                ->select([
                    'displayId' => 'COALESCE([[nested.canonicalId]], [[nested.id]])',
                    'sourceId' => 'nested.id',
                    'directFieldId' => new Expression('NULL'),
                ])
                ->from(['nested' => '{{%elements}}'])
                ->where([
                    'nested.id' => array_values(array_diff($sourceIds, [$element->id])),
                    'nested.type' => Entry::class,
                    'nested.dateDeleted' => null,
                ]);

            $query->orderBy([])->union($nested, true);
            return (new Query())
                ->select([
                    'displayId',
                    'sourceId' => 'MIN([[sourceId]])',
                    'directFieldId' => 'MIN([[directFieldId]])',
                ])
                ->from(['references' => $query])
                ->groupBy(['displayId'])
                ->orderBy(['displayId' => SORT_ASC]);
        }

        return $query;
    }

    private function referencedByQuery(Element $element, array $enabledTypes): Query
    {
        $sources = (new Query())
            ->select(['id' => 'r.sourceId'])
            ->from(['r' => '{{%relations}}'])
            ->innerJoin(['sourceElements' => '{{%elements}}'], '[[sourceElements.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $element->getCanonicalId()])
            ->andWhere(['sourceElements.dateDeleted' => null, 'sourceElements.draftId' => null, 'sourceElements.revisionId' => null])
            ->andWhere(['or', ['r.sourceSiteId' => $element->siteId], ['r.sourceSiteId' => null]]);

        // Include owners of the nested entry itself, even without a relation row.
        $sources->union((new Query())
            ->select(['id' => 'ownership.ownerId'])
            ->from(['ownership' => '{{%elements_owners}}'])
            ->innerJoin(['owner' => '{{%elements}}'], '[[owner.id]] = [[ownership.ownerId]]')
            ->where([
                'ownership.elementId' => $element->getCanonicalId(),
                'owner.dateDeleted' => null,
                'owner.draftId' => null,
                'owner.revisionId' => null,
            ]), false);

        // UNION (rather than UNION ALL) also guards against ownership cycles.
        $sources->union((new Query())
            ->select(['id' => 'ownership.ownerId'])
            ->from(['ownership' => '{{%elements_owners}}'])
            ->innerJoin(['ancestors' => 'relatedOwners'], '[[ancestors.id]] = [[ownership.elementId]]')
            ->innerJoin(['owner' => '{{%elements}}'], '[[owner.id]] = [[ownership.ownerId]]')
            ->where(['owner.dateDeleted' => null, 'owner.draftId' => null, 'owner.revisionId' => null]), false);

        return (new Query())
            ->withQuery($sources, 'relatedOwners', true)
            ->distinct()
            ->select(['displayId' => 'COALESCE([[displayElements.canonicalId]], [[displayElements.id]])'])
            ->from(['ancestors' => 'relatedOwners'])
            ->innerJoin(['displayElements' => '{{%elements}}'], '[[displayElements.id]] = [[ancestors.id]]')
            ->leftJoin(['ownership' => '{{%elements_owners}}'], '[[ownership.elementId]] = [[displayElements.id]]')
            ->where([
                'ownership.ownerId' => null,
                'displayElements.type' => array_values(array_intersect_key(self::TYPES, array_flip($enabledTypes))),
                'displayElements.dateDeleted' => null,
                'displayElements.draftId' => null,
                'displayElements.revisionId' => null,
            ])
            ->andWhere(['<>', new Expression('COALESCE([[displayElements.canonicalId]], [[displayElements.id]])'), $element->getCanonicalId()])
            ->orderBy(['displayId' => SORT_ASC]);
    }

    private function ownedElementIds(int $ownerId): array
    {
        $this->nestedOwners = [];
        $found = [$ownerId];
        $owners = [$ownerId];

        while ($owners) {
            $children = (new Query())
                ->select(['elementId', 'ownerId'])
                ->from(['ownership' => '{{%elements_owners}}'])
                ->innerJoin(['child' => '{{%elements}}'], '[[child.id]] = [[ownership.elementId]]')
                ->where(['ownerId' => $owners, 'child.dateDeleted' => null])
                ->all();
            foreach ($children as $child) {
                $this->nestedOwners[(int)$child['elementId']] ??= (int)$child['ownerId'];
            }
            $children = array_values(array_diff(array_map('intval', array_column($children, 'elementId')), $found));
            if (!$children) {
                break;
            }
            array_push($found, ...$children);
            $owners = $children;
        }

        return array_values(array_diff($found, [$ownerId]));
    }

    private function nestedFieldLabel(int $sourceId, int $topLevelOwnerId): ?string
    {
        $labels = [];
        $currentId = $sourceId;
        $visited = [];

        while ($currentId !== $topLevelOwnerId && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $fieldId = (new Query())->select(['fieldId'])->from('{{%entries}}')->where(['id' => $currentId])->scalar();
            if ($fieldId === false && Craft::$app->getDb()->getSchema()->getTableSchema('{{%contentblocks}}', true) !== null) {
                $fieldId = (new Query())->select(['fieldId'])->from('{{%contentblocks}}')->where(['id' => $currentId])->scalar();
            }
            if ($fieldId === false && Craft::$app->getDb()->getSchema()->getTableSchema('{{%neoblocks}}', true) !== null) {
                $fieldId = (new Query())->select(['fieldId'])->from('{{%neoblocks}}')->where(['id' => $currentId])->scalar();
            }
            if ($fieldId !== false) {
                $field = Craft::$app->getFields()->getFieldById((int)$fieldId);
                if ($field !== null) {
                    $labels[] = $field->name;
                }
            }

            $ownerId = $this->nestedOwners[$currentId] ?? false;
            if ($ownerId === false) {
                break;
            }
            $currentId = (int)$ownerId;
        }

        return $labels ? implode(' → ', array_reverse($labels)) : null;
    }

    private function ckeditorFieldLabel(?int $fieldId): ?string
    {
        if ($fieldId === null) {
            return null;
        }

        $field = Craft::$app->getFields()->getFieldById($fieldId);
        return $field !== null && $this->isCkeditorField($field) ? $field->name : null;
    }

    private function isCkeditorField(Field $field): bool
    {
        return $field::class === 'craft\\ckeditor\\Field' || is_subclass_of($field, 'craft\\ckeditor\\Field');
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
