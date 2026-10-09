<?php

    namespace Hamaka\TaskForms\Tests\Controllers;

    use Hamaka\TaskForms\Controllers\GroupedTaskRunner;
    use Hamaka\TaskForms\ExampleTask;
    use Hamaka\TaskForms\Tests\Tasks\TestCategoryProvider;
    use Hamaka\TaskForms\Tests\Tasks\TestFormTask;
    use Hamaka\TaskForms\Tests\Tasks\TestHiddenTask;
    use Hamaka\TaskForms\Tests\Tasks\TestProvidedCategoryTask;
    use ReflectionProperty;
    use SilverStripe\Control\HTTPResponse;
    use SilverStripe\Core\Config\Config;
    use SilverStripe\Core\Injector\Injector;
    use SilverStripe\Core\Kernel;
    use SilverStripe\Dev\DevelopmentAdmin;
    use SilverStripe\Dev\FunctionalTest;
    use SilverStripe\Dev\TaskRunner;

    /**
     * Class \Hamaka\TaskForms\Tests\Controllers\GroupedTaskRunnerTest
     *
     * Tests the /dev/tasks overview with categories, permissions, and the form → dry run → live run flow of a task
     * in the browser.
     */
    class GroupedTaskRunnerTest extends FunctionalTest
    {
        protected $usesDatabase = true;

        protected string $sEnvironmentBackup = '';

        protected function setUp(): void
        {
            parent::setUp();

            $this->sEnvironmentBackup = Injector::inst()->get(Kernel::class)->getEnvironment();
        }

        protected function tearDown(): void
        {
            Injector::inst()->get(Kernel::class)->setEnvironment($this->sEnvironmentBackup);

            parent::tearDown();
        }

        public function testTaskRunnerIsReplacedByGroupedTaskRunner(): void
        {
            $this->assertInstanceOf(GroupedTaskRunner::class, Injector::inst()->get(TaskRunner::class));
        }

        public function testCategoriesAreSortedAndIncludeProvidedOnes(): void
        {
            $aCategories = GroupedTaskRunner::create()->getCategories();

            $this->assertSame(['name' => 'Provided by test', 'sort' => 80], $aCategories[TestCategoryProvider::TASK_CAT_TEST_PROVIDED]);
            $this->assertSame(['name' => 'Only a name', 'sort' => 0], $aCategories[TestCategoryProvider::TASK_CAT_TEST_NAME_ONLY]);
            $this->assertArrayNotHasKey(GroupedTaskRunner::TASK_CAT_HIDDEN, $aCategories);

            $aSortValues = array_column($aCategories, 'sort');
            $aSortValuesSorted = $aSortValues;
            rsort($aSortValuesSorted);
            $this->assertSame($aSortValuesSorted, $aSortValues, 'Categories are sorted from high to low sort value');
        }

        public function testRemovedCategoriesAreLeftOut(): void
        {
            Config::modify()->set(TaskRunner::class, 'removed_categories', [GroupedTaskRunner::TASK_CAT_MAINTENANCE]);

            $aCategories = GroupedTaskRunner::create()->getCategories();

            $this->assertArrayNotHasKey(GroupedTaskRunner::TASK_CAT_MAINTENANCE, $aCategories);
            $this->assertArrayHasKey(GroupedTaskRunner::TASK_CAT_OTHER, $aCategories);
        }

        public function testTaskCategoryDefaultsToOther(): void
        {
            $this->assertSame(GroupedTaskRunner::TASK_CAT_MAINTENANCE, GroupedTaskRunner::getTaskCategory(TestFormTask::class));
            $this->assertSame(GroupedTaskRunner::TASK_CAT_OTHER, GroupedTaskRunner::getTaskCategory(TaskRunner::class));
        }

        public function testOverviewListsTasksUnderTheirCategory(): void
        {
            $sBody = $this->getBody('dev/tasks');

            $iPosProvided     = strpos($sBody, '>Provided by test<');
            $iPosProvidedTask = strpos($sBody, 'Taskforms test provided category task');
            $iPosMaintenance  = strpos($sBody, '>Maintenance<');
            $iPosFormTask     = strpos($sBody, 'Taskforms test form task');

            $this->assertNotFalse($iPosProvided);
            $this->assertNotFalse($iPosMaintenance);
            $this->assertTrue($iPosProvided < $iPosProvidedTask && $iPosProvidedTask < $iPosMaintenance, 'Provided category (80) comes before Maintenance (70), with its task');
            $this->assertTrue($iPosMaintenance < $iPosFormTask, 'The form task is listed under Maintenance');
            $this->assertStringContainsString('dev/tasks/taskformstestform', $sBody);
        }

        public function testHiddenTaskIsNotListedButCanRun(): void
        {
            $this->assertStringNotContainsString('Taskforms test hidden task', $this->getBody('dev/tasks'));
            $this->assertStringContainsString('HIDDEN TASK RAN', $this->getBody('dev/tasks/taskformstesthidden'));
        }

        public function testTasksNeedPermissionOutsideDevMode(): void
        {
            Injector::inst()->get(Kernel::class)->setEnvironment(Kernel::LIVE);
            // PHPUnit runs on the command line, where tasks are always allowed unless allow_all_cli is off
            Config::modify()->set(DevelopmentAdmin::class, 'allow_all_cli', false);

            $this->logOut();
            $this->autoFollowRedirection = false;
            $oResponse = $this->get('dev/tasks');
            $this->autoFollowRedirection = true;
            $this->assertSame(302, $oResponse->getStatusCode());
            $this->assertStringContainsString('Security/login', $oResponse->getHeader('Location'));

            $this->assertFalse(GroupedTaskRunner::create()->canInit());

            // logged-in users get core's confirmation step for dev URLs in live mode, so check access directly
            $this->logInWithPermission('ADMIN');
            $this->assertTrue(GroupedTaskRunner::create()->canInit());
        }

        public function testFormFlowFromFirstLoadToLiveRun(): void
        {
            $sFirstLoad = $this->getBody('dev/tasks/taskformstestform');
            $this->assertStringContainsString('name="' . TestFormTask::FIELD_NAME . '"', $sFirstLoad);
            $this->assertStringContainsString('>Preview</button>', $sFirstLoad);
            $this->assertStringNotContainsString('DRY RUN', $sFirstLoad);

            $sDryRun = $this->getBody('dev/tasks/taskformstestform?Name=Ada');
            $this->assertStringContainsString('DRY RUN for Ada', $sDryRun);
            $this->assertStringContainsString('type="checkbox" name="execute" value="1"', $sDryRun);
            $this->assertStringNotContainsString('LIVE RUN', $sDryRun);

            $sLiveRun = $this->getBody('dev/tasks/taskformstestform?Name=Ada&execute=1');
            $this->assertStringContainsString('LIVE RUN for Ada', $sLiveRun);
            $this->assertStringNotContainsString('<form', $sLiveRun);
        }

        public function testExampleTaskIsDisabledByDefault(): void
        {
            $this->assertFalse((new ReflectionProperty(ExampleTask::class, 'is_enabled'))->getDefaultValue());
        }

        /**
         * Returns the body of a GET request, including the output that a task writes directly (tasks stream their
         * output instead of returning it in the response).
         */
        protected function getBody(string $sUrl): string
        {
            ob_start();
            /** @var HTTPResponse $oResponse */
            $oResponse = $this->get($sUrl);
            $sStreamed = (string)ob_get_clean();

            return $sStreamed . $oResponse->getBody();
        }
    }
