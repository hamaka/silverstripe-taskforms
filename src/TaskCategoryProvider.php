<?php

    namespace Hamaka\TaskForms;

    /**
     * Used to let classes provide new task categories.
     * Every implementor of TaskCategoryProvider is accessed and provideTaskCategory() called to get the full list of
     * categories.
     */
    interface TaskCategoryProvider
    {

        /**
         * Return a category codes and their label..
         * array(
         *   'UNIQUE_CODE' => 'Management Reports',
         * );
         */
        public function provideTaskCategory();
    }
