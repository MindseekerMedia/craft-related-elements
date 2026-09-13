<?php

namespace mindseekermedia\craftrelatedelements\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enableNestedElements = true;
    public int $initialLimit = 10;
    public bool $showElementTypeLabel = true;
    public array $defaultElementTypes = ['entries', 'categories', 'assets', 'tags'];
    public array $enabledEntryTypeUids = [];
    public array $enabledSectionUids = [];

    public function rules(): array
    {
        return [
            [['enableNestedElements', 'showElementTypeLabel'], 'boolean'],
            [['initialLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['initialLimit'], 'default', 'value' => 10],
            [['defaultElementTypes', 'enabledEntryTypeUids', 'enabledSectionUids'], 'each', 'rule' => ['string']],
            [['defaultElementTypes'], 'each', 'rule' => ['in', 'range' => ['entries', 'categories', 'assets', 'tags']]],
            [['defaultElementTypes'], 'default', 'value' => ['entries', 'categories', 'assets', 'tags']],
            [['enabledEntryTypeUids'], 'default', 'value' => []],
            [['enabledSectionUids'], 'default', 'value' => []],
        ];
    }
}
