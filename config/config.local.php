<?php
	return array(
		'jira.username'                             => 'intern',
		'jira.password'                             => 'Ji3goaG',
        'jira.default_project'                      => 'SI',
        'crucible.username' => '',
		'crucible.password' => '',
		'users' => '',
		'crucible.project_table' => '',
        'ss'=>'s',
        'commands.options'                          => [
            'Actualize'                     => [
                'SI.issuetypes'         =>['Bug'],
                'SI.statuses'           => ['Done'],

            ],
            'Process'                       => [
                'SI.status'             => 'Ready for testing.php',
                'SI.trans_name'         => 'Need actualization',

                'WDB.status'            => 'Ready for testing.php',
                'WDB.trans_name'        => 'Need actualization',
            ],
            'Test'                          => [
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
        ]
	);
