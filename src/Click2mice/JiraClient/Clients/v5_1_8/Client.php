<?php

namespace Click2mice\JiraClient\Clients\v5_1_8;

use GuzzleHttp\Exception\TransferException;

class Client extends \Click2mice\JiraClient\Clients\v4_4_4\Client
{
    protected function getRestUrl()
    {
        return $this->url.'/rest/api/2/';
    }

    /**
     * Ассайнит тикет на пользователя
     * @link https://docs.atlassian.com/jira/REST/5.1.8/#id90427
     *
     * @param string $issueIdOrKey
     * @param string $username
     * @return bool
     */
    public function setIssueAssignee($issueIdOrKey, $username)
    {
        try {
            $result = $this->getRestClient()->put(
                "issue/$issueIdOrKey/assignee",
                array('json' => ['name' => $username])
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
     * Возвращает список пользователей, которые соответствуют поисковой строке $username и на которых могут быть
     * заасайнены тикиты во всех проектах $projectKeys
     * @link https://docs.atlassian.com/jira/REST/5.1.8/#id92805
     *
     * @param string  $username
     * @param array   $projectKeys
     * @param integer $startAt
     * @param integer $maxResults
     * @return mixed
     */
    public function getUserAssignableMultiProjectSearch($username, $projectKeys, $startAt = null, $maxResults = null)
    {
        try {
            $params = [
                'username'    => $username,
                'projectKeys' => implode(',', $projectKeys),
                'startAt'     => $startAt,
                'maxResults'  => $maxResults,
            ];
            $result = $this->getRestClient()->get(
                'user/assignable/multiProjectSearch?' . http_build_query($params)
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
     * Возвращает список пользователей, соответствующих поисковому запросу
     * @link https://docs.atlassian.com/jira/REST/5.1.8/#id92559
     *
     * @param string  $username
     * @param string  $project
     * @param string  $issueKey
     * @param integer $startAt
     * @param integer $maxResults
     * @param integer $actionDescriptorId
     * @return mixed
     */
    public function getUserAssignableSearch(
        $username,
        $project,
        $issueKey,
        $startAt = null,
        $maxResults = null,
        $actionDescriptorId = null
    ) {
        try {
            $params = [
                'username'           => $username,
                'project'            => $project,
                'issueKey'           => $issueKey,
                'startAt'            => $startAt,
                'maxResults'         => $maxResults,
                'actionDescriptorId' => $actionDescriptorId,
            ];
            $result = $this->getRestClient()->get(
                'user/assignable/search?' . http_build_query($params)
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
     * Возвращает список пользователей, соответствующих поисковому запросу
     * @link https://docs.atlassian.com/jira/REST/5.1.8/#id92440
     *
     * @param string  $username
     * @param integer $startAt
     * @param integer $maxResults
     * @param boolean $includeActive
     * @param boolean $includeInactive
     * @return mixed
     */
    public function getUserSearch(
        $username,
        $startAt = null,
        $maxResults = null,
        $includeActive = null,
        $includeInactive = null
    ) {
        try {
            $params = [
                'username'        => $username,
                'startAt'         => $startAt,
                'maxResults'      => $maxResults,
                'includeActive'   => $includeActive,
                'includeInactive' => $includeInactive,
            ];
            $result = $this->getRestClient()->get(
                'user/search?' . http_build_query($params)
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
     * Возвращает список пользователей, соответствующих поисковому запросу
     * @link https://docs.atlassian.com/jira/REST/5.1.8/#id92686
     *
     * @param string  $username
     * @param string  $issueKey
     * @param string  $projectKey
     * @param integer $startAt
     * @param integer $maxResults
     * @return mixed
     */
    public function getUserViewIssueSearch($username, $issueKey, $projectKey, $startAt = null, $maxResults = null)
    {
        try {
            $params = [
                'username'   => $username,
                'issueKey'   => $issueKey,
                'projectKey' => $projectKey,
                'startAt'    => $startAt,
                'maxResults' => $maxResults,
            ];
            $result = $this->getRestClient()->get(
                'user/viewissue/search?' . http_build_query($params)
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
}
