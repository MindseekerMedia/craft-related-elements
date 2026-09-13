<?php

namespace mindseekermedia\craftrelatedelements;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\events\DefineHtmlEvent;
use mindseekermedia\craftrelatedelements\models\Settings;
use mindseekermedia\craftrelatedelements\services\Relations;
use yii\base\Event;

/**
 * @property-read Relations $relations
 * @method static RelatedElements getInstance()
 */
class RelatedElements extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public static ?Settings $settings = null;

    public function init(): void
    {
        parent::init();
        $this->setComponents(['relations' => Relations::class]);
        /** @var Settings $settings */
        $settings = $this->getSettings();
        self::$settings = $settings;
        Craft::$app->onInit(fn() => $this->attachEventHandlers());
    }

    private function attachEventHandlers(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event): void {
            if (!$event->sender instanceof Entry &&
                !$event->sender instanceof Category &&
                !$event->sender instanceof Asset &&
                !$event->sender instanceof Tag) {
                return;
            }
            if (!$this->isSidebarEnabled($event->sender)) {
                return;
            }
            $event->html .= $this->renderSidebar($event->sender);
        });
    }

    public function isSidebarEnabled(Element $element): bool
    {
        return !$element instanceof Entry || ($this->entryTypeEnabled($element) && $this->sectionEnabled($element));
    }

    private function entryTypeEnabled(Entry $entry): bool
    {
        $enabled = self::$settings->enabledEntryTypeUids;
        return !$enabled || in_array($entry->getType()->uid, $enabled, true);
    }

    private function sectionEnabled(Entry $entry): bool
    {
        $enabled = self::$settings->enabledSectionUids;
        $section = $entry->getSection();
        return !$enabled || ($section !== null && in_array($section->uid, $enabled, true));
    }

    private function renderSidebar(Element $element): string
    {
        $types = self::$settings->defaultElementTypes;
        $limit = self::$settings->initialLimit;
        $references = $this->relations->page($element, 'references', $types, null, $limit, self::$settings->enableNestedElements);
        $referencedBy = $this->relations->page($element, 'referencedBy', $types, null, $limit, self::$settings->enableNestedElements);

        return Craft::$app->getView()->renderTemplate('related-elements/_element-sidebar', [
            'element' => $element,
            'references' => $references,
            'referencedBy' => $referencedBy,
            'showElementTypeLabel' => self::$settings->showElementTypeLabel,
            'pageUrl' => 'related-elements/relations/page',
        ]);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $entryTypes = Craft::$app->getEntries()->getAllEntryTypes();
        usort($entryTypes, fn($a, $b) => strcasecmp($a->name, $b->name));
        $sections = Craft::$app->getEntries()->getAllSections();
        usort($sections, fn($a, $b) => strcasecmp($a->name, $b->name));

        return Craft::$app->getView()->renderTemplate('related-elements/settings', [
            'settings' => $this->getSettings(),
            'entryTypeOptions' => array_map(fn($type) => [
                'label' => sprintf('%s (%s)', $type->name, $type->handle),
                'value' => $type->uid,
            ], $entryTypes),
            'sectionOptions' => array_map(fn($section) => [
                'label' => sprintf('%s (%s)', $section->name, $section->handle),
                'value' => $section->uid,
            ], $sections),
        ]);
    }
}
