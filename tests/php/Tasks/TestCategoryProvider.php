<?php

    namespace Hamaka\TaskForms\Tests\Tasks;

    use Hamaka\TaskForms\TaskCategoryProvider;

    /**
     * Class \Hamaka\TaskForms\Tests\Tasks\TestCategoryProvider
     *
     * Adds two categories: one with a name and sort value, one with only a name.
     * Not TestOnly, because GroupedTaskRunner skips TestOnly providers. Silverstripe only loads classes in tests/
     * folders while running tests, so it does not show up on sites.
     */
    class TestCategoryProvider implements TaskCategoryProvider
    {
        public const TASK_CAT_TEST_PROVIDED = 'TASK_CAT_TEST_PROVIDED';
        public const TASK_CAT_TEST_NAME_ONLY = 'TASK_CAT_TEST_NAME_ONLY';

        public function provideTaskCategory(): array
        {
            return [
                static::TASK_CAT_TEST_PROVIDED  => ['name' => 'Provided by test', 'sort' => 80],
                static::TASK_CAT_TEST_NAME_ONLY => 'Only a name',
            ];
        }
    }
