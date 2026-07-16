<?php

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\Plugin;
use craft\web\Controller;
use yii\web\Response;

class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return $this->renderTemplate('craft-analytics/dashboard/index.twig');
    }
}
