<?php

    namespace Hamaka\TaskForms\Tests\Tasks;

    use SilverStripe\Dev\BuildTask;
    use SilverStripe\Dev\TestOnly;
    use SilverStripe\PolyExecution\PolyOutput;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;

    /**
     * Class \Hamaka\TaskForms\Tests\Tasks\TestProvidedCategoryTask
     *
     * Task in a category that TestCategoryProvider adds.
     */
    class TestProvidedCategoryTask extends BuildTask implements TestOnly
    {
        public static string $category = TestCategoryProvider::TASK_CAT_TEST_PROVIDED;

        protected static string $commandName = 'taskformstestprovided';
        protected string $title = 'Taskforms test provided category task';

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            return Command::SUCCESS;
        }
    }
