<?php
/**
 * Все значения можно переопределить в неподверсионном файле config.local.php
 */
$config = [
    'app_name'                                  => 'sheldon',
    'default_install_path'                      => '/usr/local/bin',
    'jira.url'                                  => 'http://jira.chejam.com:8080',
    'jira.username'                             => '',
    'jira.password'                             => '',
    'jira.default_project'                      => 'CM',
    'jira.fields.git_branch'                    => 'customfield_10701',
    'jira.jql.git_branch'                       => 'cf[10701]',
    'jira.fields.release_instruction'           => 'customfield_10302',
//    'jira.jql.qa_engineer'                      => 'cf[10052]',
    'jira.fields.developer'                     => 'customfield_10203',
//    'jira.fields.team_leader'                   => 'customfield_11061',
    'jira.fields.code_reviewers'                => 'customfield_10501',
//    'jira.fields.component_version'             => 'customfield_11361',
//    'jira.fields.qa_version_issue_release_date' => 'customfield_11460',
    'jira.fields.qa_issue_epic_link'            => 'customfield_10005',
    'crucible.url'                              => 'http://crucible.lan',
    'crucible.username'                         => '',
    'crucible.password'                         => '',
    'crucible.project_table'                    => [
//        'WD'    => 'CR',
//        'WDB'   => 'CR',
//        'DFX'   => 'DFX',
    ],
    'crucible.global_reviewers'                 => '',
    'crucible.review_link_mask'                 => 'http://crucible.lan/cru/%key%',
    'mail.from.email'                           => 'robot@click2mice.com',
    'mail.from.name'                            => 'Sheldon Cooper',
    'penny.enabled'                             => false,
    'penny.url'                                 => null,
    'slack.enabled'                             => false,
    'slack.endpoint'                            => '',
    'slack.username'                            => 'Sheldon Cooper',
    'slack.channel'                             => 'site-regress',
    'slack.icon'                                => ':sheldon:',
    'memcaches'                                 => [
        [
            'host' => 'localhost',
            'port' => 11211,
        ],
    ],
    'commands.options'                          => [
        'Actualize'                     => [
            'CM.issuetypes'         => ['Development task', 'Bug',"Task"],
            'CM.statuses'           => ["Ready for Release", "Ready for Testing", "Need CodeReview", "In Testing", "Planned to Testing", "Reopened"],
            'SI.issuetypes'         => ["Bug"],
            'SI.statuses'           => ["To Do"],
        ],
        'ActualizeQa'                   => [
            'WD.link_type'          => 'Release link',
            'WD.epic_link_type'     => 'Associated issues',
        ],
        'ActualizeNeedActualization'    => [
            'WD.issuetypes'         => ['Development task', 'Bug report'],
            'WD.statuses'           => ['Need actualization'],
            'WD.done'               => 'Done actualization',
            'WD.timeSpent'          => '1m',

            'WDB.issuetypes'        => ['Development task', 'Bug report'],
            'WDB.statuses'          => ['Need actualization'],
            'WDB.done'              => 'Done actualization',
            'WDB.timeSpent'         => '1m',
        ],
        'ActualizeWithDependencies'     => [
            'WD.issuetypes'         => ['Development task', 'Bug report'],
            'WD.statuses'           => ['Ready for testing.php'],
            'WD.allowed_statuses'   => ['Integrated to RC', 'Ready for RC', 'Ready for testing.php', 'In testing.php', 'Planned to testing.php'],

            'WDB.issuetypes'        => ['Development task', 'Bug report'],
            'WDB.statuses'          => ['Ready for testing.php'],
            'WDB.allowed_statuses'  => ['Integrated to RC', 'Ready for RC', 'Ready for testing.php', 'In testing.php', 'Planned to testing.php'],
        ],
        'Build'                         => [
            'WD.statuses'           => ['Integrated to RC'],

            'WDB.statuses'          => ['Integrated to RC'],
        ],
        'CreateQa'                      => [
            'WD.issuetype_id'       => '59',
        ],
        'Desintegrate'                  => [
            'WD.statuses'           => ['Integrated to RC'],
            'WD.trans_name'         => 'Remove from RC',

            'WDB.statuses'          => ['Integrated to RC'],
            'WDB.trans_name'        => 'Remove from RC',
        ],
        'Integrate'                     => [
            'WD.preview_statuses'   => ['Ready for testing.php', 'In testing.php', 'Reopened', 'Ready for RC', 'Integrated to RC', 'Released'],
            'WD.statuses'           => ['Ready for RC'],
            'WD.filter_statuses'    => ['Released', 'Integrated to RC'],
            'WD.selection_statuses' => ['Ready for RC'],
            'WD.check_statuses'     => ['Ready for RC', 'Integrated to RC', 'Released'],
            'WD.trans_name'         => 'Integrate to RC',

            'WDB.preview_statuses'  => ['Ready for testing.php', 'In testing.php', 'Reopened', 'Ready for RC', 'Integrated to RC', 'Released'],
            'WDB.statuses'          => ['Ready for RC'],
            'WDB.filter_statuses'   => ['Released', 'Integrated to RC'],
            'WDB.selection_statuses'=> ['Ready for RC'],
            'WDB.check_statuses'    => ['Ready for RC', 'Integrated to RC', 'Released'],
            'WDB.trans_name'        => 'Integrate to RC',
        ],
        'Kick'                          => [
            'WD.statuses'           => ['Integrated to RC'],
            'WD.trans_name'         => 'Remove from RC',

            'WDB.statuses'          => ['Integrated to RC'],
            'WDB.trans_name'        => 'Remove from RC',
        ],
        'MakeReleaseInstruction'        => [
            'WD.statuses'           => ['Integrated to RC'],

            'WDB.statuses'          => ['Integrated to RC'],
        ],
        'Process'                       => [
            'WD.status'             => 'Ready for testing.php',
            'WD.trans_name'         => 'Need actualization',
            'SI.status'             => 'Ready for testing.php',
            'SI.trans_name'         => 'Need actualization',


            'WDB.status'            => 'Ready for testing.php',
            'WDB.trans_name'        => 'Need actualization',
        ],
        'Release'                       => [
            'WD.filter_statuses'    => ['Integrated to RC', 'Released'],
            'WD.trans_name'         => 'Release',

            'WDB.filter_statuses'   => ['Integrated to RC', 'Released'],
            'WDB.trans_name'        => 'Release',
        ],
        'SyncCodeReviews'               => [
            'WD.issuetypes'         => ['Development task', 'Bug report'],
            'WD.statuses'           => ['Ready for code review', 'On code review'],
            'WD.trans_name'         => 'Start CR',

            'WDB.issuetypes'        => ['Development task', 'Bug report'],
            'WDB.statuses'          => ['Ready for code review', 'On code review'],
            'WDB.trans_name'        => 'Start CR',

            'DFX.issuetypes'        => ['Project Task', 'Project Bug', 'Support Bug', 'Support Change'],
            'DFX.statuses'          => ['Ready for code review', 'In code review'],
            'DFX.trans_name'        => 'Take Review',
        ],
        'Test'                          => [
            'WD.issuetypes'         => ['Development task', 'Bug report'],
            'WD.statuses'           => ['Ready for testing.php'],
            'SI.issuetypes'         => ['Development task', 'Bug', "Task"],
            'SI.statuses'           => ["To Do", "In Progress"],
            'WD.test_trans_name'    => 'Start testing.php',
            'WD.complete_trans_name'=> 'Complete testing.php (auto)',
            'WD.err_trans_name'     => 'Reopen (auto)',

            'WDB.issuetypes'         => ['Development task', 'Bug report'],
            'WDB.statuses'           => ['Ready for testing.php'],
            'WDB.test_trans_name'    => 'Start testing.php',
            'WDB.complete_trans_name'=> 'Complete testing.php (auto)',
            'WDB.err_trans_name'     => 'Reopen (auto)',
        ],
    ],
    'components.lock_dir'                       => 'runtime/components.lock',
    'components' => [
        'site'                           => [
            'repo_url'        => 'https://github.com/Vortex-V/Test.git',
            'version_mask'    => 'site-%VERSION%',
//            'phpunit_command' => 'php -d error_reporting=0 vendor/bin/phpunit.php -c test/phpunit.php.xml',
            'phpunit_command' => 'php -d error_reporting=0 phpunit -c test/phpunit.php.xml',
//            'jenkins.build'   => 'http://jenkins.lan/job/build-legacy-way-stable/buildWithParameters?version=',
            'jenkins.build'   => '',
//            'crucible_repo'   => 'legacy-way',
            'crucible_repo'   => '',
            'jira.project'    => 'SI',
        ],
    ]
];

if (file_exists(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, include __DIR__ . '/config.local.php');
}

if (file_exists(__DIR__ . '/config.users.local.php')) {
    $config = array_merge($config, include __DIR__ . '/config.users.local.php');
}
