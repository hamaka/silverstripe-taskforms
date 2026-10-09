<?php

    namespace Hamaka\TaskForms\Tests\Tasks;

    use Hamaka\TaskForms\Controllers\GroupedTaskRunner;
    use SilverStripe\Dev\BuildTask;
    use SilverStripe\Dev\TestOnly;
    use SilverStripe\PolyExecution\PolyOutput;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;

    /**
     * Class \Hamaka\TaskForms\Tests\Tasks\TestHiddenTask
     *
     * Task in TASK_CAT_HIDDEN: not listed on /dev/tasks, but it can run through its URL.
     */
    class TestHiddenTask extends BuildTask implements TestOnly
    {
        public static string $category = GroupedTaskRunner::TASK_CAT_HIDDEN;

        protected static string $commandName = 'taskformstesthidden';
        protected string $title = 'Taskforms test hidden task';

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $output->writeln('HIDDEN TASK RAN');

            return Command::SUCCESS;
        }
    }
