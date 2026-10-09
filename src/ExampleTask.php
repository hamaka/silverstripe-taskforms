<?php

    namespace Hamaka\TaskForms;

    use Hamaka\TaskForms\Utils\TaskUtil;
    use SilverStripe\Core\Convert;
    use SilverStripe\Dev\BuildTask;
    use SilverStripe\Forms\DropdownField;
    use SilverStripe\PolyExecution\PolyOutput;
    use SilverStripe\Security\Member;
    use SilverStripe\Security\Permission;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;

    /**
     * Class \Hamaka\TaskForms\ExampleTask
     *
     * Demonstrates the features of this module: a form generated from task variables, required fields, a dry run that
     * has to be confirmed before the live run, styled messages, a table and confetti. The task changes the first name
     * of a Member. It also shows how to add a category to /dev/tasks with TaskCategoryProvider.
     *
     * Disabled by default; see $is_enabled.
     */
    class ExampleTask extends BuildTask implements TaskCategoryProvider
    {
        public const TASK_CAT_DEMO = 'TASK_CAT_DEMO';

        // the fields of the form: request keys in the browser and options on the command line
        public const FIELD_MEMBER_ID = 'MemberID';
        public const FIELD_NEW_FIRST_NAME = 'New_FirstName';

        // Have a look at dev/tasks to see the categories.
        // The function provideTaskCategory at the bottom adds this new category to the ones
        // already in GroupedTaskRunner like GroupedTaskRunner::TASK_CAT_MAINTENANCE
        public static string $category = self::TASK_CAT_DEMO;

        // Disabled by default, so the example does not show up on every site. Enable it in YAML:
        // Hamaka\TaskForms\ExampleTask:
        //   is_enabled: true
        private static bool $is_enabled = false;

        protected static string $commandName = 'taskformsexample';
        protected string $title = 'TaskForms ExampleTask';
        protected static string $description = 'Demonstration of the features of taskforms module';

        /**
         * Declares the form fields as options, so the task can also run with sake:
         * sake tasks:taskformsexample --MemberID=3 --New_FirstName=Grace --execute=1
         */
        public function getOptions(): array
        {
            // true: the action needs to be previewed, like $bDoesActionNeedToBePreviewed in execute()
            return TaskUtil::getInputOptionsForFields([static::FIELD_MEMBER_ID, static::FIELD_NEW_FIRST_NAME], true);
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            // lets the helpers read the options from getOptions(), so the task works on the command line too
            TaskUtil::setInput($input);

            // this adds CSS to the task
            TaskUtil::makePretty();
            
            // echoNormal, echoGood, echoNotice, echoError and echoCommentOnPrev are shorthands
            // for styled messages like DB::alteration_message('Something is wrong', TaskUtil::ALT_MESSAGE_TYPE_ERROR);
            // Messages are output as HTML: escape values from the request or the database with Convert::raw2xml()
            TaskUtil::echoNormal('Changes the first name of a member. Have a look at the source code of this task to see how the form is made.');

            // here we set up some vars to load the form input in
            // values from the request are strings, so cast them where another type is needed
            $sMemberID  = null;
            $sFirstName = null;

            // keys are the request keys (and option names), values are the references to the vars
            // the & is important. In the form, underscores in the keys become spaces in the labels.
            $aGetKeyToVarMap = [
                static::FIELD_MEMBER_ID      => &$sMemberID,
                static::FIELD_NEW_FIRST_NAME => &$sFirstName
            ];

            // which fields are required to execute the task? Use the keys of $aGetKeyToVarMap (their labels, with
            // spaces instead of underscores, work too). A field without a value counts as missing.
            // has to be at least 1
            $aRequiredFieldsNamesAndVars = [
                static::FIELD_MEMBER_ID,
                static::FIELD_NEW_FIRST_NAME
            ];

            $bDoesActionNeedToBePreviewed = true;
            // if $bDoesActionNeedToBePreviewed === true
            //   > the first run after filling in the form is marked as 'dry run' - a confirmation checkbox is added to the form
            // if $bDoesActionNeedToBePreviewed !== true
            //   > the first run after filling in the form is marked as 'live'
            // It is up to you how you use dry run / live run in your task. See below for an example.

            $bIsFirstLoad = TaskUtil::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars);
            $bIsDryRun    = TaskUtil::isDryRun($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed);

            // By default a textfield will be scaffolded for every var.
            // It is possible to pass an array with Silverstripe fields to echoFormHTML to build complex forms
            $aCustomComponents = [
                // the title of a field is used as its label
                static::FIELD_MEMBER_ID => DropdownField::create(static::FIELD_MEMBER_ID, 'Member', Member::get()->sort('Surname ASC')->map('ID', 'getName'))
            ];

            if ($bIsFirstLoad) {
                TaskUtil::echoFormHTML($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed, $aCustomComponents);

                return Command::SUCCESS;
            }

            // messages that follow each other are shown together; echoSpace() starts a new group
            TaskUtil::echoSpace();

            if ($bIsDryRun) {
                TaskUtil::echoNotice('Dry run: nothing has been changed yet.');
            }

            /** @var Member $oMember */
            $oMember = Member::get()->byID((int)$sMemberID);

            if ($oMember?->exists()) {
                if ($bIsDryRun) {
                    TaskUtil::echoNormal('The first name of ' . Convert::raw2xml($oMember->getName()) . ' (#' . (int)$oMember->ID . ') would change from <b>' . Convert::raw2xml($oMember->FirstName) . '</b> to <b>' . Convert::raw2xml($sFirstName) . '</b>.');
                }
                else {
                    TaskUtil::echoGood('Changed the first name of ' . Convert::raw2xml($oMember->getName()) . ' (#' . (int)$oMember->ID . ') from ' . Convert::raw2xml($oMember->FirstName) . ' to ' . Convert::raw2xml($sFirstName) . '.');

                    $oMember->FirstName = $sFirstName;
                    $oMember->write();

                    // let's demonstrate a helper function to show arrays as tables
                    $aDataToShowAsTable = [];
                    $dlMembers          = Member::get()->sort('Surname ASC');

                    foreach ($dlMembers as $oMemberInList) {
                        $aDataToShowAsTable[] = [
                            'ID'         => $oMemberInList->ID,
                            'First name' => Convert::raw2xml($oMemberInList->FirstName),
                            'Surname'    => Convert::raw2xml($oMemberInList->Surname),
                            'Admin'      => Permission::check('ADMIN', 'any', $oMemberInList),
                        ];
                    }

                    TaskUtil::echoHeading('All members');
                    TaskUtil::echoArrayAsTable($aDataToShowAsTable, true);
                    TaskUtil::echoResetFormHTML();

                    // let's celebrate the completion of the task!
                    TaskUtil::addConfetti();
                }
            }
            else {
                TaskUtil::echoError('Member #' . (int)$sMemberID . ' could not be found.');
            }

            if ($bIsDryRun) {
                TaskUtil::echoFormHTML($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed, $aCustomComponents);
            }

            return Command::SUCCESS;
        }

        /**
         * This class implements TaskCategoryProvider to illustrate
         * how to add extra categories for the dev/tasks/ list.
         * @return array[]
         */
        public function provideTaskCategory(): array
        {
            return [
                static::TASK_CAT_DEMO => [
                    'name' => 'Demonstrations',
                    'sort' => 30
                ]
            ];
        }
    }
