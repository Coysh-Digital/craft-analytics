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
    /**
     * The entries in one section - the drill-down from the section table.
     *
     * The id arrives as an action argument rather than a request param, and
     * has to: Craft's Request::resolve() hands a matched route's tokens
     * straight to runAction() and never merges them into the query params, so
     * getRequiredParam() cannot see one and throws a 400 on a URL that is
     * perfectly valid. Same reason GoalsController::actionEdit() takes its
     * uid this way.
     */
    public function actionSection(int $sectionId): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
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

    /**
     * The entries by one author — the drill-down from the author table.
     *
     * An action argument, for the reason spelled out on actionSection().
     */
    public function actionAuthor(int $authorId): Response
    {
        $site = $this->currentSite();
        $siteId = $this->siteId($site);
        $range = $this->range();
        $author = Craft::$app->getUsers()->getUserById($authorId);

        if ($author === null) {
            throw new \yii\web\NotFoundHttpException('Author not found.');
        }

        $entries = Plugin::getInstance()->getContentStats()->entriesByAuthor($siteId, $range, $authorId);

        return $this->renderTemplate('craft-analytics/content/author.twig', array_merge(
            $this->commonVariables($site, $range),
            [
                'title' => $author->getName(),
                'selectedSubnavItem' => 'content',
                'author' => $author,
                'entries' => $entries,
                'editUrls' => ElementLinks::editUrls(array_column($entries, 'elementId')),
            ],
        ));
    }
}
