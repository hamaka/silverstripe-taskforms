<?php

    namespace Hamaka\TaskForms\Tests\Utils;

    use Hamaka\TaskForms\Utils\TaskUtil;
    use ReflectionProperty;
    use SilverStripe\Control\HTTPRequest;
    use SilverStripe\Dev\SapphireTest;
    use SilverStripe\Forms\DropdownField;
    use SilverStripe\i18n\i18n;
    use SilverStripe\PolyExecution\HttpRequestInput;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Input\InputDefinition;
    use Symfony\Component\Console\Input\InputOption;

    /**
     * Class \Hamaka\TaskForms\Tests\Utils\TaskUtilTest
     *
     * Tests the TaskUtil helpers: the form, required fields, dry/live runs, tables, CLI input and CLI output.
     * PHPUnit runs on the command line, so without input the helpers output text; tests that need HTML output set an
     * HttpRequestInput.
     */
    class TaskUtilTest extends SapphireTest
    {
        protected array $aGetBackup = [];

        protected function setUp(): void
        {
            parent::setUp();

            $this->aGetBackup = $_GET;
            $_GET             = [];
            TaskUtil::setInput(null);
            i18n::set_locale('en_US');
        }

        protected function tearDown(): void
        {
            $_GET = $this->aGetBackup;
            TaskUtil::setInput(null);
            (new ReflectionProperty(TaskUtil::class, 'sExecuteFieldLabel'))->setValue(null, null);

            parent::tearDown();
        }

        public function testGetFormHTMLEscapesLabelsNamesAndValues(): void
        {
            $_GET            = ['Name' => '"><script>alert(1)</script>'];
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            $sHtml = TaskUtil::getFormHTML($aGetKeyToVarMap, ['Name']);

            $this->assertStringNotContainsString('<script>', $sHtml);
            $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $sHtml);
        }

        public function testRequiredFieldCanBeListedByKeyOrByLabel(): void
        {
            $_GET            = ['New_FirstName' => 'Ada'];
            $sFirstName      = null;
            $aGetKeyToVarMap = ['New_FirstName' => &$sFirstName];

            $this->assertTrue(TaskUtil::areAllRequiredFieldsSet($aGetKeyToVarMap, ['New_FirstName']));
            $this->assertTrue(TaskUtil::areAllRequiredFieldsSet($aGetKeyToVarMap, ['New FirstName']));
            $this->assertSame('Ada', $sFirstName);

            $_GET       = [];
            $sFirstName = null;
            $this->assertFalse(TaskUtil::areAllRequiredFieldsSet($aGetKeyToVarMap, ['New FirstName']));
        }

        public function testEmptyOrWhitespaceValueCountsAsMissing(): void
        {
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            foreach (['' => false, '   ' => false, '0' => true, 'Ada' => true] as $sValue => $bIsExpectedSet) {
                $_GET = ['Name' => (string)$sValue];
                $this->assertSame($bIsExpectedSet, TaskUtil::areAllRequiredFieldsSet($aGetKeyToVarMap, ['Name']), 'Value: "' . $sValue . '"');
            }
        }

        public function testRequiredFieldsCanBeAMapOfReferences(): void
        {
            $_GET            = ['Name' => 'Ada'];
            $sName           = null;
            $sCity           = null;
            $aGetKeyToVarMap = ['Name' => &$sName, 'City' => &$sCity];

            $this->assertTrue(TaskUtil::areAllRequiredFieldsSet($aGetKeyToVarMap, ['Name' => &$sName]));
            $this->assertFalse(TaskUtil::areAllRequiredFieldsSet($aGetKeyToVarMap, ['Name' => &$sName, 'City' => &$sCity]));
        }

        public function testTaskIsDryRunUntilExecuteIsConfirmed(): void
        {
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            $this->assertTrue(TaskUtil::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, ['Name']));
            $this->assertTrue(TaskUtil::isDryRun($aGetKeyToVarMap, ['Name']));

            $_GET = ['Name' => 'Ada'];
            $this->assertFalse(TaskUtil::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, ['Name']));
            $this->assertTrue(TaskUtil::isDryRun($aGetKeyToVarMap, ['Name']));
            $this->assertFalse(TaskUtil::isLiveRun($aGetKeyToVarMap, ['Name']));

            $_GET = ['Name' => 'Ada', TaskUtil::EXECUTE_FIELD_NAME => '1'];
            $this->assertFalse(TaskUtil::isDryRun($aGetKeyToVarMap, ['Name']));
            $this->assertTrue(TaskUtil::isLiveRun($aGetKeyToVarMap, ['Name']));
        }

        public function testTaskWithoutPreviewIsLiveAsSoonAsRequiredFieldsAreSet(): void
        {
            $_GET            = ['Name' => 'Ada'];
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            $this->assertTrue(TaskUtil::isLiveRun($aGetKeyToVarMap, ['Name'], false));
        }

        public function testFormShowsExecuteCheckboxOnlyOnDryRun(): void
        {
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];
            $sCheckbox       = 'type="checkbox" name="' . TaskUtil::EXECUTE_FIELD_NAME . '" value="1"';

            $sHtmlFirstLoad = TaskUtil::getFormHTML($aGetKeyToVarMap, ['Name'], true);
            $this->assertStringNotContainsString($sCheckbox, $sHtmlFirstLoad);
            $this->assertStringContainsString('>Preview</button>', $sHtmlFirstLoad);

            $_GET        = ['Name' => 'Ada'];
            $sHtmlDryRun = TaskUtil::getFormHTML($aGetKeyToVarMap, ['Name'], true);
            $this->assertStringContainsString($sCheckbox, $sHtmlDryRun);
            $this->assertStringContainsString('Yes, execute the task', $sHtmlDryRun);
            $this->assertStringContainsString('taskforms-form--dry-run', $sHtmlDryRun);
            $this->assertStringContainsString('data-label-checked="Execute task"', $sHtmlDryRun);
            $this->assertStringContainsString('>Preview again</button>', $sHtmlDryRun);

            $sHtmlWithoutPreview = TaskUtil::getFormHTML($aGetKeyToVarMap, ['Name'], false);
            $this->assertStringNotContainsString($sCheckbox, $sHtmlWithoutPreview);
            $this->assertStringContainsString('>Submit</button>', $sHtmlWithoutPreview);
        }

        public function testFormMarksRequiredFieldsAndMissingValuesAfterSubmit(): void
        {
            $sName           = null;
            $sCity           = null;
            $aGetKeyToVarMap = ['Name' => &$sName, 'City' => &$sCity];

            $sHtmlFirstLoad = TaskUtil::getFormHTML($aGetKeyToVarMap, ['Name'], true);
            $this->assertStringContainsString('<label class="taskforms-field__label" for="taskforms-field-Name">Name <span class="taskforms-field__required">required</span></label>', $sHtmlFirstLoad);
            $this->assertStringContainsString('<label class="taskforms-field__label" for="taskforms-field-City">City</label>', $sHtmlFirstLoad);
            $this->assertStringNotContainsString('taskforms-field--missing', $sHtmlFirstLoad, 'No message before the form is submitted');

            $_GET           = ['Name' => '', 'City' => '', TaskUtil::CACHEBUSTER_FIELD_NAME => '1'];
            $sHtmlSubmitted = TaskUtil::getFormHTML($aGetKeyToVarMap, ['Name'], true);
            $this->assertSame(1, substr_count($sHtmlSubmitted, 'taskforms-field--missing'), 'Only the required field gets a message');
            $this->assertStringContainsString('Please fill in this field.', $sHtmlSubmitted);
        }

        public function testFormUsesTitleOfCustomFieldAsLabel(): void
        {
            $sMemberID       = null;
            $aGetKeyToVarMap = ['MemberID' => &$sMemberID];

            $sHtml = TaskUtil::getFormHTML($aGetKeyToVarMap, [], false, ['MemberID' => DropdownField::create('MemberID', 'Member', [1 => 'Ada'])]);

            $this->assertStringContainsString('for="MemberID">Member</label>', $sHtml);
        }

        public function testExecuteFieldLabelIsTranslatedUnlessSet(): void
        {
            i18n::set_locale('nl_NL');
            $this->assertSame('Ja, voer de taak uit', TaskUtil::getExecuteFieldLabel());

            TaskUtil::setExecuteFieldLabel('Yes, rename the member');
            $this->assertSame('Yes, rename the member', TaskUtil::getExecuteFieldLabel());

            TaskUtil::setExecuteFieldLabel(5);
            $this->assertSame('Yes, rename the member', TaskUtil::getExecuteFieldLabel(), 'A label that is not a string is ignored');
        }

        public function testTableShowsReadableValues(): void
        {
            $sHtml = TaskUtil::getArrayAsTable([
                ['Int' => 1, 'Float' => 1.5, 'True' => true, 'False' => false, 'Null' => null, 'Array' => ['a' => 1], 'Html' => '<b>bold</b>'],
            ]);

            $this->assertStringContainsString(
                '<tr><td>1</td><td>1.5</td><td><span class="taskforms-badge taskforms-badge--yes">Yes</span></td><td><span class="taskforms-badge taskforms-badge--no">No</span></td><td></td><td>{&quot;a&quot;:1}</td><td><b>bold</b></td></tr>',
                $sHtml
            );
            $this->assertStringNotContainsString('i:1;', $sHtml);
        }

        public function testSingleValueRowSpansAllColumns(): void
        {
            $sHtml = TaskUtil::getArrayAsTable([['ID' => 1, 'Name' => 'Ada'], 'Total: 1']);

            $this->assertStringContainsString('<td colspan="2">Total: 1</td>', $sHtml);
        }

        public function testTableAppliesRowCssClass(): void
        {
            $aRows = [['Name' => 'Ada', TaskUtil::TABLE_ROW_CSS_CLASS_KEY => 'section--error']];

            $sHtmlHorizontal = TaskUtil::getArrayAsTable($aRows);
            $this->assertStringContainsString('<tr class="section--error"><td>Ada</td></tr>', $sHtmlHorizontal);
            $this->assertStringNotContainsString('<th>css_class</th>', $sHtmlHorizontal);

            $sHtmlStacked = TaskUtil::getArrayAsTable($aRows, true, TaskUtil::TABLE_LAYOUT_STACKED);
            $this->assertStringContainsString('<tr class="section--error">  <td>Ada</td></tr>', $sHtmlStacked);
            $this->assertStringNotContainsString('css_class', $sHtmlStacked);
        }

        public function testTextTableAlignsColumns(): void
        {
            $sText = TaskUtil::getArrayAsTextTable([
                ['ID' => 1, 'Name' => 'Ada', 'Is admin' => true],
                ['ID' => 22, 'Name' => 'Grace', 'Is admin' => false],
            ]);

            $this->assertSame(
                "ID | Name  | Is admin\n" .
                "---+-------+---------\n" .
                "1  | Ada   | Yes\n" .
                "22 | Grace | No\n",
                $sText
            );
        }

        public function testStackedTextTableShowsLabelValueLines(): void
        {
            $sText = TaskUtil::getArrayAsTextTable([['ID' => 1, 'Name' => 'Ada'], 'single value'], true, TaskUtil::TABLE_LAYOUT_STACKED);

            $this->assertSame("ID: 1\nName: Ada\n\nsingle value\n", $sText);
        }

        public function testInputOptionsForFieldsIncludeExecuteWhenPreviewed(): void
        {
            $aOptionNamesPreviewed = array_map(fn(InputOption $oOption): string => $oOption->getName(), TaskUtil::getInputOptionsForFields(['MemberID', 'New_FirstName'], true));
            $aOptionNamesDirect    = array_map(fn(InputOption $oOption): string => $oOption->getName(), TaskUtil::getInputOptionsForFields(['MemberID'], false));

            $this->assertSame(['MemberID', 'New_FirstName', TaskUtil::EXECUTE_FIELD_NAME], $aOptionNamesPreviewed);
            $this->assertSame(['MemberID'], $aOptionNamesDirect);
        }

        public function testValuesFromInputOptionsWinOverRequest(): void
        {
            $oDefinition = new InputDefinition(TaskUtil::getInputOptionsForFields(['Name'], true));
            TaskUtil::setInput(new ArrayInput(['--Name' => 'From input', '--' . TaskUtil::EXECUTE_FIELD_NAME => '1'], $oDefinition));
            $_GET = ['Name' => 'From request', 'Other' => 'Only in request'];

            $sName           = null;
            $sOther          = null;
            $aGetKeyToVarMap = ['Name' => &$sName, 'Other' => &$sOther];
            TaskUtil::hydrateVarsFromRequest($aGetKeyToVarMap);

            $this->assertSame('From input', $sName);
            $this->assertSame('Only in request', $sOther, 'Keys that are not an option still come from the request');
            $this->assertTrue(TaskUtil::doesRequestContainExecuteConfirmation());
        }

        public function testFormTextListsFieldsAsOptions(): void
        {
            $oDefinition = new InputDefinition(TaskUtil::getInputOptionsForFields(['Name', 'City'], true));
            TaskUtil::setInput(new ArrayInput(['--Name' => 'Ada Lovelace'], $oDefinition));

            $sName           = null;
            $sCity           = null;
            $aGetKeyToVarMap = ['Name' => &$sName, 'City' => &$sCity];

            $this->assertSame(
                "Input:\n  --Name='Ada Lovelace'\n  --City=  (required, missing)\n",
                TaskUtil::getFormText($aGetKeyToVarMap, ['Name', 'City'], true)
            );
        }

        public function testFormTextSaysWhenFieldsAreNoOptions(): void
        {
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            $this->assertStringContainsString('getInputOptionsForFields()', TaskUtil::getFormText($aGetKeyToVarMap, ['Name'], true));
        }

        public function testEchoHelpersOutputTextOnCommandLine(): void
        {
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            ob_start();
            TaskUtil::echoHeading('Overview');
            TaskUtil::echoSeparator();
            TaskUtil::echoFormHTML($aGetKeyToVarMap, ['Name'], true);
            TaskUtil::echoResetFormHTML();
            TaskUtil::addConfetti();
            $this->assertFalse(TaskUtil::makePretty());
            $sOutput = ob_get_clean();

            $this->assertStringContainsString("Overview\n========\n", $sOutput);
            $this->assertStringContainsString(str_repeat('-', 60), $sOutput);
            $this->assertStringContainsString('--Name=  (required, missing)', $sOutput);
            $this->assertStringNotContainsString('<', $sOutput);
        }

        public function testEchoHelpersOutputHtmlInBrowser(): void
        {
            TaskUtil::setInput(HttpRequestInput::create(new HTTPRequest('GET', 'dev/tasks/test')));
            $sName           = null;
            $aGetKeyToVarMap = ['Name' => &$sName];

            ob_start();
            $this->assertTrue(TaskUtil::makePretty());
            TaskUtil::echoHeading('Overview');
            TaskUtil::echoFormHTML($aGetKeyToVarMap, ['Name'], true);
            $sOutput = ob_get_clean();

            $this->assertStringContainsString('<style>', $sOutput);
            $this->assertStringContainsString('class="taskforms-back"', $sOutput);
            $this->assertStringContainsString('<h2 class="taskforms-heading">Overview</h2>', $sOutput);
            $this->assertStringContainsString('<form class="taskforms-form" action="" method="get">', $sOutput);
        }
    }
