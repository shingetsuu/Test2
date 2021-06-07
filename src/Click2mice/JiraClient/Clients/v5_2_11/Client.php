<?php

namespace Click2mice\JiraClient\Clients\v5_2_11;

use GuzzleHttp\Exception\TransferException;

class Client extends \Click2mice\JiraClient\Clients\v5_1_8\Client
{
    /**
     * Возвращает список тикетов по результатам поиска по JQL
     * @link https://docs.atlassian.com/jira/REST/5.2.11/#id105200
     * @param $jql
     * @param $fields
     * @return bool|array
     */
    public function getIssuesByJql($jql, $fields = null)
    {
        try {
            $query = ['jql' => $jql, 'maxResults' => self::RESPONSE_MAX_RESULTS, 'fields' => 'key'];
            if (isset($fields)) {
                $query['fields'] = $fields;
            }
            $result = $this->getRestClient()->get(
                "search",
                ['query' => $query]
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
     * Устанавливает в тикете значение поля, принимающего множество значений
     * @link https://docs.atlassian.com/jira/REST/5.2.11/#id109247
     * @param $issueKey
     * @param $field
     * @param $values
     * @return bool
     */
    public function setIssueMultiFieldValue($issueKey, $field, $values)
    {
        try {
            $query  = [
                'update' => [
                    $field => [
                        ['set' => $values]
                    ]
                ]
            ];
            $result = $this->getRestClient()->put(
                "issue/$issueKey",
                ['json' => $query]
            );
            return $this->checkStatusCode($result);
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Устанавливает значение поля fixVersions для тикета
     * @param $issueKey
     * @param $versionId
     * @return mixed
     */
    public function setIssueFixVersion($issueKey, $versionId)
    {
        return $this->setIssueMultiFieldValue($issueKey, 'fixVersions', [['id' => $versionId]]);
    }

    /**
     * Добавляет комментарий к тикету
     * @param $issueKey
     * @param $comment
     * @return mixed
     */
    public function addIssueComment( $issueKey, $comment )
    {
        try {
            if (!isset($comment)) {
                return true;
            }
            $result = $this->getRestClient()->post(
                "issue/$issueKey/comment",
                array(
                     'json' => array(
                         'body'     => $comment,
                     )
                )
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
     * Совершает переход для тикета по названию перехода
     * @param            $issueKey
     * @param            $transitionName
     * @param array|null $fields
     * @param null       $comment
     * @param array|null $update
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
        if ($transitions = $this->getIssueTransitions($issueKey)) {
            foreach ($transitions['transitions'] as $transition) {
                if ($transition['name'] == $transitionName) {
                    return $this->addIssueTransition(
                        $issueKey,
                        $transition['id'],
                        $fields,
                        null,
                        $update
                    ) && $this->addIssueComment($issueKey, $comment);
                }
            }
        }
        return false;
    }
}