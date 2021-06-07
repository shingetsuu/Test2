<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MakeReleaseInstructionCommand extends ProcessCommand
{
    const FILENAME_AUTO = 'auto';

    protected function configure()
    {
        $this
        ->setName( 'make-release-instruction' )
        ->setDescription( 'Собирает инструкции к релизу' )
        ->setHelp(
                'Собирает инструкции к релизу в единый список из полей Release Instruction тикетов, интегрированных в версию.'
            )
        ->addArgument(
                'component',
                InputArgument::REQUIRED,
                'Название компонента (например: site или order-api)'
            )
        ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Числовой номер версии (например: 13083 или 1.15.2)'
            )
        ->addOption(
                'filename',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Имя файла, в который надо записать инструкции'
            )
        ->addOption(
                'email',
                'e',
                InputOption::VALUE_OPTIONAL,
                'Адрес, на который надо отправить инструкции'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version   = $input->getArgument('version');
        $component = $input->getArgument('component');

        $versionName = $this->getComponentVersionName($component, $version);
        if (!$versionName) {
            $output->writeln(sprintf('<error>неизвестный компонент: %s</error>', $component));

            return;
        }

        $filename = $input->getOption('filename');
        if ($filename && $filename == self::FILENAME_AUTO) {
            $filename = 'release-instruction-' . $versionName . '.txt';
        }

        $email = $input->getOption('email');

        $project = $this->getProject();

        $output->writeln("Компонент: <comment>$component</comment>");
        $output->writeln("Версия: <comment>$versionName</comment>");

        if ($filename) {
            $filename = 'runtime/' . $filename;
            $output->writeln("Имя файла: <comment>$filename</comment>");
        }
        if ($email) {
            $output->writeln("Отправка email: <comment>$email</comment>");
        }

        $output->write('Получение интегрированных тикетов по версии... ');
        // получаем все тикеты по версии
        $jql = "fixVersion = '$versionName' AND project = '$project' AND status IN ('"
            . implode("', '", $this->config['commands.options']['MakeReleaseInstruction'][$project . ".statuses"]) .
            "')";
        $output->writeln("\n<info>[JQL] " . $jql . "</info>");

        $issues = $this->getJiraClient()->getIssuesByJql(
            $jql,
            implode(
                ',',
                [
                'summary',
                $this->config['jira.fields.release_instruction']
                ]
            )
        );
        if ($issues) {
            $output->writeln('<info>found ' . $issues['total'] . '</info>');
            if ($issues['total']) {
                $subject = 'Инструкции к релизу ' . $versionName;
                $text    = $subject . "\n\n";
                foreach ($issues['issues'] as $issue) {
                    if (!empty($issue['fields'][$this->config['jira.fields.release_instruction']])) {
                        $instruction = $issue['fields'][$this->config['jira.fields.release_instruction']];
                        $text .= $issue['key'] . ': ' . $issue['fields']['summary'] . ' (' . $this->config['jira.url'] . '/browse/' . $issue['key'] . ')' . "\n\n";
                        $text .= $instruction . "\n\n\n";
                        $output->writeln('Добавление инструкций для <comment>' . $issue['key'] . '</comment>');
                    }
                }
                if ($filename) {
                    if ($fp = fopen($filename, 'w+')) {
                        fwrite($fp, $text);
                        fclose($fp);
                        $output->writeln('<info>Файл готов</info>');
                    } else {
                        $output->writeln('<error>Ошибка при записи файла</error>');
                    }
                }
                if ($email) {
                    $message = \Swift_Message::newInstance();
                    $message->setFrom($this->config['mail.from.email'], $this->config['mail.from.name']);
                    $message->setTo(explode(',', $email));
                    $message->setBody($text);
                    $message->setSubject($subject);
                    \Swift_Mailer::newInstance(\Swift_SendmailTransport::newInstance())->send($message);
                    $output->writeln('<info>Email отправлен</info>');
                }
                if (!$filename && !$email) {
                    $output->writeln($text);
                }
            }
        } else {
            $output->writeln('<error>ОШИБКА</error>');
            return;
        }
    }
}