<?php

    namespace Hamaka\TaskForms\Tests\Tasks;

    use Hamaka\TaskForms\Controllers\GroupedTaskRunner;
    use Hamaka\TaskForms\Utils\TaskUtil;
    use SilverStripe\Core\Convert;
    use SilverStripe\Dev\BuildTask;
    use SilverStripe\Dev\TestOnly;
    use SilverStripe\PolyExecution\PolyOutput;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;

    /**
     * Class \Hamaka\TaskForms\Tests\Tasks\TestFormTask
     *
     * Task with a one-field form that needs a preview, used to test the form → dry run → live run flow in the
     * browser. It outputs 'DRY RUN for <name>' and 'LIVE RUN for <name>' markers through $output, because
     * DB::alteration_message() (and so TaskUtil::echoGood() etc.) is silenced while tests run.
     */
    class TestFormTask extends BuildTask implements TestOnly
    {
        public const FIELD_NAME = 'Name';

        public static string $category = GroupedTaskRunner::TASK_CAT_MAINTENANCE;

        protected static string $commandName = 'taskformstestform';
        protected string $title = 'Taskforms test form task';
        protected static string $description = 'Test task with a form';

        public function getOptions(): array
        {
            return TaskUtil::getInputOptionsForFields([static::FIELD_NAME], true);
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            TaskUtil::setInput($input);

            $sName           = null;
            $aGetKeyToVarMap = [static::FIELD_NAME => &$sName];
            $aRequiredFields = [static::FIELD_NAME];

            if (TaskUtil::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, $aRequiredFields)) {
                TaskUtil::echoFormHTML($aGetKeyToVarMap, $aRequiredFields, true);
            }
            elseif (TaskUtil::isDryRun($aGetKeyToVarMap, $aRequiredFields, true)) {
                $output->writeln('DRY RUN for ' . Convert::raw2xml($sName));
                TaskUtil::echoFormHTML($aGetKeyToVarMap, $aRequiredFields, true);
            }
            else {
                $output->writeln('LIVE RUN for ' . Convert::raw2xml($sName));
            }

            TaskUtil::setInput(null);

            return Command::SUCCESS;
        }
    }
