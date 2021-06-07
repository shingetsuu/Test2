<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Click2mice\Sheldon\Helper\VersionHelper;

class CreateQaCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('create-qa')
            ->setDescription('Собирает тикеты по Fix Version для релиза')
            ->setHelp(
                'Создаёт QA Release тикет и присобачивает к нему ссылки на тикеты с такой же Fix Version'
            )
            ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Числовой номер версии (например: 13083 или 1.15.2) или значение next (возьмет следующую по порядку версию, если для нее задана дата релиза)'
            );
    }
    // TODO: Протестировать команду
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version     = $input->getArgument('version');
        $component   = $input->getArgument('component');
        $versionName = $this->getComponentVersionName($component, $version);
        $project     = $this->getProject($component);
        $componentId = $this->getJiraClient()->getComponentInfo($project, $component)['id'];
        if (!$componentId) {
            $output->writeln("<error>Информация по компоненту {$component} в проекте {$project} не найдена</error>");
            return 1;
        }

        $fields = [
            'fields' => [
                'project'                                                  => [
                    'key' => 'QA'
                ],
                'summary'                                                  => "Release {$versionName}",
                'issuetype'                                                => [
                    'id' => $this->config['commands.options']['CreateQa'][$project . ".issuetype_id"]
                ],
                'components'                                               => [
                    [
                        'id' => $componentId,
                    ]
                ],
                $this->config['jira.fields.qa_version_issue_release_date'] => date("Y-m-d")
            ]
        ];

        $result = $this->getJiraClient()->createIssue($fields);
        if (!$result) {
            $output->writeln('<error>Ошибка при создании QA-тикета</error>');
            return 2;
        } else {
            $output->writeln("Тикет <comment>{$result['key']}</comment> успешно создан!");
        }

        $args                = array(
            'command'   => 'actualize-qa',
            'component' => $component,
            'version'   => $version,
        );
        $releaseCommandInput = new ArrayInput($args);

        $releaseCommand = $this->getApplication()->find('actualize-qa');
        $returnCode     = $releaseCommand->run($releaseCommandInput, $output);
        if ($returnCode) {
            $output->writeln('<error>Ошибка при добавлении ссылок к тикету</error>');
        }
        return $returnCode;
    }
}