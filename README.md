# Silverstripe Taskforms

Utility to make Silverstripe tasks more interactive and better looking.

## Requirements

| Module version | Silverstripe | PHP    |
|----------------|--------------|--------|
| 2.x            | 6            | ^8.3   |
| 1.x            | 4 and 5      | >=7.1  |

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
[ExampleTask.php](src/ExampleTask.php) shows all features in one task. Read its source as the reference for writing
your own task forms.

### Enabling the example task
`ExampleTask` is **disabled by default**, so it does not appear on every site that installs this module. It changes
the first name of a member, so only enable it on a development or test site.

1. Create a config file in your project, e.g. `app/_config/taskforms-example.yml`:

   ```yaml
   ---
   Name: taskforms-example
   ---
   Hamaka\TaskForms\ExampleTask:
     is_enabled: true
   ```

2. Flush the config cache: open any page with `?flush=1`, or run `vendor/bin/sake flush`.
3. Open `/dev/tasks`. The task is listed as "TaskForms ExampleTask" under "Demonstrations". Run it from there, open
   `/dev/tasks/taskformsexample` directly, or run it on the command line:

   ```
   vendor/bin/sake tasks:taskformsexample --MemberID=1 --New_FirstName=Ada
   ```

To disable it again, remove the config file (or set `is_enabled: false`) and flush.

### Running a task form on the command line
In Silverstripe 6, `sake` only accepts options that a task declares. To make the fields of a task form work on the
command line too, declare them in `getOptions()` and pass the input to `TaskUtil` at the start of `execute()`:

```php
public function getOptions(): array
{
    // the keys of your $aGetKeyToVarMap; true adds the --execute option for the dry run confirmation
    return TaskUtil::getInputOptionsForFields(['MemberID', 'New_FirstName'], true);
}

protected function execute(InputInterface $input, PolyOutput $output): int
{
    TaskUtil::setInput($input);
    // ...
}
```

```
vendor/bin/sake tasks:taskformsexample --MemberID=3 --New_FirstName=Grace             # dry run
vendor/bin/sake tasks:taskformsexample --MemberID=3 --New_FirstName=Grace --execute=1 # live run
```

The options are also listed with the task on `/dev/tasks`. In the browser the form works the same with or without
them.

## Upgrading from 1.x (Silverstripe 6)
The API of this module is unchanged: `TaskUtil`, `TaskCategoryProvider`, the `GroupedTaskRunner::TASK_CAT_*`
constants and the `public static $category` property on a task all work as before.

The changes you need to make come from Silverstripe 6 itself, which changed the `BuildTask` API
(see the [Silverstripe 6 changelog](https://docs.silverstripe.org/en/6/changelogs/6.0.0/)):

```php
// Silverstripe 4/5
private static $segment = 'my-task';
protected $title = 'My task';
protected $description = 'Does things';

public function run($request)
{
    // ...
    exit;
}

// Silverstripe 6
protected static string $commandName = 'my-task';
protected string $title = 'My task';
protected static string $description = 'Does things';

protected function execute(InputInterface $input, PolyOutput $output): int
{
    // ...
    return Command::SUCCESS;
}
```

Replace an `exit;` after `TaskUtil::echoFormHTML()` with `return Command::SUCCESS;`, so Silverstripe can finish the task
properly.

### Behaviour changes in 2.0
- Required fields are now really required: a field that is missing, empty or only whitespace shows the form again.
  Before, an empty value counted as filled in, and a required field listed by its label (`'New FirstName'`) was not
  checked at all. Both the key (`'New_FirstName'`) and the label work.
- Tables (`echoArrayAsTable()`) show readable values: `1` instead of `i:1;`, `Yes`/`No` instead of `b:1;`/`b:0;`,
  an empty cell instead of `N;`, and arrays as JSON. A row's `css_class` key is now applied to the row as a CSS class,
  e.g. `'section--error'`.
- `makePretty()` gives task output a new Silverstripe 6 look: a header band with a link back to `/dev/tasks`,
  messages grouped as a log, the form as a card and tables as cards with Yes/No badges. CSS registered with
  `addCSS()` is now added after the module's CSS, so it can override it (before, the module's CSS overrode it).
- The form markup is new: `<label>` elements, a "required" tag on required fields, a message on a required field
  that is left empty after submitting, a confirmation panel after a dry run and `<button>`s instead of
  `<input type="submit">`. A custom FormField's title is used as its label. The buttons say what they do: Preview,
  Preview again / Execute task (when the checkbox is ticked) and Start over. If you styled the old markup with
  `addCSS()`, check your CSS. `echoSpace()`, `echoSeparator()` and `echoHeading()` output classed elements instead of
  `<br><br>`, an inline-styled `<hr>` and a bare heading.
- On the command line (`sake`) the helpers output plain text instead of HTML: the form becomes a list of the
  fields with their values (and which required ones are missing), tables are aligned text, headings are underlined,
  and the restart form and confetti are left out. The `get...HTML()` methods still return HTML; the new
  `getFormText()` and `getArrayAsTextTable()` return the text versions.
- After a dry run the form confirms the action with a checkbox ("Execute the task") instead of a field to type 1 in.
  It sends the same `execute=1`, so existing links keep working. If you set your own label with
  `setExecuteFieldLabel()`, check that it reads well next to a checkbox, e.g. 'Yes, delete these records'.
- `ExampleTask` is disabled by default, so it (and its "Demonstrations" category) no longer shows up on every site.
  See [Enabling the example task](#enabling-the-example-task).

### If you extend the module's classes
The methods of `TaskUtil` and `GroupedTaskRunner` now have parameter and return types. If you extend one of these
classes and override a method, add the same return type to your override, or PHP will refuse to load your class.
`TaskUtil::makePretty()` now returns `true` when it outputs CSS (it returned `null`; it still returns `false` on the
command line).

## Running the tests
The tests need a Silverstripe 6 project with this module installed and a database (SQLite works). From the project
root:

```
composer require --dev phpunit/phpunit:^11.3
vendor/bin/phpunit vendor/hamaka/silverstripe-taskforms/tests/php
```

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
