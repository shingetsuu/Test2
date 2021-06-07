<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription(
                'Создаёт команду sheldon с автокомплитом'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Куда устанавливать программу?'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('path');
        if (!$path) {
            $path = $this->config['default_install_path'];
        }
        $path .= '/' . $this->config['app_name'];

        $result = file_put_contents($path, "#!/usr/bin/env php\n<?php\nrequire_once('" . ROOT_PATH . "/run.php');");

        if ($result) {
            exec('chmod a+x ' . $path);
            $path = '/etc/bash_completion.d/' . $this->config['app_name'];
            if (file_put_contents($path, $this->generateAutocompletion()))
            {
                $output->writeln('Файл автодополнения успешно установлен, перезапустите bash');
            }
            $output->writeln('<info>Установка завершена.</info>');
        } else {
            $output->writeln('<error>Ошибка при установке, возможно у вас нет прав на запись.</error>');
            return 1;
        }
        return 0;
    }

    protected function generateAutocompletion()
    {
        $commands    = $this->getApplication()->all();
        $components  = array_keys($this->config['components']);
        $commandList = array();
        foreach ($commands as $command) {
            $commandList[] = $command->getName();
        }
        $script =
            '_sheldon_command()
{
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    commands="' . implode(' ', $commandList) . '"
    components="' . implode(' ', $components) . '"
    
    if [[ ${COMP_CWORD} == 1 ]] ; then
        COMPREPLY=( $(compgen -W "${commands}" -- ${cur}) )
        return 0
    fi
    
    command="${COMP_WORDS[1]}"
    case "${command}" in
';
        foreach ($commands as $command) {
            $script .= "\t" . '"' . $command->getName() . '" )' . "\n\t\t";
            $arguments = $command->getDefinition()->getArguments();
            $options   = $command->getDefinition()->getOptions();
            $wordId    = 2;

            if ($command->getDefinition()->getArgumentCount() == 0 && empty($options)) {
                $script .= "COMPREPLY=()\n\t\treturn 0";
            }
            foreach ($arguments as $argument) {
                $script .= "if [[ \${COMP_CWORD} == {$wordId} ]] ; then\n\t\t\t";
                if ($argument->getName() == 'command_name') {
                    $script .= 'COMPREPLY=( $(compgen -W "${commands}" -- ${cur}) )';
                } elseif ($argument->getName() == 'component') {
                    $script .= 'COMPREPLY=( $(compgen -W "${components}" -- ${cur}) )';
                } else {
                    $script .= 'COMPREPLY=()';
                }
                $script .= "\n\t\t\treturn 0\n\t\tfi\n\t\t";
                $wordId++;
            }
            foreach ($options as $option) {
                if ($option->getName() == 'filename') {
                    $script .=
                        'case "${COMP_WORDS[COMP_CWORD-1]}" in 							
        -f)
            COMPREPLY=($(compgen -f -- ${cur}))
            return 0
            ;;
        *)
            COMPREPLY=($(compgen -W "-f" -- ${cur}))
            return 0
            ;;
        esac';
                }
            }
            $script .= "\n\t\t;;\n";
        }
        $script .= "\tesac\n\treturn 0\n}\n\ncomplete -F _sheldon_command {$this->config['app_name']}";
        return str_replace("\t", '    ', $script);
    }
}