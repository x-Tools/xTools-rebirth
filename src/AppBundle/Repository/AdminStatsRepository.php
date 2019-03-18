<?php
/**
 * This file contains only the AdminStatsRepository class.
 */

declare(strict_types = 1);

namespace AppBundle\Repository;

use AppBundle\Model\Project;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;

/**
 * AdminStatsRepository is responsible for retrieving data from the database
 * about users with administrative rights on a given wiki.
 * @codeCoverageIgnore
 */
class AdminStatsRepository extends Repository
{
    /**
     * Get the URLs of the icons for the user groups.
     * @return string[] System user group name as the key, URL to image as the value.
     */
    public function getUserGroupIcons(): array
    {
        return $this->container->getParameter('user_group_icons');
    }

    /**
     * Core function to get statistics about users who have admin/patroller/steward-like permissions.
     * @param Project $project
     * @param int $start UTC timestamp.
     * @param int $end UTC timestamp.
     * @param string $type Which 'type' we're querying for, as configured in admin_stats.yml
     * @param string[] $actions Which log actions to query for.
     * @return string[][] with key for each action type (specified in admin_stats.yml), including 'total'.
     */
    public function getStats(Project $project, int $start, int $end, string $type, array $actions = []): array
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'adminstats');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $userTable = $project->getTableName('user');
        $loggingTable = $project->getTableName('logging', 'logindex');
        [$countSql, $types, $actions] = $this->getLogSqlParts($project, $type, $actions);
        $dateConditions = $this->getDateConditions($start, $end, "logging_logindex.", 'log_timestamp');

        $sql = "SELECT user_name AS `username`,
                    $countSql
                    SUM(IF(log_type != '' AND log_action != '', 1, 0)) AS `total`
                FROM $loggingTable
                JOIN $userTable ON user_id = log_user
                WHERE log_type IN ($types)
                    AND log_action IN ($actions)
                    $dateConditions
                GROUP BY user_name
                HAVING `total` > 0";

        $results = $this->executeProjectsQuery($sql)->fetchAll();

        // Cache and return.
        return $this->setCache($cacheKey, $results);
    }

    /**
     * Get the SQL to query for the given type and actions.
     * @param Project $project
     * @param string $type
     * @param string[] $requestedActions
     * @return string[]
     */
    private function getLogSqlParts(Project $project, string $type, array $requestedActions = []): array
    {
        $config = $this->getConfig($project)[$type];

        $countSql = '';
        $logTypes = [];
        $logActions = [];

        foreach ($config['actions'] as $key => $logTypeActions) {
            if (is_array($requestedActions) && !in_array($key, $requestedActions)) {
                continue;
            }

            $keyTypes = [];
            $keyActions = [];

            /** @var string|array $entry String matching 'log_type/log_action' or a configuration array. */
            foreach ($logTypeActions as $entry) {
                // admin_stats.yml gives us the log type and action as a string in the format of "type/action".
                [$logType, $logAction] = explode('/', $entry);

                $logTypes[] = $keyTypes[] = $this->getProjectsConnection()->quote($logType, \PDO::PARAM_STR);
                $logActions[] = $keyActions[] = $this->getProjectsConnection()->quote($logAction, \PDO::PARAM_STR);
            }

            $keyTypes = implode(',', array_unique($keyTypes));
            $keyActions = implode(',', array_unique($keyActions));

            $countSql .= "SUM(IF((log_type IN ($keyTypes) AND log_action IN ($keyActions)), 1, 0)) AS `$key`,\n";
        }

        return [$countSql, implode(',', array_unique($logTypes)), implode(',', array_unique($logActions))];
    }

    /**
     * Get the configuration for the given Project. This respects extensions installed on the wiki.
     * @param Project $project
     * @return array
     */
    public function getConfig(Project $project): array
    {
        $config = $this->container->getParameter('admin_stats');
        $extensions = $project->getInstalledExtensions();

        foreach ($config as $type => $values) {
            foreach ($values['actions'] as $permission => $actions) {
                $requiredExtension = $actions[0]['extension'] ?? '';
                if ('' !== $requiredExtension) {
                    unset($config[$type]['actions'][$permission][0]);

                    if (!in_array($requiredExtension, $extensions)) {
                        unset($config[$type]['actions'][$permission]);
                        continue;
                    }
                }
            }
        }

        return $config;
    }

    /**
     * Get the user_group from the config given the 'type'.
     * @param string $type
     * @return string
     */
    public function getRelevantUserGroup(string $type): string
    {
        return $this->container->getParameter('admin_stats')[$type]['user_group'];
    }

    /**
     * Get all user groups with permissions applicable to the given 'group'.
     * @param Project $project
     * @param string $type Which 'type' we're querying for, as configured in admin_stats.yml
     * @return array Each entry contains 'name' (user group) and 'rights' (the permissions).
     */
    public function getUserGroups(Project $project, string $type): array
    {
        $cacheKey = $this->getCacheKey(func_get_args(), 'admingroups');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $permissions = $this->container->getParameter('admin_stats')[$type]['permissions'];
        $userGroups = [];

        $api = $this->getMediawikiApi($project);
        $res = $api->getRequest(new SimpleRequest('query', [
            'meta' => 'siteinfo',
            'siprop' => 'usergroups',
            'list' => 'globalgroups',
            'ggpprop' => 'rights',
        ]))['query'];

        /** @var string[] $groupsProcessed Keeps track of which groups have been processed. */
//        $groupsProcessed = [];
//
//        dump(array_intersect_uassoc($res['globalgroups'], $res['globalgroups'], function ($a, $b) {
//
//        }));

        // Loop through each, ignoring any 'usergroups' for which there is also a 'globalgroup'.
        foreach (array_merge($res['usergroups'], $res['globalgroups']) as $userGroup) {
            if (in_array($userGroup['name'], $userGroups)) {
                continue;
            }

//            dump($userGroup['name']);
//            dump($userGroup['rights']);
            // If they are able to add and remove user groups,
            // we'll treat them as having the 'userrights' permission.
            if (isset($userGroup['add']) || isset($userGroup['remove'])) {
                array_push($userGroup['rights'], 'userrights');
            }

            if (count(array_intersect($userGroup['rights'], $permissions)) > 0) {
                $userGroups[] = $userGroup['name'];
            }
        }

        dump($userGroups);

        // Cache for a week and return.
        return $this->setCache($cacheKey, $userGroups, 'P7D');
    }

    /**
     * Get each group and its rights for the given Project -- including global rights.
     * @param Project $project
     * @return array Each with keys 'name' and 'rights'.
     */
    private function getGroupRights(Project $project): array
    {
        $api = $this->getMediawikiApi($project);
        $query = new SimpleRequest('query', [
            'meta' => 'siteinfo',
            'siprop' => 'usergroups',
            'list' => 'globalgroups',
            'ggpprop' => 'rights',
        ]);
        $res = $api->getRequest($query);

        // Merge in usergroups and globalgroups and make them unique by 'name'.
        // If either hash is missing from the response let it error out, as something has gone horribly wrong.
        $groupRights = [];
        foreach (array_merge($res['query']['usergroups'], $res['query']['globalgroups']) as $userGroup) {
            if (!isset($groupRights[$userGroup['name']])) {
                $groupRights[] = $userGroup;
            }
        }

        return $groupRights;
    }
}
