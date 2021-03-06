<?php

namespace Click2mice\JiraClient\Clients\v6_4_1;

use GuzzleHttp\Exception\TransferException;

class Client extends \Click2mice\JiraClient\Clients\v6_3_8\Client
{
    protected function getRestUrl()
    {
        return $this->url.'/rest/api/2/';
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getRestClient()
    {
        if (is_null($this->restClient)) {
            $this->restClient = new \GuzzleHttp\Client(
                [
                    'base_url' => $this->getRestUrl()
                ]
            );
        }

        return $this->restClient;
    }

    /**
     * Возвращает список тикетов по результатам поиска по JQL
     * @link https://docs.atlassian.com/jira/REST/5.2.11/#id105200
     * @param $jql
     * @param $fields
     * @return bool|array
     */
    /**
     * @param $jql
     * @param null $fields
     * @return array|bool|\Exception|TransferException|mixed|\Psr\Http\Message\ResponseInterface
     */
    public function getIssuesByJql($jql, $fields = null)
    {
        try {
            $query = ['jql' => $jql, 'maxResults' => self::RESPONSE_MAX_RESULTS, 'fields' => 'key'];
            if (isset($fields)) {
                $query['fields'] = $fields;
            }
            $authData = base64_encode("$this->username:$this->password");
            $result = $this->getRestClient()->get(
                $this->getRestUrl()."search",
                [
                    'query' => $query,
                    'headers' => [
                        'Authorization' => 'Basic ' . $authData]
                ]
            );
            if ($this->checkStatusCode($result)) {
                return json_decode($result->getBody(),true);
            } else {
                return false;
            }
        } catch (TransferException $e) {
            return false;
        }
    }

    /**
     * Возвращает данные тикета
     * @param $issueKey
     * @return bool|mixed
     * aoaoaoo
     */
    public function getIssue($issueKey)
    {
        try {
            $authData = base64_encode("$this->username:$this->password");
            $result = $this->getRestClient()->get($this->getRestUrl()."issue/$issueKey",
                [
                    'headers' => [
                        'Authorization' => 'Basic ' . $authData]
                ]
            );
            if ($this->checkStatusCode($result)) {
                return json_decode($result->getBody(),true);
            } else {
                return false;
            }
        } catch (TransferException $e) {
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
        try {
            if (!isset($comment)) {
                return true;
            }
            $authData = base64_encode("$this->username:$this->password");
            $result = $this->getRestClient()->post(
                $this->getRestUrl()."issue/$issueKey/comment",
                [
                    'json' => [
                        'body'     => $comment],
                    'headers' => [
                        'Authorization' => 'Basic ' . $authData]
                ]
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
}