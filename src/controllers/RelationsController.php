<?php

namespace mindseekermedia\craftrelatedelements\controllers;

use Craft;
use craft\web\Controller;
use mindseekermedia\craftrelatedelements\models\Settings;
use mindseekermedia\craftrelatedelements\RelatedElements;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RelationsController extends Controller
{
    public function actionPage(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('accessCp');

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');
        $section = (string)$request->getRequiredBodyParam('section');
        $cursor = $request->getBodyParam('cursor');

        if (!in_array($section, ['references', 'referencedBy'], true)) {
            throw new BadRequestHttpException('Invalid relation page request.');
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            throw new NotFoundHttpException('Element not found.');
        }
        if (!Craft::$app->getElements()->canView($element)) {
            throw new ForbiddenHttpException('You are not allowed to view this element.');
        }

        $plugin = RelatedElements::getInstance();
        if (!$plugin->isSidebarEnabled($element)) {
            throw new ForbiddenHttpException('Related Elements is not enabled for this element.');
        }
        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $page = $plugin->relations->page($element, $section, $settings->defaultElementTypes, is_string($cursor) ? $cursor : null, $settings->initialLimit, $settings->enableNestedElements);
        $html = Craft::$app->getView()->renderTemplate('related-elements/_items', [
            'items' => $page['items'],
            'showElementTypeLabel' => $settings->showElementTypeLabel,
            'loaded' => $cursor !== null,
        ]);

        return $this->asJson([
            'html' => $html,
            'cursor' => $page['cursor'],
            'hasMore' => $page['hasMore'],
        ]);
    }
}
