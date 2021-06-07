<?php

namespace Click2mice\Sheldon\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActualizeDbSchemaCommand extends ProcessCommand
{
    protected function configure()
    {
        $this
            ->setName('actualize-db-schema')
            ->setDescription('Актуализировать хранимые процедуры и триггеры для базы wm2 в проекте db-migration')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $component = 'db-wm2';
        if (!$this->lockComponent($component)) {
            $this->waitAndLockComponent($component, $output);
        }
        $output->writeln( '<comment>Актуализация схемы БД на ' . date( 'Y-m-d H:i:s' ) . '</comment>' );

        $repoUrl = 'git@git.lan:db-migration.git';

        $repo = $this->prepareRepo( $repoUrl, $output );

        $repoPath = $repo->get_repo_path();

        $output->writeln($repoPath);

        $schemaArchiveUrl = 'http://dbarchive.lan/db1.lan/proc_func_trig.tar.bz2';
        $schemaArchive = $repoPath . '/wm2/proc_func_trig.tar.bz2';

        exec('curl ' . $schemaArchiveUrl . ' -o ' . $schemaArchive, $execStdout, $exitCode);

        if($exitCode) {
            $output->writeln('<error>Ошибка при загрузке архива:</error> '.$schemaArchiveUrl);

            return 1;
        }

        chdir(dirname($schemaArchive));

        exec('tar xjf ' . $schemaArchive, $execStdout, $exitCode);

        if($exitCode) {
            $output->writeln('<error>Ошибка при распаковке архива:</error> '.$schemaArchive);

            $repo->run('reset HEAD --hard');
            $repo->clean(true);

            return 1;
        }

        unlink($schemaArchive);

        $filesChangedCount = (int) trim($repo->run('status -s | wc -l'));
        if(!$filesChangedCount) {
            $output->writeln('<info>Кажется, ничего не изменилось</info>');
        } else {
            $output->writeln('<info>Изменилось '.$filesChangedCount.' файлов - добавляем и коммитим</info>');

            $repo->add();

            $output->write('Commiting... ');
            $repo->commit('Updating proc_trig_func');
            $output->writeln('OK');

            $output->write('Pushing "master"... ');
            $repo->push('origin', 'master');
            $output->writeln('OK');
        }
        if (!$this->lockComponent($component, true)) {
            $output->writeln("<error>Не удалось разблокировать компонет {$component}</error>");
        }
        return 0;
    }
}