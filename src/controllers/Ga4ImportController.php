<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\enums\Ga4Dataset;
use coyshdigital\craftanalytics\Plugin;
use coyshdigital\craftanalytics\services\Ga4Exception;
use coyshdigital\craftanalytics\write\ImportGa4Job;
use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The GA4 history import: connecting to Google, and starting a backfill.
 *
 * Every Google call reached from here is one the operator asked for by clicking
 * something. The controller holds no analytics-viewing permission - importing
 * history is a settings-level, one-off act - so it gates on manage-settings and
 * extends the plain CP controller rather than the reports' base.
 */
class Ga4ImportController extends Controller
{
    private const STATE_SESSION_KEY = 'craftAnalytics.ga4.oauthState';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

        return true;
    }

    /**
     * Sends the operator to Google's consent screen.
     */
    public function actionOauthStart(): Response
    {
        $client = Plugin::getInstance()->getGa4Client();

        if (!$client->hasCredentials()) {
            Craft::$app->getSession()->setError(Craft::t(
                'craft-analytics',
                'Add the Client ID and Client secret first.',
            ));

            return $this->redirect(self::utilityUrl());
        }

        // A one-time value tying this redirect to this session, checked on the
        // way back so a stray callback cannot start a connection.
        $state = StringHelper::UUID();
        Craft::$app->getSession()->set(self::STATE_SESSION_KEY, $state);

        return $this->redirect($client->authUrl(self::redirectUri(), $state));
    }

    /**
     * Where Google sends the operator back, with a one-time code.
     */
    public function actionOauthCallback(): Response
    {
        $session = Craft::$app->getSession();
        $expected = $session->get(self::STATE_SESSION_KEY);
        $session->remove(self::STATE_SESSION_KEY);

        $state = (string)$this->request->getQueryParam('state');

        if ($expected === null || !hash_equals((string)$expected, $state)) {
            throw new BadRequestHttpException('Invalid OAuth state.');
        }

        $error = $this->request->getQueryParam('error');

        if ($error !== null) {
            $session->setError(Craft::t('craft-analytics', 'Google declined the connection: {error}.', ['error' => $error]));

            return $this->redirect(self::utilityUrl());
        }

        $code = (string)$this->request->getQueryParam('code');

        if ($code === '') {
            $session->setError(Craft::t('craft-analytics', 'Google returned no authorisation code.'));

            return $this->redirect(self::utilityUrl());
        }

        try {
            Plugin::getInstance()->getGa4Client()->connect($code, self::redirectUri());
            $session->setNotice(Craft::t('craft-analytics', 'Connected to Google.'));
        } catch (Ga4Exception $e) {
            $session->setError($e->getMessage());
        }

        return $this->redirect(self::utilityUrl());
    }

    /**
     * The account's GA4 properties, for the picker. AJAX, and an operator
     * action: it runs only because the connected utility asked for it.
     */
    public function actionProperties(): Response
    {
        $this->requireAcceptsJson();

        try {
            return $this->asJson(['properties' => Plugin::getInstance()->getGa4Client()->properties()]);
        } catch (Ga4Exception $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * Forgets the Google connection and its tokens.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getGa4Auth()->disconnect();
        Craft::$app->getSession()->setNotice(Craft::t('craft-analytics', 'Disconnected from Google.'));

        return $this->redirect(self::utilityUrl());
    }

    /**
     * Validates the picker, remembers the property, and queues the import.
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = Craft::$app->getSession();

        if (!$plugin->getGa4Auth()->isConnected()) {
            $session->setError(Craft::t('craft-analytics', 'Connect to Google first.'));

            return $this->redirect(self::utilityUrl());
        }

        $propertyId = (string)$this->request->getBodyParam('propertyId');
        $propertyName = (string)$this->request->getBodyParam('propertyName');
        $siteId = (int)$this->request->getBodyParam('siteId');
        $from = (string)$this->request->getBodyParam('from');
        $to = (string)$this->request->getBodyParam('to');
        /** @var string[] $groups */
        $groups = array_values(array_intersect(
            Ga4Dataset::groups(),
            (array)$this->request->getBodyParam('groups', []),
        ));

        $error = $this->validate($propertyId, $siteId, $from, $to, $groups);

        if ($error !== null) {
            $session->setError($error);

            return $this->redirect(self::utilityUrl());
        }

        $plugin->getGa4Auth()->saveProperty($propertyId, $propertyName, $siteId);

        Craft::$app->getQueue()->push(new ImportGa4Job([
            'propertyId' => $propertyId,
            'siteId' => $siteId,
            'startDate' => $from,
            'endDate' => $to,
            'groups' => $groups,
        ]));

        $session->setNotice(Craft::t(
            'craft-analytics',
            'Import started. It runs in the background; watch the queue for progress.',
        ));

        return $this->redirect(self::utilityUrl());
    }

    /**
     * @param string[] $groups
     */
    private function validate(string $propertyId, int $siteId, string $from, string $to, array $groups): ?string
    {
        $t = static fn(string $message): string => Craft::t('craft-analytics', $message);

        if (!preg_match('/^properties\/\d+$/', $propertyId)) {
            return $t('Choose a GA4 property.');
        }

        if ($siteId <= 0 || Craft::$app->getSites()->getSiteById($siteId) === null) {
            return $t('Choose a site to import into.');
        }

        if (!self::isDate($from) || !self::isDate($to)) {
            return $t('Choose a start and end date.');
        }

        if ($from > $to) {
            return $t('The start date must be on or before the end date.');
        }

        if ($to > date('Y-m-d')) {
            return $t('The end date cannot be in the future.');
        }

        if ($groups === []) {
            return $t('Choose at least one thing to import.');
        }

        return null;
    }

    private static function isDate(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && \DateTime::createFromFormat('Y-m-d', $value) !== false;
    }

    /**
     * The stable callback URL, shown in the wizard for the operator to paste
     * into Google and sent as the redirect on the way out. The two must match
     * exactly, so both come from here.
     */
    public static function redirectUri(): string
    {
        return UrlHelper::cpUrl('craft-analytics/ga4-import/oauth-callback');
    }

    private static function utilityUrl(): string
    {
        return UrlHelper::cpUrl('utilities/ga4-import');
    }
}
