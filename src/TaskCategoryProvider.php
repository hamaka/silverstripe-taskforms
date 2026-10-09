<?php

    namespace Hamaka\TaskForms;

    /**
     * Interface \Hamaka\TaskForms\TaskCategoryProvider
     *
     * Lets classes add categories to the /dev/tasks overview, in the same way PermissionProvider adds permissions.
     * GroupedTaskRunner calls provideTaskCategory() on every implementor to get the full list of categories.
     * A task is put in a category with a public static $category property that holds the category code.
     */
    interface TaskCategoryProvider
    {

        /**
         * Returns a map of category codes to their name, or to an array with a name and an optional sort value.
         * Categories are shown from high to low sort value (default 0). The built-in categories use 100 (Priority)
         * down to 10 (Misc).
         *
         * [
         *   'TASK_CAT_REPORTS'   => 'Management reports',
         *   'TASK_CAT_IMPORTERS' => ['name' => 'Importers', 'sort' => 80],
         * ];
         *
         * No return type, to match PermissionProvider::providePermissions() and to keep existing implementors working.
         *
         * @return array
         */
        public function provideTaskCategory();
    }
