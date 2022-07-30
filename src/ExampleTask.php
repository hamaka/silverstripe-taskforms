<?php

    namespace Hamaka\TaskForms;

    use Hamaka\TaskForms\Utils\TaskUtil;
    use SilverStripe\Dev\BuildTask;
    use SilverStripe\Forms\DropdownField;
    use SilverStripe\ORM\DB;
    use SilverStripe\Security\Member;
    use SilverStripe\Security\Permission;

    class ExampleTask extends BuildTask implements TaskCategoryProvider
    {
        // Have a look at dev/tasks to see the categories.
        // The function provideTaskCategory at the bottom adds this new category to the ones
        // already in GroupedTaskRunner like GroupedTaskRunner::TASK_CAT_MAINTENANCE
        public static $category = 'DEMO';

        private static $segment = 'taskformsexample';
        protected $title = 'TaskForms ExampleTask';
        protected $description = 'Demonstration of the features of taskforms module';
        protected $enabled = true;

        public function run($request = null)
        {
            // this adds CSS to the task
            TaskUtil::makePretty();
            
            // echoNormal, echoGood, echoNotice, echoError and echoCommentOnPrev are shorthands
            // for styled messages like DB::alteration_message('Something is wrong', TaskUtil::ALT_MESSAGE_TYPE_ERROR);
            TaskUtil::echoNormal("Start task " . $this->title);
            TaskUtil::echoNormal('This task is in itself not very useful but is meant to demonstrate how to set up an easy form in a task. Have a look at the source code.');

            // here we set up some vars to load the form input in
            $iMemberID  = null;
            $sFirstName = null;

            // keys are the field labels, values are the references to the vars
            // the & is important.
            $aGetKeyToVarMap = [
                'MemberID'      => &$iMemberID,
                'New_FirstName' => &$sFirstName
            ];

            // which fields are required to execute the task?
            // has to be at least 1
            $aRequiredFieldsNamesAndVars = [
                'MemberID',
                'New FirstName'
            ];

            $bDoesActionNeedToBePreviewed = true;
            // if $bDoesActionNeedToBePreviewed === true
            //   > the first run after filling in the form is marked as 'dry run' - a confirmation field is added to the form
            // if $bDoesActionNeedToBePreviewed !== true
            //   > the first run after filling in the form is marked as 'live'
            // It is up to you how you use dry run / live run in your task. See below for an example.

            $bIsFirstLoad = TaskUtil::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars);
            $bIsDryRun    = TaskUtil::isDryRun($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed);

            // By default a textfield will be scaffolded for every var.
            // It is possible to pass an array with Silverstripe fields to echoFormHTML to build complex forms
            $aCustomComponents = [
                'MemberID' => DropdownField::create('MemberID', 'Select a Member', Member::get()->sort('Surname ASC')->map('ID', 'getName'))
            ];

            if ($bIsFirstLoad) {
                TaskUtil::echoSpace();
                TaskUtil::echoFormHTML($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed, $aCustomComponents);
                DB::alteration_message("End task " . $this->title);
                exit;
            }

            if ($bIsDryRun) {
                TaskUtil::echoSpace();
                TaskUtil::echoNotice('This is a dry run. No changes will be made. To execute this action, type a 1 in the form.');
                TaskUtil::echoSpace();
            }
            else {
                TaskUtil::echoSpace();
                TaskUtil::echoGood('This is a LIVE run.');
                TaskUtil::echoSpace();
            }

            /** @var Member $oMember */
            $oMember = Member::get()->byID(intval($iMemberID));

            if ($oMember) {
                if ($bIsDryRun) {
                    TaskUtil::echoNormal('The selected Member (' . $iMemberID . ' ' . $oMember->getName() . ') would change from ' . $oMember->FirstName . ' to ' . $sFirstName);
                }
                else {
                    TaskUtil::echoGood('Process Member record ' . $iMemberID . '. Change FirstName from ' . $oMember->FirstName . ' to ' . $sFirstName);

                    $oMember->FirstName = $sFirstName;
                    $oMember->write();

                    // let's demonstrate a helper function to show arrays as tables
                    $aDataToShowAsTable = [];
                    $dlMembers          = Member::get()->sort('Surname ASC');

                    foreach ($dlMembers as $oMember) {
                        $aDataToShowAsTable[] = [
                            'ID'         => $oMember->ID,
                            'First name' => $oMember->FirstName,
                            'Surname'    => $oMember->Surname,
                            'Is ADMIN'   => Permission::check('ADMIN', 'any', $oMember),
                        ];
                    }

                    TaskUtil::echoSeparator();
                    TaskUtil::echoHeading('Overview of all members');
                    TaskUtil::echoArrayAsTable($aDataToShowAsTable, true);
                    TaskUtil::echoSpace();
                    TaskUtil::echoResetFormHTML();

                    // let's celebrate the completion of the task!
                    TaskUtil::addConfetti();
                }
            }
            else {
                TaskUtil::echoError('Member could not be found. Did you alter the MemberID in the URL?');
            }

            if ($bIsDryRun) {
                TaskUtil::echoSpace();
                TaskUtil::echoFormHTML($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed, $aCustomComponents);
            }

            DB::alteration_message("End task " . $this->title);
        }

        /**
         * This class implements TaskCategoryProvider to illustrate
         * how to add extra categories for the dev/tasks/ list.
         * @return array[]
         */
        public function provideTaskCategory()
        {
            return [
                "DEMO" => [
                    'name' => 'Demonstrations',
                    'sort' => 30
                ]
            ];
        }
    }
