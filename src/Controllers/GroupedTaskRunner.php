<?php

    namespace Hamaka\TaskForms\Controllers;

    use Hamaka\TaskForms\TaskCategoryProvider;
    use SilverStripe\Control\Director;
    use SilverStripe\Core\ClassInfo;
    use SilverStripe\Core\Config\Config;
    use SilverStripe\Core\Convert;
    use SilverStripe\Dev\BuildTask;
    use SilverStripe\Dev\DebugView;
    use SilverStripe\Dev\TaskRunner;
    use SilverStripe\Dev\TestOnly;
    use function _t;
    use function in_array;
    use function is_array;
    use function singleton;
    use function sizeof;
    use function str_replace;
    use function strcmp;
    use function uasort;
    use function user_error;
    use const E_USER_WARNING;

    /**
     * Class \GroupedTaskRunner
     *
     */
    class GroupedTaskRunner extends TaskRunner
    {
        const TASK_CAT_PRIORITY = 'TASK_CAT_PRIORITY';
        const TASK_CAT_STATS_AND_REPORTS = 'TASK_CAT_STATS_AND_REPORTS';
        const TASK_CAT_MAINTENANCE = 'TASK_CAT_MAINTENANCE';
        const TASK_CAT_TESTS = 'TASK_CAT_TESTS';
        const TASK_CAT_OTHER = 'TASK_CAT_OTHER';
        const TASK_CAT_HIDDEN = 'TASK_CAT_HIDDEN';

        private static $url_handlers = [
            ''                => 'index',
            '$TaskName'       => 'runTask',
            'queue/$TaskName' => 'queueTask',
        ];

        private static $allowed_actions = [
            'index',
            'runTask',
            'queueTask',
        ];

        protected function init()
        {
            parent::init();
        }

        public function index()
        {
            $aTasks = $this->getTasks();

            // Web mode
            if ( ! Director::is_cli()) {
                $renderer = new DebugView();
                echo $renderer->renderHeader();
                echo $renderer->renderInfo("Development Tools: Tasks", Director::absoluteBaseURL());
                $base = Director::absoluteBaseURL();

                $aTaskCatOrder = $this->getCategories();

                echo('<br>');

                foreach ($aTaskCatOrder as $sCategoryCode => $aCategoryConfig) {

                    $aTasksInCat = [];
                    foreach ($aTasks as $aTaskConfig) {
                        if ($aTaskConfig['category'] === $sCategoryCode) {
                            $aTasksInCat[] = $aTaskConfig;
                        }
                    }

                    if (sizeof($aTasksInCat) > 0) {
                        echo "<h2 style='margin-bottom: 0;'>&nbsp;&nbsp;" . $aCategoryConfig['name'] . "</h2>";
                        echo "<div class=\"options\" style='padding-top: 0;'>";
                        echo "<ul>";

                        foreach ($aTasksInCat as $aTaskConfig) {

                            $sLinkImmediate = $base . "dev/tasks/" . $aTaskConfig['segment'];

                            echo "<li><p>";
                            echo "<a href=\"{$sLinkImmediate}\">" . $aTaskConfig['title'] . "</a>";

                            echo "<br /><span class=\"description\">" . $aTaskConfig['description'] . "</span>";
                            echo "</p></li>\n";
                        }

                        echo "</ul></div>";
                    }
                }

                echo $renderer->renderFooter();
                // CLI mode
            }
            else {
                echo "SILVERSTRIPE DEVELOPMENT TOOLS: Tasks\n--------------------------\n\n";
                foreach ($aTasks as $aTaskConfig) {
                    echo " * $aTaskConfig[title]: sake dev/tasks/" . $aTaskConfig['segment'] . "\n";
                }
            }
        }

        public function getCategories()
        {
            $aAllCategories = [];

            $oConfig = Config::forClass(TaskRunner::class);

            /**
             * YML:
             * SilverStripe\Dev\TaskRunner:
             *  removed_categories:
             *      - 'TASK_CAT_OTHER'
             */
            $aRemovedCategories = (array)$oConfig->get('removed_categories');

            if ( ! in_array(static::TASK_CAT_PRIORITY, $aRemovedCategories)) {
                $aAllCategories[static::TASK_CAT_PRIORITY] = [
                    'name' => _t('Hamaka\\TaskForms\\Controllers\\GroupedTaskRunner.CAT_PRIORITY_LABEL', "Priority"),
                    'sort' => 100,
                ];
            }

            if ( ! in_array(static::TASK_CAT_STATS_AND_REPORTS, $aRemovedCategories)) {
                $aAllCategories[static::TASK_CAT_STATS_AND_REPORTS] = [
                    'name' => _t('Hamaka\\TaskForms\\Controllers\\GroupedTaskRunner.CAT_STATS_AND_REPORTS_LABEL', "Statistics and Reports"),
                    'sort' => 90,
                ];
            }

            if ( ! in_array(static::TASK_CAT_MAINTENANCE, $aRemovedCategories)) {
                $aAllCategories[static::TASK_CAT_MAINTENANCE] = [
                    'name' => _t('Hamaka\\TaskForms\\Controllers\\GroupedTaskRunner.CAT_MAINTENANCE_LABEL', "Maintenance"),
                    'sort' => 70,
                ];
            }

            if ( ! in_array(static::TASK_CAT_TESTS, $aRemovedCategories)) {
                $aAllCategories[static::TASK_CAT_TESTS] = [
                    'name' => _t('Hamaka\\TaskForms\\Controllers\\GroupedTaskRunner.CAT_TESTS_LABEL', "Tests"),
                    'sort' => 50,
                ];
            }

            if ( ! in_array(static::TASK_CAT_OTHER, $aRemovedCategories)) {
                $aAllCategories[static::TASK_CAT_OTHER] = [
                    'name' => _t('Hamaka\\TaskForms\\Controllers\\GroupedTaskRunner.A', "Misc"),
                    'sort' => 10,
                ];
            }

            $aClasses = ClassInfo::implementorsOf(TaskCategoryProvider::class);

            if ($aClasses) {
                foreach ($aClasses as $sClass) {

                    $SNG = singleton($sClass);

                    if ($SNG instanceof TestOnly) {
                        continue;
                    }

                    $aSomeCategories = $SNG->provideTaskCategory();

                    if ($aSomeCategories) {
                        foreach ($aSomeCategories as $sCategoryCode => $aCategoryConfig) {
                            if (is_array($aCategoryConfig)) {
                                // There must be a name and optional a sort key.
                                if ( ! isset($aCategoryConfig['name'])) {
                                    user_error(
                                        "The category $sCategoryCode must have a name key",
                                        E_USER_WARNING
                                    );
                                }

                                $aAllCategories[$sCategoryCode] = [
                                    'name' => $aCategoryConfig['name'],
                                    'sort' => isset($aCategoryConfig['sort']) ? $aCategoryConfig['sort'] : 0
                                ];
                            }
                            else {
                                $aAllCategories[$sCategoryCode] = [
                                    'name' => $aCategoryConfig,
                                    'sort' => 0
                                ];
                            }
                        }
                    }
                }
            }

            uasort($aAllCategories, [__CLASS__, 'sortCategories']);

            //            $aResponse = [];
            //            foreach ($aAllCategories as $sCategoryCode => $permissions) {
            //                $aResponse = array_merge($aResponse, $permissions);
            //            }

            $this->extend('updateCategories', $aAllCategories);

            return $aAllCategories;
        }

        /**
         * Sort categories based on their sort value, or name
         *
         * @param array $a
         * @param array $b
         *
         * @return int
         */
        public static function sortCategories($a, $b)
        {
            if ($a['sort'] == $b['sort']) {
                // Same sort value, do alpha instead
                return strcmp($a['name'], $b['name']);
            }
            else {
                // Just numeric.
                return $a['sort'] > $b['sort'] ? -1 : 1;
            }
        }

        /**
         * @return array Array of associative arrays for each task (Keys: 'class', 'title', 'description')
         */
        protected function getTasks()
        {
            $aAvailableTasks = [];
            $aTaskClasses    = ClassInfo::subclassesFor(BuildTask::class);

            // remove the base class
            array_shift($aTaskClasses);

            foreach ($aTaskClasses as $sClass) {
                if ( ! $this->taskEnabled($sClass)) {
                    continue;
                }

                $oSingleton = BuildTask::singleton($sClass);

                $sCat = static::TASK_CAT_OTHER;

                $sCatTemp = '';
                if (property_exists($oSingleton, 'category')) {
                    $sCatTemp = $oSingleton::$category;
                }

                //                $oConfig = Config::forClass(str_replace('\\', '-', $sClass));
                //                var_dump($oConfig->get('url_segment'));
                //                $sCatTemp = $oConfig->get('url_segment');

                if (trim($sCatTemp) !== "") {
                    $sCat = $sCatTemp;
                }

                $desc = (Director::is_cli())
                    ? Convert::html2raw($oSingleton->getDescription())
                    : $oSingleton->getDescription();

                $aAvailableTasks[] = [
                    'class'       => $sClass,
                    'title'       => $oSingleton->getTitle(),
                    'segment'     => $oSingleton->config()->segment ?: str_replace('\\', '-', $sClass),
                    'description' => $desc,
                    'category'    => $sCat
                ];
            }

            return $aAvailableTasks;
        }
    }
