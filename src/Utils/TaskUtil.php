<?php

    namespace Hamaka\TaskForms\Utils;

    use SilverStripe\Control\Controller;
    use SilverStripe\Control\Director;
    use SilverStripe\Core\Convert;
    use SilverStripe\ORM\DB;
    use SilverStripe\PolyExecution\HttpRequestInput;
    use Stringable;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use function _t;
    use function array_filter;
    use function array_key_exists;
    use function array_keys;
    use function array_map;
    use function array_values;
    use function count;
    use function dirname;
    use function escapeshellarg;
    use function file_get_contents;
    use function get_class;
    use function html_entity_decode;
    use function implode;
    use function is_array;
    use function is_bool;
    use function is_float;
    use function is_int;
    use function is_object;
    use function is_scalar;
    use function is_string;
    use function json_encode;
    use function max;
    use function mb_strlen;
    use function method_exists;
    use function preg_match;
    use function print_r;
    use function rtrim;
    use function str_repeat;
    use function str_replace;
    use function strip_tags;
    use function time;
    use function trim;

    /**
     * Class \Hamaka\TaskForms\Utils\TaskUtil
     *
     * Static helpers to make Silverstripe BuildTasks interactive and better looking in the browser:
     * - styled messages (echoGood(), echoNotice(), echoError() etc.) and layout helpers (headings, separators, tables)
     * - a GET form that is generated from a map of request keys to variables (getFormHTML()), including the logic to
     *   detect a first load, a dry run and a live run (isFirstLoadOrLoadWithoutAllRequiredFields(), isDryRun(),
     *   isLiveRun())
     *
     * A task builds a map of request keys to references of its own variables, e.g. ['MemberID' => &$sMemberID].
     * The helpers hydrate those variables from the request, so the task can use them directly.
     *
     * Messages (echoNormal() etc.), headings and table values are output as HTML, so tasks can use markup like links.
     * Escape values from the request or the database yourself with Convert::raw2xml(). The generated form escapes
     * its own labels and values.
     * See \Hamaka\TaskForms\ExampleTask for a complete example.
     */
    class TaskUtil
    {
        // type values for DB::alteration_message() that result in the different message styles
        public const ALT_MESSAGE_TYPE_CHANGED = 'changed'; // class info
        public const ALT_MESSAGE_TYPE_COMMENT = 'created';
        public const ALT_MESSAGE_TYPE_NOTICE = 'notice'; // class warning
        public const ALT_MESSAGE_TYPE_ERROR = 'error'; // class error

        // values for the $sLayout parameter of getArrayAsTable()
        public const TABLE_LAYOUT_HORIZONTAL = 'horizontal';
        public const TABLE_LAYOUT_STACKED = 'stacked';

        // key in a table row that holds a CSS class for that row instead of a column (e.g. 'section--error')
        public const TABLE_ROW_CSS_CLASS_KEY = 'css_class';

        // request key (and CLI option) that confirms the action after a dry run, with the value 1
        public const EXECUTE_FIELD_NAME = 'execute';

        // request key that the generated forms always send, to tell a submitted form from a first load
        public const CACHEBUSTER_FIELD_NAME = 'cachebuster';

        protected static array $aExtraCss = [];
        // null means the translated default label
        protected static ?string $sExecuteFieldLabel = null;

        // input of the running task, see setInput()
        protected static ?InputInterface $oInput = null;

        /**
         * Lets the helpers read the task's input (the InputInterface passed to execute()), so values can be passed as
         * CLI options with sake as well as in the browser. Call it at the start of execute(). The task has to declare
         * the options in getOptions(), see getInputOptionsForFields(). Without input, or for fields that are not an
         * option, the helpers read the request ($_GET), which only works in the browser.
         *
         * Pass null to stop using the input, e.g. when several tasks run in one process.
         */
        public static function setInput(?InputInterface $oInput): void
        {
            static::$oInput = $oInput;
        }

        /**
         * Returns the input options for the fields of a task form, to return from getOptions() in the task. Silverstripe
         * then accepts them as CLI options (sake tasks:my-task --MemberID=3) and as request parameters, and lists them
         * on /dev/tasks. Adds the execute option when the action needs to be previewed.
         *
         * @param array $aFieldNames                  The request keys of the form, the keys of $aGetKeyToVarMap
         * @param bool  $bDoesActionNeedToBePreviewed Whether the user has to confirm the action after a dry run
         * @return InputOption[]
         */
        public static function getInputOptionsForFields(array $aFieldNames, bool $bDoesActionNeedToBePreviewed = true): array
        {
            $aOptions = [];

            foreach ($aFieldNames as $sFieldName) {
                $aOptions[] = new InputOption($sFieldName, null, InputOption::VALUE_REQUIRED, str_replace('_', ' ', $sFieldName));
            }

            if ($bDoesActionNeedToBePreviewed) {
                $aOptions[] = new InputOption(static::EXECUTE_FIELD_NAME, null, InputOption::VALUE_REQUIRED, static::getExecuteFieldLabel());
            }

            return $aOptions;
        }

        /**
         * Registers extra CSS that makePretty() adds to the page after the module's CSS, so it can override it.
         * Call it before makePretty().
         */
        public static function addCSS(string $sCss = ''): void
        {
            static::$aExtraCss[] = $sCss;
        }

        /**
         * Outputs the module's CSS (client/css/taskforms.css), followed by the CSS registered with addCSS(), to style
         * the task output in the browser in the Silverstripe 6 look, plus a link back to /dev/tasks. Call it once at the
         * start of a task. Does nothing on the command line.
         *
         * @param bool $bEnablePartyMode Shows the headings in Comic Sans
         * @return bool Whether the CSS was output
         */
        public static function makePretty(bool $bEnablePartyMode = false): bool
        {
            if (static::isOutputForCli()) {
                return false;
            }

            $aCss = [static::getModuleFileContents('client/css/taskforms.css')];

            if ($bEnablePartyMode === true) {
                $aCss[] = 'h1 { font-family: "Comic Sans MS", "Comic Sans", cursive; }';
            }

            // after the module's CSS, so it can override it
            foreach (static::$aExtraCss as $sCss) {
                $aCss[] = $sCss;
            }

            echo('<meta name="viewport" content="width=device-width, initial-scale=1">');
            echo('<style>' . "\n" . implode("\n", $aCss) . "\n" . '</style>');
            echo('<a class="taskforms-back" href="' . Convert::raw2att(Controller::join_links(Director::absoluteBaseURL(), 'dev/tasks')) . '">'
                . Convert::raw2xml(_t(__CLASS__ . '.LINK_ALL_TASKS', 'All tasks')) . '</a>');

            return true;
        }

        /**
         * Whether the task should only show what it would do. True on a first load (not all required fields are
         * filled in) and, when the action needs to be previewed, as long as the execute confirmation is missing.
         *
         * @param array $aFieldNamesAndVars          Map of request keys to references of the task's variables
         * @param array $aRequiredFieldsNamesAndVars List of required request keys, or a map of request keys to references
         * @param bool  $bDoesActionNeedToBePreviewed Whether the user has to confirm the action after a dry run
         */
        public static function isDryRun(array $aFieldNamesAndVars, array $aRequiredFieldsNamesAndVars, bool $bDoesActionNeedToBePreviewed = true): bool
        {
            // To make things easy we mark a first load as a dry run
            // isFirstLoad can be called stand alone to specify different behaviour on first load
            if (static::isFirstLoadOrLoadWithoutAllRequiredFields($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars) === true) {
                return true;
            }

            if ($bDoesActionNeedToBePreviewed === true && ! static::doesRequestContainExecuteConfirmation()) {
                return true;
            }

            return false;
        }

        /**
         * Whether the task should actually make its changes: all required fields are filled in and, when the action
         * needs to be previewed, the execute confirmation is present. The opposite of isDryRun().
         *
         * @param array $aFieldNamesAndVars          Map of request keys to references of the task's variables
         * @param array $aRequiredFieldsNamesAndVars List of required request keys, or a map of request keys to references
         * @param bool  $bDoesActionNeedToBePreviewed Whether the user has to confirm the action after a dry run
         */
        public static function isLiveRun(array $aFieldNamesAndVars, array $aRequiredFieldsNamesAndVars, bool $bDoesActionNeedToBePreviewed = true): bool
        {
            if (static::isFirstLoadOrLoadWithoutAllRequiredFields($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars)) {
                return false;
            }

            if (static::isDryRun($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed)) {
                return false;
            }

            return true;
        }

        /**
         * Whether the request lacks the execute confirmation, so only a preview should be shown.
         *
         * @param bool $bDoesActionNeedToBePreviewed Not used; kept for backwards compatibility
         */
        public static function doWeOnlyShowPreview(bool $bDoesActionNeedToBePreviewed = true): bool
        {
            return ! static::doesRequestContainExecuteConfirmation();
        }

        /**
         * Whether the form still has to be shown because not all required fields are in the request. Hydrates the
         * task's variables from the request as a side effect.
         *
         * @param array $aFieldNamesAndVars          Map of request keys to references of the task's variables
         * @param array $aRequiredFieldsNamesAndVars List of required request keys, or a map of request keys to references
         */
        public static function isFirstLoadOrLoadWithoutAllRequiredFields(array $aFieldNamesAndVars, array $aRequiredFieldsNamesAndVars): bool
        {
            return ! static::areAllRequiredFieldsSet($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars);
        }

        /**
         * Copies the values of the request (GET), or of the task's input options (see setInput()), into the task's
         * variables.
         *
         * @param array $aGetKeyToVarMap Map of request keys to references of the task's variables
         */
        public static function hydrateVarsFromRequest(array $aGetKeyToVarMap = []): void
        {
            foreach ($aGetKeyToVarMap as $sGetKey => &$mVar) {
                $mValue = static::getRequestValue($sGetKey);

                if ($mValue !== null) {
                    $mVar = $mValue;
                }
            }
        }

        /**
         * Whether the user confirmed the action after the dry run: execute=1 in the request, or --execute=1 on the
         * command line.
         */
        public static function doesRequestContainExecuteConfirmation(): bool
        {
            $mValue = static::getRequestValue(static::EXECUTE_FIELD_NAME);

            if (is_scalar($mValue) && (int)$mValue === 1) {
                return true;
            }

            return false;
        }

        /**
         * Whether all required fields are in the request with a value. Hydrates the task's variables from the request
         * first. A field without a value, or with only whitespace, counts as missing.
         *
         * @param array $aFieldNamesAndVars          Map of request keys to references of the task's variables
         * @param array $aRequiredFieldsNamesAndVars List of required fields, by request key ('New_FirstName') or by
         *                                           label ('New FirstName'), or a map of request keys to references
         */
        public static function areAllRequiredFieldsSet(array $aFieldNamesAndVars, array $aRequiredFieldsNamesAndVars = []): bool
        {
            static::hydrateVarsFromRequest($aFieldNamesAndVars);

            $bAreAllPresent = true;

            if (static::isRequiredFieldsAMap($aRequiredFieldsNamesAndVars)) {
                foreach ($aRequiredFieldsNamesAndVars as $sGetKey => &$mVar) {
                    if ( ! static::isValueFilledIn($mVar)) {
                        $bAreAllPresent = false;
                    }
                }
            }
            else {
                foreach ($aRequiredFieldsNamesAndVars as $sFieldName) {
                    foreach ($aFieldNamesAndVars as $sGetKey => &$mVar) {
                        if (static::isFieldNameForGetKey($sFieldName, $sGetKey) && ! static::isValueFilledIn($mVar)) {
                            $bAreAllPresent = false;
                        }
                    }
                }
            }

            return $bAreAllPresent;
        }

        /**
         * Outputs a message in the default style. Values that are not strings are shown with print_r().
         */
        public static function echoNormal(mixed $mMessage): void
        {
            DB::alteration_message(static::getAsString($mMessage));
        }

        /**
         * Outputs a message in the success style (green). Values that are not strings are shown with print_r().
         */
        public static function echoGood(mixed $mMessage): void
        {
            DB::alteration_message(static::getAsString($mMessage), static::ALT_MESSAGE_TYPE_CHANGED);
        }

        /**
         * Outputs a message in the warning style (orange). Values that are not strings are shown with print_r().
         */
        public static function echoNotice(mixed $mMessage): void
        {
            DB::alteration_message(static::getAsString($mMessage), static::ALT_MESSAGE_TYPE_NOTICE);
        }

        /**
         * Outputs a message in the error style (red). Values that are not strings are shown with print_r().
         */
        public static function echoError(mixed $mMessage): void
        {
            DB::alteration_message(static::getAsString($mMessage), static::ALT_MESSAGE_TYPE_ERROR);
        }

        /**
         * Outputs an indented message, meant as a comment on the previous message. Values that are not strings are
         * shown with print_r().
         */
        public static function echoCommentOnPrev(mixed $mMessage): void
        {
            DB::alteration_message(static::getAsString($mMessage), static::ALT_MESSAGE_TYPE_COMMENT);
        }

        /**
         * Outputs vertical whitespace.
         */
        public static function echoSpace(): void
        {
            echo(static::isOutputForCli() ? "\n" : '<div class="taskforms-space"></div>');
        }

        /**
         * Outputs a horizontal line.
         */
        public static function echoSeparator(): void
        {
            if (static::isOutputForCli()) {
                echo("\n" . str_repeat('-', 60) . "\n\n");
                return;
            }

            echo('<hr class="taskforms-separator">');
        }

        /**
         * Outputs a heading. On the command line the label is underlined: with = for level 1 and 2, with - for the
         * other levels.
         *
         * @param int $iHeadingLevel 1 to 6 for <h1> to <h6>
         */
        public static function echoHeading(string $sLabel = '', int $iHeadingLevel = 2): void
        {
            if (static::isOutputForCli()) {
                $sText = static::getHtmlAsText($sLabel);
                echo("\n" . $sText . "\n" . str_repeat($iHeadingLevel <= 2 ? '=' : '-', mb_strlen($sText)) . "\n");
                return;
            }

            echo('<h' . $iHeadingLevel . ' class="taskforms-heading">' . $sLabel . '</h' . $iHeadingLevel . '>');
        }

        /**
         * Outputs the form of getFormHTML(). On the command line it outputs the fields with their values instead,
         * see getFormText().
         *
         * @see getFormHTML()
         */
        public static function echoFormHTML(array $aGetKeyToVarMap = [], array $aRequiredFieldsNamesAndVars = [], bool $bDoesActionNeedToBePreviewed = false, array $aCustomComponents = []): void
        {
            if (static::isOutputForCli()) {
                echo(static::getFormText($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed));
                return;
            }

            echo(static::getFormHTML($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed, $aCustomComponents));
        }

        /**
         * Returns the input of a task as plain text for the command line: every field as a CLI option with its current
         * value, which required fields are missing and, after a dry run, how to execute the task.
         *
         * @see getFormHTML()
         */
        public static function getFormText(array $aGetKeyToVarMap = [], array $aRequiredFieldsNamesAndVars = [], bool $bDoesActionNeedToBePreviewed = false): string
        {
            // also hydrates the task's variables from the request
            $bIsExecuteFieldNeeded = static::isExecuteFieldNeeded($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed);

            $aLines              = [_t(__CLASS__ . '.CLI_INPUT', 'Input:')];
            $bAreAllFieldsOption = true;

            foreach ($aGetKeyToVarMap as $sGetKey => $mVar) {
                $sLine = '  --' . $sGetKey . '=';

                if (static::isValueFilledIn($mVar)) {
                    $sLine .= preg_match('/^[\w.,:@\/-]+$/', $mVar) ? $mVar : escapeshellarg($mVar);
                }
                elseif (static::isFieldRequired($sGetKey, $aRequiredFieldsNamesAndVars)) {
                    $sLine .= '  ' . _t(__CLASS__ . '.CLI_REQUIRED_MISSING', '(required, missing)');
                }

                $aLines[]            = $sLine;
                $bAreAllFieldsOption = $bAreAllFieldsOption && static::isFieldAnInputOption($sGetKey);
            }

            if ($bIsExecuteFieldNeeded) {
                $aLines[] = '  --' . static::EXECUTE_FIELD_NAME . '=1  (' . static::getExecuteFieldLabel() . ')';
            }

            if ( ! $bAreAllFieldsOption) {
                $aLines[] = _t(
                    __CLASS__ . '.CLI_OPTIONS_MISSING',
                    'This task does not accept all of these options yet: return TaskUtil::getInputOptionsForFields() from getOptions() in the task and call TaskUtil::setInput() in execute().'
                );
            }

            return implode("\n", $aLines) . "\n";
        }

        /**
         * Returns a GET form with a field for every request key in $aGetKeyToVarMap, filled with the current values.
         * After the first load of a task that needs to be previewed, a checkbox asks to confirm the action
         * (EXECUTE_FIELD_NAME=1).
         * Labels are the titles of custom FormFields, or the request keys with underscores replaced by spaces. Required
         * fields are marked, and after submitting, a required field without a value gets a message. Labels and values
         * are escaped, because the values come from the request.
         *
         * @param array $aGetKeyToVarMap              Map of request keys to references of the task's variables
         * @param array $aRequiredFieldsNamesAndVars  List of required request keys, or a map of request keys to references
         * @param bool  $bDoesActionNeedToBePreviewed Whether the user has to confirm the action after a dry run
         * @param array $aCustomComponents            Map of request keys to a FormField (or another object with
         *                                            forTemplate()) or an HTML string, to use instead of a text field.
         *                                            The value of an HTML string is not filled in.
         */
        public static function getFormHTML(array $aGetKeyToVarMap = [], array $aRequiredFieldsNamesAndVars = [], bool $bDoesActionNeedToBePreviewed = false, array $aCustomComponents = []): string
        {
            // also hydrates the task's variables from the request
            $bIsFirstLoad          = static::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars);
            $bIsExecuteFieldNeeded = static::isExecuteFieldNeeded($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed);

            $bIsSubmitted = isset($_GET[static::CACHEBUSTER_FIELD_NAME]);

            $aHtml   = [];
            $aHtml[] = '<form class="taskforms-form' . ($bIsExecuteFieldNeeded ? ' taskforms-form--dry-run' : '') . '" action="" method="get">';
            $aHtml[] = '<div class="taskforms-form__fields">';

            foreach ($aGetKeyToVarMap as $sGetKey => &$mVar) {
                if ( ! is_string($mVar)) {
                    $mVar = '';
                }

                $bIsRequired  = static::isFieldRequired($sGetKey, $aRequiredFieldsNamesAndVars);
                $bIsMissing   = $bIsSubmitted && $bIsRequired && ! static::isValueFilledIn($mVar);
                $mComponent   = $aCustomComponents[$sGetKey] ?? null;
                $sInputId     = 'taskforms-field-' . Convert::raw2htmlid($sGetKey);
                $sControlHtml = '';

                if (is_string($mComponent)) {
                    // a component can be passed as string but the downside: the value is not set
                    $sControlHtml = $mComponent;
                    $sInputId     = '';
                }
                elseif (is_object($mComponent) && method_exists($mComponent, 'forTemplate')) {
                    if (method_exists($mComponent, 'setValue')) {
                        $mComponent->setValue($mVar);
                    }

                    $sControlHtml = (string)$mComponent->forTemplate();
                    $sInputId     = method_exists($mComponent, 'ID') ? (string)$mComponent->ID() : '';
                }
                else {
                    $sControlHtml = '<input type="text" class="taskforms-field__input" id="' . $sInputId . '" name="' . Convert::raw2att($sGetKey) . '" value="' . Convert::raw2att($mVar) . '"' . ($bIsMissing ? ' aria-invalid="true"' : '') . '>';
                }

                $aHtml[] = '<div class="taskforms-field' . ($bIsMissing ? ' taskforms-field--missing' : '') . '">';
                $aHtml[] = '<label class="taskforms-field__label"' . ($sInputId !== '' ? ' for="' . Convert::raw2att($sInputId) . '"' : '') . '>'
                    . Convert::raw2xml(static::getFieldLabel($sGetKey, $mComponent))
                    . ($bIsRequired ? ' <span class="taskforms-field__required">' . Convert::raw2xml(_t(__CLASS__ . '.FIELD_REQUIRED', 'required')) . '</span>' : '')
                    . '</label>';
                $aHtml[] = $sControlHtml;

                if ($bIsMissing) {
                    $aHtml[] = '<p class="taskforms-field__message">' . Convert::raw2xml(_t(__CLASS__ . '.FIELD_MISSING', 'Please fill in this field.')) . '</p>';
                }

                $aHtml[] = '</div>';
            }

            $aHtml[] = '</div>';

            if ($bIsExecuteFieldNeeded) {
                $aHtml[] = '<div class="taskforms-confirm">';
                $aHtml[] = '<label class="taskforms-confirm__label"><input type="checkbox" name="' . static::EXECUTE_FIELD_NAME . '" value="1" class="taskforms-confirm__checkbox"'
                    . ' onchange="var b=this.form.querySelector(\'[data-label-checked]\');b.textContent=this.checked?b.dataset.labelChecked:b.dataset.labelUnchecked;b.classList.toggle(\'taskforms-button--danger\',this.checked);">'
                    . ' <span>' . static::getExecuteFieldLabel() . '</span></label>';
                $aHtml[] = '<p class="taskforms-confirm__hint">' . Convert::raw2xml(_t(__CLASS__ . '.CONFIRM_HINT', 'Changes are only made when this box is ticked.')) . '</p>';
                $aHtml[] = '</div>';
            }

            $aHtml[] = '<input type="hidden" name="' . static::CACHEBUSTER_FIELD_NAME . '" value="' . time() . '">';
            $aHtml[] = '<div class="taskforms-form__actions">';

            if ($bIsExecuteFieldNeeded) {
                $sLabelUnchecked = _t(__CLASS__ . '.BUTTON_PREVIEW_AGAIN', 'Preview again');
                $sLabelChecked   = _t(__CLASS__ . '.BUTTON_EXECUTE', 'Execute task');
                $aHtml[]         = '<button type="submit" class="taskforms-button taskforms-button--primary" data-label-unchecked="' . Convert::raw2att($sLabelUnchecked) . '" data-label-checked="' . Convert::raw2att($sLabelChecked) . '">' . Convert::raw2xml($sLabelUnchecked) . '</button>';
            }
            elseif ($bDoesActionNeedToBePreviewed === true && $bIsFirstLoad) {
                $aHtml[] = '<button type="submit" class="taskforms-button taskforms-button--primary">' . Convert::raw2xml(_t(__CLASS__ . '.BUTTON_PREVIEW', 'Preview')) . '</button>';
            }
            else {
                $aHtml[] = '<button type="submit" class="taskforms-button taskforms-button--primary">' . Convert::raw2xml(_t(__CLASS__ . '.BUTTON_SUBMIT', 'Submit')) . '</button>';
            }

            $aHtml[] = '</div>';
            $aHtml[] = '</form>';

            return implode("\n", $aHtml);
        }

        /**
         * Sets the label of the checkbox that confirms the action after a dry run, e.g. 'Yes, delete these records'.
         *
         * @param mixed $mExecuteFieldLabel Ignored when it is not a string
         */
        public static function setExecuteFieldLabel(mixed $mExecuteFieldLabel): void
        {
            if (is_string($mExecuteFieldLabel)) {
                static::$sExecuteFieldLabel = $mExecuteFieldLabel;
            }
        }

        /**
         * Returns the label of the checkbox that confirms the action after a dry run: the one set with
         * setExecuteFieldLabel(), or the translated default.
         */
        public static function getExecuteFieldLabel(): string
        {
            return static::$sExecuteFieldLabel ?? _t(__CLASS__ . '.EXECUTE_FIELD_LABEL', 'Yes, execute the task');
        }

        /**
         * Outputs the form of getResetFormHTML(). Does nothing on the command line.
         */
        public static function echoResetFormHTML(): void
        {
            if (static::isOutputForCli()) {
                return;
            }

            echo(static::getResetFormHTML());
        }

        /**
         * Returns a form with a button that reloads the task without any input, to start over.
         */
        public static function getResetFormHTML(): string
        {
            $aHtml = [];

            $aHtml[] = '<form class="taskforms-reset" action="" method="get">';
            $aHtml[] = '<input type="hidden" name="' . static::CACHEBUSTER_FIELD_NAME . '" value="' . time() . '">';
            $aHtml[] = '<button type="submit" class="taskforms-button">' . Convert::raw2xml(_t(__CLASS__ . '.BUTTON_RESTART', 'Start over')) . '</button>';
            $aHtml[] = '</form>';

            return implode("\n", $aHtml);
        }

        /**
         * Outputs a confetti animation (client/js/confetti.js) that runs for 10 seconds, to celebrate a finished task.
         * Does nothing on the command line.
         */
        public static function addConfetti(): void
        {
            if ( ! static::isOutputForCli()) {
                echo('<script>' . "\n" . static::getModuleFileContents('client/js/confetti.js') . '</script>');
            }
        }

        /**
         * Outputs the table of getArrayAsTable(), or of getArrayAsTextTable() on the command line.
         *
         * @see getArrayAsTable()
         */
        public static function echoArrayAsTable(iterable $mTableData, bool $bShowHeaders = true, string $sLayout = self::TABLE_LAYOUT_HORIZONTAL): void
        {
            if (static::isOutputForCli()) {
                echo(static::getArrayAsTextTable($mTableData, $bShowHeaders, $sLayout));
                return;
            }

            echo(static::getArrayAsTable($mTableData, $bShowHeaders, $sLayout));
        }

        /**
         * Returns a table for a list of rows as plain text for the command line, with the same input as
         * getArrayAsTable(). The horizontal layout aligns the columns, the stacked layout shows "label: value" lines.
         *
         * @see getArrayAsTable()
         */
        public static function getArrayAsTextTable(iterable $mTableData, bool $bShowHeaders = true, string $sLayout = self::TABLE_LAYOUT_HORIZONTAL): string
        {
            $aRows = [];
            foreach ($mTableData as $mRowData) {
                $aRows[] = $mRowData;
            }

            if ($sLayout === static::TABLE_LAYOUT_STACKED) {
                return static::getRowsAsStackedText($aRows, $bShowHeaders);
            }

            return static::getRowsAsHorizontalText($aRows, $bShowHeaders);
        }

        /**
         * Returns an HTML table for a list of rows.
         *
         * @param iterable $mTableData   List of rows. A row is an associative array of column labels to values, or a
         *                               single value. The key TABLE_ROW_CSS_CLASS_KEY ('css_class') of a row is not
         *                               shown, but sets a CSS class on the row, e.g. 'section--correct', 'section--error'.
         * @param bool     $bShowHeaders Whether to show the column labels (taken from the keys of the first row)
         * @param string   $sLayout      TABLE_LAYOUT_HORIZONTAL for one row per item, TABLE_LAYOUT_STACKED for one
         *                               label/value pair per row
         */
        public static function getArrayAsTable(iterable $mTableData, bool $bShowHeaders = true, string $sLayout = self::TABLE_LAYOUT_HORIZONTAL): string
        {
            if ($sLayout === static::TABLE_LAYOUT_STACKED) {
                return static::getArrayAsStackedTable($mTableData, $bShowHeaders);
            }

            return static::getArrayAsTableHorizontal($mTableData, $bShowHeaders);
        }

        /**
         * Whether a value from the request is filled in: a string that is not empty or only whitespace.
         * '0' counts as filled in.
         */
        protected static function isValueFilledIn(mixed $mValue): bool
        {
            return is_string($mValue) && trim($mValue) !== '';
        }

        /**
         * Whether a required field name refers to a request key: either the key itself ('New_FirstName') or its label
         * as shown in the form ('New FirstName').
         */
        protected static function isFieldNameForGetKey(string $sFieldName, string $sGetKey): bool
        {
            return $sFieldName === $sGetKey || $sFieldName === str_replace('_', ' ', $sGetKey);
        }

        /**
         * Returns a value as readable HTML for a table cell. Strings are used as they are (they can contain markup),
         * booleans become Yes/No badges, null becomes an empty cell and arrays are shown as escaped JSON. Objects are cast to
         * a string when they can be, otherwise their class name is shown.
         */
        protected static function getValueForTableCell(mixed $mValue): string
        {
            return match (true) {
                is_string($mValue)                   => $mValue,
                $mValue === null                     => '',
                is_bool($mValue)                     => $mValue
                    ? '<span class="taskforms-badge taskforms-badge--yes">' . Convert::raw2xml(_t(__CLASS__ . '.YES', 'Yes')) . '</span>'
                    : '<span class="taskforms-badge taskforms-badge--no">' . Convert::raw2xml(_t(__CLASS__ . '.NO', 'No')) . '</span>',
                is_int($mValue), is_float($mValue)   => (string)$mValue,
                $mValue instanceof Stringable        => (string)$mValue,
                is_array($mValue)                    => Convert::raw2xml(json_encode($mValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                is_object($mValue)                   => Convert::raw2xml(get_class($mValue)),
                default                              => '',
            };
        }

        /**
         * Returns the value for a request key: from the task's input when the key is one of its options and has a
         * value, otherwise from the request ($_GET). Null when there is no value.
         */
        protected static function getRequestValue(string $sKey): mixed
        {
            if (static::isFieldAnInputOption($sKey)) {
                $mValue = static::$oInput->getOption($sKey);

                if ($mValue !== null) {
                    return $mValue;
                }
            }

            return $_GET[$sKey] ?? null;
        }

        /**
         * Whether a request key is an option of the task's input, so it can be passed on the command line.
         */
        protected static function isFieldAnInputOption(string $sKey): bool
        {
            return static::$oInput?->hasOption($sKey) === true;
        }

        /**
         * Whether the output goes to the command line (sake) instead of the browser. The helpers then output plain
         * text instead of HTML. With setInput(), the type of input decides (Silverstripe passes an HttpRequestInput
         * when a task runs in the browser); without it, whether PHP runs on the command line.
         */
        protected static function isOutputForCli(): bool
        {
            if (static::$oInput !== null) {
                return ! (static::$oInput instanceof HttpRequestInput);
            }

            return Director::is_cli();
        }

        /**
         * Returns HTML as plain text for the command line: without tags and with decoded entities.
         */
        protected static function getHtmlAsText(string $sHtml): string
        {
            return html_entity_decode(strip_tags($sHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        /**
         * Returns the label of a form field: the title of its custom FormField when it has one, otherwise the request
         * key with underscores replaced by spaces.
         */
        protected static function getFieldLabel(string $sGetKey, mixed $mComponent): string
        {
            if (is_object($mComponent) && method_exists($mComponent, 'Title')) {
                $sTitle = trim((string)$mComponent->Title());

                if ($sTitle !== '') {
                    return $sTitle;
                }
            }

            return str_replace('_', ' ', $sGetKey);
        }

        /**
         * Whether the required fields are given as a map of request keys to references (['Name' => &$sName]) instead of
         * a list of request keys or labels (['Name']).
         */
        protected static function isRequiredFieldsAMap(array $aRequiredFieldsNamesAndVars): bool
        {
            return count(array_filter(array_keys($aRequiredFieldsNamesAndVars), 'is_string')) > 0;
        }

        /**
         * Whether the form needs the checkbox that confirms the action: the action needs to be previewed, all required
         * fields are filled in and the action is not confirmed yet. Hydrates the task's variables from the request.
         */
        protected static function isExecuteFieldNeeded(array $aGetKeyToVarMap, array $aRequiredFieldsNamesAndVars, bool $bDoesActionNeedToBePreviewed): bool
        {
            return $bDoesActionNeedToBePreviewed
                && ! static::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars)
                && ! static::doesRequestContainExecuteConfirmation();
        }

        /**
         * Whether a request key is one of the required fields.
         *
         * @param array $aRequiredFieldsNamesAndVars List of required request keys or labels, or a map of request keys
         *                                           to references
         */
        protected static function isFieldRequired(string $sGetKey, array $aRequiredFieldsNamesAndVars): bool
        {
            if (static::isRequiredFieldsAMap($aRequiredFieldsNamesAndVars)) {
                return array_key_exists($sGetKey, $aRequiredFieldsNamesAndVars);
            }

            foreach ($aRequiredFieldsNamesAndVars as $sFieldName) {
                if (static::isFieldNameForGetKey($sFieldName, $sGetKey)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Returns rows as a text table with aligned columns. The column labels come from the keys of the first row.
         *
         * @param array $aRows List of rows, each an associative array of column labels to values or a single value
         */
        protected static function getRowsAsHorizontalText(array $aRows, bool $bShowHeaders): string
        {
            // rows that are a single value have no column labels
            $bShowHeaders = $bShowHeaders && count($aRows) > 0 && is_array($aRows[0]);
            $aRows        = array_map(fn(mixed $mRowData): array => is_array($mRowData) ? $mRowData : [$mRowData], $aRows);
            $aTextRows    = [];

            if ($bShowHeaders) {
                $aTextRows[] = array_map('strval', array_keys(static::getRowWithoutCssClass($aRows[0])));
            }

            foreach ($aRows as $aRow) {
                $aTextRows[] = array_map(
                    fn(mixed $mValue): string => static::getHtmlAsText(static::getValueForTableCell($mValue)),
                    array_values(static::getRowWithoutCssClass($aRow))
                );
            }

            $aColumnWidths = [];
            foreach ($aTextRows as $aTextRow) {
                foreach ($aTextRow as $iColumnNr => $sText) {
                    $aColumnWidths[$iColumnNr] = max($aColumnWidths[$iColumnNr] ?? 0, mb_strlen($sText));
                }
            }

            $aLines = [];
            foreach ($aTextRows as $iRowNr => $aTextRow) {
                $aCells = [];
                foreach ($aTextRow as $iColumnNr => $sText) {
                    $aCells[] = $sText . str_repeat(' ', $aColumnWidths[$iColumnNr] - mb_strlen($sText));
                }

                $aLines[] = rtrim(implode(' | ', $aCells));

                if ($iRowNr === 0 && $bShowHeaders) {
                    $aLines[] = implode('-+-', array_map(fn(int $iWidth): string => str_repeat('-', $iWidth), $aColumnWidths));
                }
            }

            return implode("\n", $aLines) . "\n";
        }

        /**
         * Returns rows as "label: value" lines, with an empty line between rows.
         *
         * @param array $aRows List of rows, each an associative array of labels to values or a single value
         */
        protected static function getRowsAsStackedText(array $aRows, bool $bShowHeaders): string
        {
            $aBlocks = [];

            foreach ($aRows as $mRowData) {
                if ( ! is_array($mRowData)) {
                    $aBlocks[] = static::getHtmlAsText(static::getValueForTableCell($mRowData));
                    continue;
                }

                $aRow   = $mRowData;
                $aLines = [];
                foreach (static::getRowWithoutCssClass($aRow) as $mFieldLabel => $mFieldContent) {
                    $sText    = static::getHtmlAsText(static::getValueForTableCell($mFieldContent));
                    $aLines[] = $bShowHeaders ? $mFieldLabel . ': ' . $sText : $sText;
                }

                $aBlocks[] = implode("\n", $aLines);
            }

            return implode("\n\n", $aBlocks) . "\n";
        }

        /**
         * Returns a table row without its TABLE_ROW_CSS_CLASS_KEY, which is not a column.
         */
        protected static function getRowWithoutCssClass(array $aRow): array
        {
            unset($aRow[static::TABLE_ROW_CSS_CLASS_KEY]);

            return $aRow;
        }

        /**
         * Returns the contents of a file in this module, e.g. 'client/css/taskforms.css'.
         */
        protected static function getModuleFileContents(string $sRelativePath): string
        {
            return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $sRelativePath);
        }

        /**
         * Returns the <tr> tag for a table row, with the CSS class from the row's TABLE_ROW_CSS_CLASS_KEY if it has one.
         */
        protected static function getTableRowOpeningTag(mixed $mRowData): string
        {
            if (is_array($mRowData) && isset($mRowData[static::TABLE_ROW_CSS_CLASS_KEY])) {
                return '<tr class="' . Convert::raw2att($mRowData[static::TABLE_ROW_CSS_CLASS_KEY]) . '">';
            }

            return '<tr>';
        }

        /**
         * Returns a value as a string for output. Values that are not strings are shown with print_r().
         */
        protected static function getAsString(mixed $mMessage): string
        {
            if (is_string($mMessage)) {
                return $mMessage;
            }

            return '<pre>' . print_r($mMessage, true) . '</pre>';
        }

        /**
         * Returns a table with one row per item.
         *
         * @see getArrayAsTable()
         */
        protected static function getArrayAsTableHorizontal(iterable $mTableData, bool $bShowHeaders = true): string
        {
            $aHtml   = [];
            $aHtml[] = '<div class="taskforms-table"><table>';

            $iRowNr       = 1;
            $iColumnCount = 1;
            foreach ($mTableData as $mRowData) {
                if ($iColumnCount === 1 && is_array($mRowData)) {
                    $iColumnCount = max(1, count(static::getRowWithoutCssClass($mRowData)));
                }

                // header
                if ($iRowNr === 1 && $bShowHeaders === true) {
                    $aHtml[] = '<tr>';

                    if (is_array($mRowData)) {
                        foreach ($mRowData as $sFieldLabel => $mFieldContent) {
                            if ($sFieldLabel !== static::TABLE_ROW_CSS_CLASS_KEY) {
                                $aHtml[] = '<th>' . $sFieldLabel . '</th>';
                            }
                        }
                    }
                    else {
                        $aHtml[] = '<th>' . static::getValueForTableCell($mRowData) . '</th>';
                    }

                    $aHtml[] = '</tr>';
                }

                // regular row
                $aHtml[] = static::getTableRowOpeningTag($mRowData);

                if (is_array($mRowData)) {
                    foreach ($mRowData as $sFieldLabel => $mFieldContent) {
                        if ($sFieldLabel !== static::TABLE_ROW_CSS_CLASS_KEY) {
                            $aHtml[] = '<td>' . static::getValueForTableCell($mFieldContent) . '</td>';
                        }
                    }
                }
                else {
                    // a single value spans all columns
                    $aHtml[] = '<td' . ($iColumnCount > 1 ? ' colspan="' . $iColumnCount . '"' : '') . '>' . static::getValueForTableCell($mRowData) . '</td>';
                }

                $aHtml[] = '</tr>';

                ++$iRowNr;
            }

            $aHtml[] = '</table></div>';

            return implode('', $aHtml);
        }

        /**
         * Returns a table with one label/value pair per row and an empty row between items.
         *
         * @see getArrayAsTable()
         */
        protected static function getArrayAsStackedTable(iterable $mTableData, bool $bShowHeaders = true): string
        {
            $aHtml   = [];
            $aHtml[] = '<div class="taskforms-table taskforms-table--stacked"><table>';

            foreach ($mTableData as $mRowData) {

                if (is_array($mRowData)) {
                    $sRowOpeningTag = static::getTableRowOpeningTag($mRowData);

                    foreach ($mRowData as $sFieldLabel => $mFieldContent) {
                        if ($sFieldLabel === static::TABLE_ROW_CSS_CLASS_KEY) {
                            continue;
                        }

                        if ($bShowHeaders) {
                            $aHtml[] = $sRowOpeningTag;
                            $aHtml[] = '  <th style="text-align: left">' . $sFieldLabel . ':</th>';
                            $aHtml[] = '</tr>';
                        }

                        $aHtml[] = $sRowOpeningTag;
                        $aHtml[] = '  <td>' . static::getValueForTableCell($mFieldContent) . '</td>';
                        $aHtml[] = '</tr>';
                    }
                }
                else {
                    $aHtml[] = '<tr>';
                    $aHtml[] = '  <td>' . static::getValueForTableCell($mRowData) . '</td>';
                    $aHtml[] = '</tr>';
                }

                $aHtml[] = '<tr class="taskform-arraytable__separator-row">';
                $aHtml[] = '  <td class="taskform-arraytable__separator-cell">&nbsp;</td>';
                $aHtml[] = '</tr>';
            }

            $aHtml[] = '</table></div>';

            return implode('', $aHtml);
        }
    }
