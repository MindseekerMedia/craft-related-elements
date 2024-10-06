<?php

namespace mindseekermedia\craftrelatedelements;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\fields\Matrix;
use mindseekermedia\craftrelatedelements\models\Settings;
use yii\base\Event;

/**
 * Related Elements plugin
 *
 * @method static RelatedElements getInstance()
 * @author Mindseeker Media <dev@mindseeker.media>
 * @copyright Mindseeker Media
 * @license MIT
 */
class RelatedElements extends Plugin
{
    private static ?RelatedElements $plugin;
    /**
     * @var null|Settings
     */
    public static ?Settings $settings = null;
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;
        /** @var Settings $settings */
        $settings = self::$plugin->getSettings();
        self::$settings = $settings;

        Craft::$app->onInit(fn() => $this->attachEventHandlers());
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            fn(DefineHtmlEvent $event) => $event->html .=
                ($event->sender instanceof Entry ||
                    $event->sender instanceof Category ||
                    $event->sender instanceof Asset)
                    ? $this->renderTemplate($event->sender)
                    : ''
        );
    }

    private function renderTemplate(Entry|Category|Asset $entry): string
    {
        $relatedTypes = [
            'Entry' => Entry::class,
            'Category' => Category::class,
            'Asset' => Asset::class,
        ];

        $relatedElements = [];
        $nestedRelatedElements = [];
        $hasResults = false;
        $enableNestedElements = self::$settings->enableNestedElements;

        foreach ($relatedTypes as $type => $class) {
            $relatedElements[$type] = $class::find()->relatedTo($entry)->status(null)->orderBy('title')->all();
            if (!empty($relatedElements[$type])) {
                $hasResults = true;
            }
        }

        if ($enableNestedElements) {
            $customFields = $entry->getFieldLayout()->getCustomFields();

            foreach ($customFields as $field) {
                $isMatrixField = $field instanceof Matrix;
                $isNeoField = class_exists('\benf\neo\Field') && get_class($field) === \benf\neo\Field::class;

                if ($isMatrixField || $isNeoField) {
                    $blocks = $entry->getFieldValue($field->handle);

                    foreach ($blocks->all() as $block) {
                        foreach ($relatedTypes as $type => $class) {
                            $nestedRelatedElements[$field->name][$type] = $class::find()
                                ->relatedTo($block)
                                ->status(null)
                                ->orderBy('title')
                                ->all();

                            if (!empty($nestedRelatedElements[$field->name][$type])) {
                                $hasResults = true;
                            }
                        }
                    }
                }
            }
        }

        return Craft::$app->getView()->renderTemplate(
            'related-elements/_element-sidebar',
            [
                'hasResults' => $hasResults,
                'relatedElements' => $relatedElements,
                'nestedRelatedElements' => $nestedRelatedElements,
            ]
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate(
            'related-elements/settings',
            [ 'settings' => $this->getSettings() ]
        );
    }
}
