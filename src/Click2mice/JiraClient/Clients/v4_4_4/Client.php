<?php

namespace Click2mice\JiraClient\Clients\v4_4_4;

use GuzzleHttp\Exception\TransferException,
    Click2mice\JiraClient\Clients\Common;

class Client extends Common
{
    protected function getRestUrl()
    {
        return $this->url.'/rest/api/2.0.alpha1/';
    }

    /**
     * Возвращает список проектов
     *
     * @return bool|array
     */
    public function getProjects()
    {
        try {
            $result = $this->getRestClient()->get('project');
            if ($this->checkStatusCode($result)) {
                return $result->json();
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает список компонентов в проекте
     * @param $project
     * @return bool|array
     */
    public function getComponents($project)
    {
        try {
            $result = $this->getRestClient()->get("project/$project/components");
            if ($this->checkStatusCode($result)) {
                return $result->json();
            }
            else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает список версий в проекте
     * @param $project
     * @return bool|array
     */
    public function getVersions( $project )
    {
        try {
            $result = $this->getRestClient()->get("project/$project/versions?expand");
            if ($this->checkStatusCode($result)) {
                return $result->json();
            }
            else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Создает версию
     * @param        $project
     * @param        $name
     * @param string $description
     * @return bool|mixed
     */
    public function createVersion($project, $name, $description = '')
    {
        try {
            $result = $this->getRestClient()->post("version", array(
                    'json' => array(
                        'name'    => $name,
                        'project' => $project,
                        'description' => $description,
                        'released' => false,
                        'archived' => false
                    )
                ));

            if ($this->checkStatusCode($result)) {
                return $result->json();
            } else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает кол-во тикетов по версии
     * @param $versionId
     * @return bool|int
     */
    public function getVersionIssuesCount( $versionId )
    {
        try {
            $result = $this->getRestClient()->get("version/$versionId/relatedIssueCounts");
            if ($this->checkStatusCode($result)) {
                $versionStat = $result->json();
                return $versionStat['issuesFixedCount'];
            }
            else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает список тикетов по результатам поиска по JQL
     * @param $jql
     * @return bool|array
     */
    public function getIssuesByJql($jql, $fields = null)
    {
        try {
            $result = $this->getRestClient()->get(
                "search",
                ['query' => ['jql' => $jql, 'maxResults' => self::RESPONSE_MAX_RESULTS]]
            );
            if ($this->checkStatusCode($result)) {
                return $result->json();
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает список возможных переходов у тикета
     * @param $issueKey
     * @return bool|mixed
     */
    public function getIssueTransitions( $issueKey )
    {
        try {
            $result = $this->getRestClient()->get("issue/$issueKey/transitions");
            if ($this->checkStatusCode($result)) {
                return $result->json();
            }
            else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Совершает переход для тикета (меняет статус)
     * @param $issueKey
     * @param $transitionId
     * @param array|null $fields
     * @param null       $comment
     * @param null       $update
     * @return bool
     */
    public function addIssueTransition($issueKey, $transitionId, $fields = null, $comment = null, $update = null)
    {
        try {
            $result = $this->getRestClient()->post("issue/$issueKey/transitions", array(
                    'json' => array(
                        'transition' => $transitionId,
                        'fields' => new \ArrayObject( is_array( $fields ) ? $fields : array() ),
                        'comment' => $comment,
                        'update' => $update,
                    )
                ));
            if ($this->checkStatusCode($result)) {
                return true;
            }
            else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Совершает переход для тикета по названию перехода
     * @param            $issueKey
     * @param            $transitionName
     * @param array|null $fields
     * @param null       $comment
     * @param null       $update
     * @return bool
     */
    public function addIssueTransitionByName(
        $issueKey,
        $transitionName,
        $fields = null,
        $comment = null,
        $update = null
    )
    {
        if ( $transitions = $this->getIssueTransitions( $issueKey ) ) {
            foreach ( $transitions as $transitionId => $transition ) {
                if ( $transition['name'] == $transitionName ) {
                    return $this->addIssueTransition($issueKey, $transitionId, $fields, $comment, $update);
                }
            }
        }

        return false;
    }

    /**
     * Возвращает данные тикета
     * @param $issueKey
     * @return bool|mixed
     */
    public function getIssue($issueKey)
    {
        try {
            $result = $this->getRestClient()->get("issue/$issueKey");
            if ($this->checkStatusCode($result)) {
                return $result->json();
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Меняет аттрибуты версии
     * @param $versionId
     * @param null $released
     * @param null $archived
     * @param null $releaseDate
     * @return bool
     */
    public function editVersion( $versionId, $released = null, $archived = null, $releaseDate = null )
    {
        $params = array();
        if ( !is_null( $released ) ) {
            $params['released'] = $released;
        }
        if ( !is_null( $archived ) ) {
            $params['archived'] = $archived;
        }
        if ( !is_null( $releaseDate ) ) {
            $params['releaseDate'] = $releaseDate;
        }

        try {
            $result = $this->getRestClient()->put("version/$versionId", array('json' => $params));
            if ($this->checkStatusCode($result)) {
                return true;
            }
            else {
                return false;
            }
        }
        catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Добавляет комментарий к тикету
     * @param $issueKey
     * @param $comment
     * @return mixed
     */
    public function addIssueComment( $issueKey, $comment )
    {
        if ( ! empty( $comment ) ) {
            return $this->getSoapClient()->addComment( $this->getSoapAuthToken(), $issueKey, array( 'body' => $comment ) );
        }
        else {
            return false;
        }
    }

    /**
     * Устанавливает в тикете значение поля, принимающего множество значений
     * @param $issueKey
     * @param $field
     * @param $value
     * @return mixed
     */
    public function setIssueMultiFieldValue( $issueKey, $field, $values )
    {
        return $this->getSoapClient()->updateIssue( $this->getSoapAuthToken(), $issueKey, array(
            array(
                'id' => $field,
                'values' => $values
            )
        ));
    }

    /**
     * Устанавливает значение поля fixVersions для тикета
     * @param $issueKey
     * @param $versionId
     * @return mixed
     */
    public function setIssueFixVersion( $issueKey, $versionId )
    {
        return $this->setIssueMultiFieldValue( $issueKey, 'fixVersions', array($versionId) );
    }

    /**
     * Возвращает данные пользователя
     * @param $username
     * @return mixed
     */
    public function getUser($username)
    {
        try {
            $result = $this->getRestClient()->get("user/?username=$username");
            if ($this->checkStatusCode($result)) {
                return $result->json();
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Добавляет зависимость к тикету
     * @param $issueKey
     * @param $linkKey
     * @param $type
     * @return bool
     */
    public function addLinkToIssue($issueKey, $linkKey, $type)
    {
        try {
            $query  = [
                'type'         => [
                    'name' => $type
                ],
                'outwardIssue' => [
                    'key' => $linkKey
                ],
                'inwardIssue'  => [
                    'key' => $issueKey
                ]
            ];
            $result = $this->getRestClient()->post(
                "issueLink",
                ['json' => $query]
            );
            if ($this->checkStatusCode($result)) {
                return true;
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Удаляет зависимость из тикета
     * @param $linkId
     * @return bool
     */
    public function deleteLinkFromIssue($linkId)
    {
        try {
            $result = $this->getRestClient()->delete("issueLink/{$linkId}");
            if ($this->checkStatusCode($result)) {
                return true;
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Создаёт тикет
     * @param $fields
     * @return bool|array
     */
    public function createIssue($fields)
    {
        try {
            $result = $this->getRestClient()->post(
                "issueLink",
                ['json' => $fields]
            );
            if ($this->checkStatusCode($result)) {
                return $result->json();
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает инфу о компоненте
     * @param string $project
     * @param string $component
     * @return bool|array
     */
    public function getComponentInfo($project, $component)
    {
        $components = $this->getComponents($project);

        foreach ($components as $item) {
            if ($item['name'] == $component) {
                return $item;
            }
        }
        return false;
    }

    /**
     * Возвращает историю переходов
     * @param $issueKey
     * @return bool|array
     */
    public function getTransitionsHistory($issueKey)
    {
        try {
            $result = $this->getRestClient()->get("issue/{$issueKey}?expand=changelog");
            if ($this->checkStatusCode($result)) {
                $result      = $result->json();
                $transitions = array();

                foreach ($result['changelog']['histories'] as $history) {
                    foreach ($history['items'] as $change) {
                        if ($change['field'] == 'status') {
                            $transitions[] = $history;
                        }
                    }
                }

                return $transitions;
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }
}