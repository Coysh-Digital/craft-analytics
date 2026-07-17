<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\helpers\ElementLinks;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\web\Response;

/**
 * Traffic by Craft's own content model.
 *
 * The screen a generic analytics tool cannot show you: not "/blog/some-slug
 * got 400 views" but "the Blog section is up, Case Studies are flat, and Sam's
 * articles out-read everyone else's".
 */
class ContentController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $content = Plugin::getInstance()->getContentStats();
        $by = (string)$this->request->getParam('by', 'section');

        if (!in_array($by, ['section', 'type', 'author'], true)) {
            $by = 'section';
        }

        $common = $this->commonVariables($site, $range);

        // Carried on the range and site links so switching either keeps the
        // tab you were reading.
        $common['currentParams']['by'] = $by;

        return $this->renderTemplate('craft-analytics/content/index.twig', array_merge(
            $common,
            [
                'title' => Craft::t('craft-analytics', 'Content'),
                'selectedSubnavItem' => 'content',
                'isPro' => Plugin::getInstance()->is(Plugin::EDITION_PRO),
                'by' => $by,
                'sections' => $content->bySection($siteId, $range),
                'entryTypes' => $content->byEntryType($siteId, $range),
                'authors' => $content->byAuthor($siteId, $range),
                'exportKind' => 'content',
            ],
        ));
    }

    /**
     * The entries inside one section — the drill-down from the section table.
     */
    public function actionSection(): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $sectionId = (int)$this->request->getRequiredParam('sectionId');
        $section = Craft::$app->getEntries()->getSectionById($sectionId);

        if ($section === null) {
            throw new \yii\web\NotFoundHttpException('Section not found.');
        }

        $entries = Plugin::getInstance()->getContentStats()->entriesInSection($siteId, $range, $sectionId);

        return $this->renderTemplate('craft-analytics/content/section.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => $section->name,
                'selectedSubnavItem' => 'content',
                'section' => $section,
                'entries' => $entries,
                'editUrls' => ElementLinks::editUrls(array_column($entries, 'elementId')),
            ],
        ));
    }
}
