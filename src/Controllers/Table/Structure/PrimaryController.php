<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\Table\Structure;

use PhpMyAdmin\Config;
use PhpMyAdmin\Controllers\AbstractController;
use PhpMyAdmin\Controllers\Table\StructureController;
use PhpMyAdmin\Current;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\DbTableExists;
use PhpMyAdmin\Http\Response;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Identifiers\DatabaseName;
use PhpMyAdmin\Identifiers\TableName;
use PhpMyAdmin\Message;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

use function __;
use function count;
use function is_array;

final class PrimaryController extends AbstractController
{
    public function __construct(
        ResponseRenderer $response,
        Template $template,
        private DatabaseInterface $dbi,
        private StructureController $structureController,
        private readonly DbTableExists $dbTableExists,
    ) {
        parent::__construct($response, $template);
    }

    public function __invoke(ServerRequest $request): Response|null
    {
        $GLOBALS['message'] ??= null;
        $GLOBALS['urlParams'] ??= null;
        $GLOBALS['errorUrl'] ??= null;

        /** @var string[]|null $selected */
        $selected = $request->getParsedBodyParam('selected_fld', $request->getParsedBodyParam('selected'));

        if (! is_array($selected) || $selected === []) {
            $this->response->setRequestStatus(false);
            $this->response->addJSON('message', __('No column selected.'));

            return null;
        }

        $this->dbi->selectDb(Current::$database);
        $hasPrimary = $this->hasPrimaryKey();

        /** @var string|null $deletionConfirmed */
        $deletionConfirmed = $request->getParsedBodyParam('mult_btn');

        if ($hasPrimary && $deletionConfirmed === null) {
            if (! $this->checkParameters(['db', 'table'])) {
                return null;
            }

            $GLOBALS['urlParams'] = ['db' => Current::$database, 'table' => Current::$table];
            $GLOBALS['errorUrl'] = Util::getScriptNameForOption(
                Config::getInstance()->settings['DefaultTabTable'],
                'table',
            );
            $GLOBALS['errorUrl'] .= Url::getCommon($GLOBALS['urlParams'], '&');

            $databaseName = DatabaseName::tryFrom($request->getParam('db'));
            if ($databaseName === null || ! $this->dbTableExists->selectDatabase($databaseName)) {
                if ($request->isAjax()) {
                    $this->response->setRequestStatus(false);
                    $this->response->addJSON('message', Message::error(__('No databases selected.')));

                    return null;
                }

                $this->response->redirectToRoute('/', ['reload' => true, 'message' => __('No databases selected.')]);

                return null;
            }

            $tableName = TableName::tryFrom($request->getParam('table'));
            if ($tableName === null || ! $this->dbTableExists->hasTable($databaseName, $tableName)) {
                if ($request->isAjax()) {
                    $this->response->setRequestStatus(false);
                    $this->response->addJSON('message', Message::error(__('No table selected.')));

                    return null;
                }

                $this->response->redirectToRoute('/', ['reload' => true, 'message' => __('No table selected.')]);

                return null;
            }

            $this->render('table/structure/primary', [
                'db' => Current::$database,
                'table' => Current::$table,
                'selected' => $selected,
            ]);

            return null;
        }

        if ($deletionConfirmed === __('Yes') || ! $hasPrimary) {
            $GLOBALS['sql_query'] = 'ALTER TABLE ' . Util::backquote(Current::$table);
            if ($hasPrimary) {
                $GLOBALS['sql_query'] .= ' DROP PRIMARY KEY,';
            }

            $GLOBALS['sql_query'] .= ' ADD PRIMARY KEY(';

            $i = 1;
            $selectedCount = count($selected);
            foreach ($selected as $field) {
                $GLOBALS['sql_query'] .= Util::backquote($field);
                $GLOBALS['sql_query'] .= $i++ === $selectedCount ? ');' : ', ';
            }

            $this->dbi->selectDb(Current::$database);
            $result = $this->dbi->tryQuery($GLOBALS['sql_query']);

            if (! $result) {
                $GLOBALS['message'] = Message::error($this->dbi->getError());
            }
        }

        if (empty($GLOBALS['message'])) {
            $GLOBALS['message'] = Message::success();
        }

        ($this->structureController)($request);

        return null;
    }

    private function hasPrimaryKey(): bool
    {
        $result = $this->dbi->query('SHOW KEYS FROM ' . Util::backquote(Current::$table));

        foreach ($result as $row) {
            if ($row['Key_name'] === 'PRIMARY') {
                return true;
            }
        }

        return false;
    }
}
