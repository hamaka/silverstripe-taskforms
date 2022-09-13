# Silverstripe Taskforms

Utility to make Silverstripe tasks more interactive and better looking.

Silverstripe 4+

## Installation
```
composer require "hamaka/silverstripe-taskforms"
```

## Features
 * Adds some basic CSS to task output.
 * Adds utility methods to give colour to alteration messages (echoGood, echoNotice, echoError etc).
 * Adds a pattern to generate a simple form to populate variable input (an array of variables is turned into a form).
 * Adds a way to block execution when not all required input is present.
 * The default textfields in the form can be overruled with more complex Silverstripe FormFields.
 * Adds a pattern to be able to force a dry run of the task before execution.
 * Adds utility method to dump array output as a table.
 * Overrides the default Silverstripe TaskRunner to allow /dev/tasks to be organized in categories. Categories can be added with TaskCategoryProviders in the same way Silverstripe PermissionProvider works.

## Usage
See ExampleTask.php

## Screenshots
![](/docs/images/screen_tasks_categories.jpg?raw=true "/dev/tasks can be organized with categories")

![](/docs/images/screen_task_simple_form.jpg?raw=true "Add a pattern to generate a simple form to populate variable input (an array of variables is turned into a form)")

![](/docs/images/screen_task_dry_run.jpg?raw=true "Add a pattern to be able to force a dry run of the task before execution")

![](/docs/images/screen_task_table.jpg?raw=true "Adds utility method to dump array output as a table")

PS. Who doesn't like confetti to celebrate a job well done?

## License
See [License](license.md)

## Maintainers
 * Sander van Scheepen <sander@hamaka.nl>
 * Carlo Riedstra <carlo@hamaka.nl>
 * Bauke Zwaan <bauke@hamaka.nl>
