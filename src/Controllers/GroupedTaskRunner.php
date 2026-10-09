<?php

    namespace Hamaka\TaskForms\Controllers;

    use Hamaka\TaskForms\TaskCategoryProvider;
    use SilverStripe\Control\Controller;
    use SilverStripe\Control\Director;
    use SilverStripe\Core\ClassInfo;
    use SilverStripe\Core\Config\Config;
    use SilverStripe\Dev\DebugView;
    use SilverStripe\Dev\TaskRunner;
    use SilverStripe\Dev\TestOnly;
    use SilverStripe\Model\ArrayData;
    use SilverStripe\Model\List\ArrayList;
    use SilverStripe\Model\ModelData;
    use SilverStripe\ORM\FieldType\DBHTMLText;
    use function _t;
    use function in_array;
    use function is_array;
    use function is_string;
    use function property_exists;
    use function singleton;
    use function strcmp;
    use function trigger_error;
    use function trim;
    use function uasort;
    use const E_USER_WARNING;

    /**
     * Class \Hamaka\TaskForms\Controllers\GroupedTaskRunner
     *
     * Replaces the core TaskRunner (through the Injector) to show the tasks on /dev/tasks grouped in categories.
     * A task picks its category with a public static $category property. Categories are the built-in TASK_CAT_*
     * categories plus the ones provided by implementors of TaskCategoryProvider. Running a task is left to the core
     * TaskRunner.
     */
    class GroupedTaskRunner extends TaskRunner
    {
        public const TASK_CAT_PRIORITY = 'TASK_CAT_PRIORITY';
        public const TASK_CAT_STATS_AND_REPORTS = 'TASK_CAT_STATS_AND_REPORTS';
        public const TASK_CAT_MAINTENANCE = 'TASK_CAT_MAINTENANCE';
        public const TASK_CAT_TESTS = 'TASK_CAT_TESTS';
        public const TASK_CAT_OTHER = 'TASK_CAT_OTHER';
        // not registered as a category, so tasks in it are not listed (but can still be run through their URL)
        public const TASK_CAT_HIDDEN = 'TASK_CAT_HIDDEN';

        /**
         * Renders the /dev/tasks overview: every task that may run in the browser, grouped under the categories from
         * getCategories() in their sort order. Tasks whose category is not registered (e.g. TASK_CAT_HIDDEN) are not
         * listed, but can still be run through their URL.
         */
        public function index(): DBHTMLText
        {
            $sBaseUrl = Director::absoluteBaseURL();
            $aTasks   = $this->getTasks();

            $alCategories = ArrayList::create();
            foreach ($this->getCategories() as $sCategoryCode => $aCategoryConfig) {
                $alTasksInCat = ArrayList::create();
                foreach ($aTasks as $aTaskConfig) {
                    if ($aTaskConfig['category'] === $sCategoryCode) {
                        $alTasksInCat->push(ArrayData::create([
                            'TaskLink'    => Controller::join_links($sBaseUrl, 'dev/tasks/', $aTaskConfig['segment']),
                            'Title'       => $aTaskConfig['title'],
                            'Description' => $aTaskConfig['description'],
                            'Parameters'  => $aTaskConfig['parameters'],
                            'Help'        => $aTaskConfig['help'],
                        ]));
                    }
                }

                if ($alTasksInCat->count() > 0) {
                    $alCategories->push(ArrayData::create([
                        'Title' => $aCategoryConfig['name'],
                        'Tasks' => $alTasksInCat,
                    ]));
                }
            }

            $oDebugView = DebugView::create();

            $aData = [
                'Categories' => $alCategories,
                'Header'     => $this->addCssToHeader($oDebugView->renderHeader()),
                'Footer'     => $oDebugView->renderFooter(),
                'Info'       => $oDebugView->renderInfo('Development Tools: Tasks', $sBaseUrl),
            ];

            return ModelData::create()->renderWith(static::class, $aData);
        }

        /**
         * Returns all categories, sorted from high to low 'sort' value (and by name for equal values):
         * the built-in TASK_CAT_* categories minus the ones in the removed_categories config, plus the categories of
         * every TaskCategoryProvider. Extensions can change the result with updateCategories().
         *
         * Remove built-in categories in YML:
         * SilverStripe\Dev\TaskRunner:
         *   removed_categories:
         *     - 'TASK_CAT_OTHER'
         *
         * @return array Map of category codes to ['name' => string, 'sort' => int]
         */
        public function getCategories(): array
        {
            $aAllCategories     = [];
            $aRemovedCategories = (array)Config::forClass(TaskRunner::class)->get('removed_categories');

            // category code => [name, sort]
            $aBuiltInCategories = [
                static::TASK_CAT_PRIORITY          => [_t(__CLASS__ . '.CAT_PRIORITY_LABEL', 'Priority'), 100],
                static::TASK_CAT_STATS_AND_REPORTS => [_t(__CLASS__ . '.CAT_STATS_AND_REPORTS_LABEL', 'Statistics and Reports'), 90],
                static::TASK_CAT_MAINTENANCE       => [_t(__CLASS__ . '.CAT_MAINTENANCE_LABEL', 'Maintenance'), 70],
                static::TASK_CAT_TESTS             => [_t(__CLASS__ . '.CAT_TESTS_LABEL', 'Tests'), 50],
                static::TASK_CAT_OTHER             => [_t(__CLASS__ . '.CAT_OTHER_LABEL', 'Misc'), 10],
            ];

            foreach ($aBuiltInCategories as $sCategoryCode => [$sName, $iSort]) {
                if ( ! in_array($sCategoryCode, $aRemovedCategories)) {
                    $aAllCategories[$sCategoryCode] = [
                        'name' => $sName,
                        'sort' => $iSort,
                    ];
                }
            }

            foreach (ClassInfo::implementorsOf(TaskCategoryProvider::class) as $sProviderClass) {
                $oCategoryProvider = singleton($sProviderClass);

                if ($oCategoryProvider instanceof TestOnly) {
                    continue;
                }

                $aCategoriesFromProvider = $oCategoryProvider->provideTaskCategory();

                if ( ! $aCategoriesFromProvider) {
                    continue;
                }

                foreach ($aCategoriesFromProvider as $sCategoryCode => $mCategoryConfig) {
                    $aAllCategories[$sCategoryCode] = static::getCategoryConfigNormalized($sCategoryCode, $mCategoryConfig);
                }
            }

            uasort($aAllCategories, [__CLASS__, 'sortCategories']);

            $this->extend('updateCategories', $aAllCategories);

            return $aAllCategories;
        }

        /**
         * Adds the category to the tasks the core TaskRunner makes available in the browser.
         *
         * @return array Array of associative arrays for each task (Keys: 'class', 'title', 'segment', 'description',
         *               'parameters', 'help', 'category')
         */
        protected function getTasks(): array
        {
            $aAvailableTasks = parent::getTasks();

            foreach ($aAvailableTasks as &$aTaskConfig) {
                $aTaskConfig['category'] = static::getTaskCategory($aTaskConfig['class']);
            }

            return $aAvailableTasks;
        }

        /**
         * Comparison callback for uasort() that orders categories from high to low 'sort' value, and alphabetically
         * by name when the sort values are equal. The name is kept for backwards compatibility.
         *
         * @param array $aCategoryA ['name' => string, 'sort' => int]
         * @param array $aCategoryB ['name' => string, 'sort' => int]
         */
        public static function sortCategories(array $aCategoryA, array $aCategoryB): int
        {
            if ($aCategoryA['sort'] == $aCategoryB['sort']) {
                return strcmp($aCategoryA['name'], $aCategoryB['name']);
            }

            return $aCategoryA['sort'] > $aCategoryB['sort'] ? -1 : 1;
        }

        /**
         * Returns the category code of a task class. A task is put in a category by giving it a public static
         * $category property with one of the TASK_CAT_* constants or a code provided by a TaskCategoryProvider.
         * Tasks without (a non-empty) $category end up in TASK_CAT_OTHER.
         *
         * @param string $sClass FQCN of a BuildTask
         */
        public static function getTaskCategory(string $sClass): string
        {
            if (property_exists($sClass, 'category') && is_string($sClass::$category) && trim($sClass::$category) !== '') {
                return $sClass::$category;
            }

            return static::TASK_CAT_OTHER;
        }

        /**
         * Returns a category as provided by a TaskCategoryProvider in the form ['name' => string, 'sort' => int].
         * A provider can give just the name, or an array with a 'name' and an optional 'sort' (default 0).
         * Triggers a warning when the array has no name.
         *
         * @param mixed $mCategoryConfig The name, or ['name' => string, 'sort' => int]
         */
        protected static function getCategoryConfigNormalized(string $sCategoryCode, mixed $mCategoryConfig): array
        {
            if ( ! is_array($mCategoryConfig)) {
                return [
                    'name' => $mCategoryConfig,
                    'sort' => 0,
                ];
            }

            if ( ! isset($mCategoryConfig['name'])) {
                trigger_error("The category $sCategoryCode must have a name key", E_USER_WARNING);
            }

            return [
                'name' => $mCategoryConfig['name'],
                'sort' => $mCategoryConfig['sort'] ?? 0,
            ];
        }
    }
