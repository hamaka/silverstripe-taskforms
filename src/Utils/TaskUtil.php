<?php

    namespace Hamaka\TaskForms\Utils;

    use SilverStripe\Control\Director;
    use SilverStripe\ORM\DB;
    use function array_keys;
    use function implode;
    use function in_array;
    use function intval;
    use function is_string;
    use function method_exists;
    use function print_r;
    use function time;

    /**
     * @todo TaskRunner
     * @todo is er JS toe te voegen die zodra je 1 veld aanpast het execute veld weghaalt? Dus dat je bij een wijziging geforceerd wordt om nog een dry run te starten.
     */

    /**
     * Utility functions to make Silverstripe Tasks
     *
     */
    class TaskUtil
    {
        protected static $aExtraCSS = [];

        const ALT_MESSAGE_TYPE_CHANGED = 'changed'; // class info
        const ALT_MESSAGE_TYPE_COMMENT = 'created';
        const ALT_MESSAGE_TYPE_NOTICE = 'notice'; // class warning
        const ALT_MESSAGE_TYPE_ERROR = 'error'; // class error

        public static function addCSS($sInput = "")
        {
            static::$aExtraCSS[] = $sInput;
        }

        public static function makePretty($bEnablePartyMode = false)
        {
            $aHTML = [];

            if (Director::is_cli()) {
                return false;
            }

            $aHTML[] = "<style>
";

            if ($bEnablePartyMode === true) {
                $aHTML[] = "h1 {
                  font-family: \"Comic Sans MS\", \"Comic Sans\", cursive;
                }


                ";
            }

            if (sizeof(static::$aExtraCSS) > 0) {
                foreach (static::$aExtraCSS as $sCSS) {
                    $aHTML[] = "
" . $sCSS . "
";
                }
            }

            $aHTML[] = "

								body {
									font-family: Arial, sans-serif;
									font-weight: normal;
								}

                pre code {
                    background-color: #eee;
                    border: 1px solid #999;
                    display: block;
                    padding: 20px;
                    color: #000;
                }

                .section--correct {
                    background-color: #d0dcb9;
                }

                .section--warning {
                    background-color: #ffebcc;
                }

                .section--error {
                    background-color: #f45656;
                }

                .section--notice {
                    background-color: #eee;
                }

                .section--ignored {
                    background-color: #faebf8;
                }

                li {
                  font-size: 15px;
                  font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif;
                  line-height: 1.5;
                }

               .warning {
                    color: #ffa717;
                    font-weight: bold;
                    text-decoration: ;
                }

               .success {
                    text-indent: 50px;
                }

               .error {
                    color: #ef1717;
                    font-size: 17px;
                    font-weight: bold;
                }

               .info {
                  color: darkgreen;
                  font-weight: 700;
                }

                th {
                  text-align: left;
                }

                td, th {
                  padding: 10px;
                }

                .fieldlabel {
                  display: inline-block;
                  font-size: 12px;
                  margin-bottom: 5px;
                }

                </style>
            ";

            echo(implode('', $aHTML));
        }

        public static function isDryRun($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed = true)
        {
            // To make things easy we mark a first load as a dry run
            // isFirstLoad can be called stand alone to specify different behaviour on first load
            if (static::isFirstLoadOrLoadWithoutAllRequiredFields($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars) === true) {
                return true;
            }

            if ($bDoesActionNeedToBePreviewed === true && ! TaskUtil::doesRequestContainExecuteConfirmation()) {
                return true;
            }

            return false;
        }

        public static function isLiveRun($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed = true)
        {
            if (static::isFirstLoadOrLoadWithoutAllRequiredFields($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars)) {
                return false;
            }

            if (static::isDryRun($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed)) {
                return false;
            }

            return true;
        }

        public static function doWeOnlyShowPreview($bDoesActionNeedToBePreviewed = true)
        {
            return ! TaskUtil::doesRequestContainExecuteConfirmation();
        }

        public static function isFirstLoadOrLoadWithoutAllRequiredFields($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars)
        {
            return ! TaskUtil::areAllRequiredFieldsSet($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars);
        }

        public static function hydrateVarsFromRequest($aGetKeyToVarMap = [])
        {
            foreach ($aGetKeyToVarMap as $sGetKey => &$oVar) {
                if (isset($_GET[$sGetKey])) {
                    $oVar = $_GET[$sGetKey];
                }
            }
        }

        public static function doesRequestContainExecuteConfirmation()
        {
            if (isset($_GET['execute']) && intval($_GET['execute']) === 1) {
                return true;
            }

            return false;
        }

        public static function areAllRequiredFieldsSet($aFieldNamesAndVars, $aRequiredFieldsNamesAndVars = false)
        {
            static::hydrateVarsFromRequest($aFieldNamesAndVars);

            $bAreAllPresent = true;

            if (count(array_filter(array_keys($aRequiredFieldsNamesAndVars), 'is_string')) > 0) {
                foreach ($aRequiredFieldsNamesAndVars as $sGetKey => &$oVar) {
                    if ( ! is_string($oVar)) {
                        $bAreAllPresent = false;
                    }
                }
            }
            else {
                foreach ($aRequiredFieldsNamesAndVars as $sFieldName) {
                    foreach ($aFieldNamesAndVars as $sGetKey => &$oVar) {
                        if ($sGetKey === $sFieldName) {
                            if ( ! is_string($oVar)) {
                                $bAreAllPresent = false;
                            }
                        }
                    }
                }
            }

            return $bAreAllPresent;
        }

        protected static function getAsString($oMessage)
        {
            if (is_string($oMessage)) {
                return $oMessage;
            }

            return '<pre>' . print_r($oMessage, true) . '</pre>';
        }

        public static function echoNormal($oMessage)
        {
            DB::alteration_message(static::getAsString($oMessage));
        }

        public static function echoGood($oMessage)
        {
            DB::alteration_message(static::getAsString($oMessage), TaskUtil::ALT_MESSAGE_TYPE_CHANGED);
        }

        public static function echoNotice($oMessage)
        {
            DB::alteration_message(static::getAsString($oMessage), TaskUtil::ALT_MESSAGE_TYPE_NOTICE);
        }

        public static function echoError($oMessage)
        {
            DB::alteration_message(static::getAsString($oMessage), TaskUtil::ALT_MESSAGE_TYPE_ERROR);
        }

        public static function echoCommentOnPrev($oMessage)
        {
            DB::alteration_message(static::getAsString($oMessage), TaskUtil::ALT_MESSAGE_TYPE_COMMENT);
        }

        public static function echoSpace()
        {
            echo('<br><br>');
        }

        public static function echoSeparator()
        {
            echo('<hr style="margin-top:20px; margin-bottom: 20px;" >');
        }

        public static function echoHeading($sLabel = '', $iHeadingLevel = 2)
        {
            echo('<h' . intval($iHeadingLevel) . '>' . $sLabel . '</h' . intval($iHeadingLevel) . '>');
        }

        public static function echoFormHTML($aGetKeyToVarMap = [], $aRequiredFieldsNamesAndVars = [], $bDoesActionNeedToBePreviewed = false, $aCustomComponents = [])
        {
            echo(static::getFormHTML($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed, $aCustomComponents));
        }

        public static function getFormHTML($aGetKeyToVarMap = [], $aRequiredFieldsNamesAndVars = [], $bDoesActionNeedToBePreviewed = false, $aCustomComponents = [])
        {
            static::hydrateVarsFromRequest($aGetKeyToVarMap);
            $bIsFirstLoad     = static::isFirstLoadOrLoadWithoutAllRequiredFields($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars);
            $bIsDryRun        = static::isDryRun($aGetKeyToVarMap, $aRequiredFieldsNamesAndVars, $bDoesActionNeedToBePreviewed);
            $bAddExecuteField = $bDoesActionNeedToBePreviewed && ! $bIsFirstLoad && $bIsDryRun;

            $aHTML = [];

            $aHTML[] = '<form action="" method="get" >';

            foreach ($aGetKeyToVarMap as $sGetKey => &$sVar) {
                if ( ! is_string($sVar)) {
                    $sVar = '';
                }

                if (in_array($sGetKey, array_keys($aCustomComponents))) {
                    if (is_string($aCustomComponents[$sGetKey])) {
                        // a component can be passed as string but the downside: the value is not set
                        $aHTML[] = $aCustomComponents[$sGetKey];
                    }
                    elseif (method_exists($aCustomComponents[$sGetKey], 'forTemplate')) {
                        if (method_exists($aCustomComponents[$sGetKey], 'setValue')) {
                            $aCustomComponents[$sGetKey]->setValue($sVar);
                        }

                        $aHTML[] = '<span class=\'fieldlabel\'>' . $sGetKey . ':</span><br/>';
                        $aHTML[] = $aCustomComponents[$sGetKey]->forTemplate() . '<br/><br/>';
                    }
                }
                else {
                    $aHTML[] = '<span class=\'fieldlabel\'>' . $sGetKey . ':</span><br/>';
                    $aHTML[] = '<input type="text" name="' . $sGetKey . '" value="' . $sVar . '" "><br/><br/>';
                }
            }

            if ($bAddExecuteField) {
                //$aHTML[] = '<input type="hidden" name="previewed" value="1">';
                $aHTML[] = '<span class=\'fieldlabel\'>' . static::getExecuteFieldLabel() . ':</span><br/>';
                $aHTML[] = '<input type="text" name="execute"><br/><br/>';
            }

            $aHTML[] = '<input type="hidden" name="cachebuster" value="' . time() . '">';

            if ($bDoesActionNeedToBePreviewed === true && $bIsFirstLoad) {
                $aHTML[] = '<input type="submit" value="Preview">';
            }
            else {
                $aHTML[] = '<input type="submit" value="Submit">';
            }

            $aHTML[] = '</form>';

            return implode('
            ', $aHTML);
        }

        protected static $sExecuteFieldLabel = 'Type 1 to execute the task';

        public static function setExecuteFieldLabel($sInput)
        {
            if (is_string($sInput)) {
                static::$sExecuteFieldLabel = $sInput;
            }
        }

        public static function getExecuteFieldLabel()
        {
            return static::$sExecuteFieldLabel;
        }

        public static function echoResetFormHTML()
        {
            echo(static::getResetFormHTML());
        }

        public static function getResetFormHTML()
        {
            $aHTML = [];

            $aHTML[] = '<form action="" method="get" >';
            $aHTML[] = '<input type="hidden" name="cachebuster" value="' . time() . '">';
            $aHTML[] = '<input type="submit" value="restart">';
            $aHTML[] = '</form>';

            return implode('
            ', $aHTML);
        }

        public static function addConfetti()
        {
            echo '
  <script>var confetti = {
	maxCount: 100,		//set max confetti count
	speed: 2,			//set the particle animation speed
	frameInterval: 15,	//the confetti animation frame interval in milliseconds
	alpha: 1.0,			//the alpha opacity of the confetti (between 0 and 1, where 1 is opaque and 0 is invisible)
	gradient: false,	//whether to use gradients for the confetti particles
	start: null,		//call to start confetti animation (with optional timeout in milliseconds, and optional min and max random confetti count)
	stop: null,			//call to stop adding confetti
	toggle: null,		//call to start or stop the confetti animation depending on whether its already running
	pause: null,		//call to freeze confetti animation
	resume: null,		//call to unfreeze confetti animation
	togglePause: null,	//call to toggle whether the confetti animation is paused
	remove: null,		//call to stop the confetti animation and remove all confetti immediately
	isPaused: null,		//call and returns true or false depending on whether the confetti animation is paused
	isRunning: null		//call and returns true or false depending on whether the animation is running
};


	confetti.start = startConfetti;
	confetti.stop = stopConfetti;
	confetti.toggle = toggleConfetti;
	confetti.pause = pauseConfetti;
	confetti.resume = resumeConfetti;
	confetti.togglePause = toggleConfettiPause;
	confetti.isPaused = isConfettiPaused;
	confetti.remove = removeConfetti;
	confetti.isRunning = isConfettiRunning;
	var supportsAnimationFrame = window.requestAnimationFrame || window.webkitRequestAnimationFrame || window.mozRequestAnimationFrame || window.oRequestAnimationFrame || window.msRequestAnimationFrame;
	var colors = ["rgba(30,144,255,", "rgba(107,142,35,", "rgba(255,215,0,", "rgba(255,192,203,", "rgba(106,90,205,", "rgba(173,216,230,", "rgba(238,130,238,", "rgba(152,251,152,", "rgba(70,130,180,", "rgba(244,164,96,", "rgba(210,105,30,", "rgba(220,20,60,"];
	var streamingConfetti = false;
	var animationTimer = null;
	var pause = false;
	var lastFrameTime = Date.now();
	var particles = [];
	var waveAngle = 0;
	var context = null;

	function resetParticle(particle, width, height) {
		particle.color = colors[(Math.random() * colors.length) | 0] + (confetti.alpha + ")");
		particle.color2 = colors[(Math.random() * colors.length) | 0] + (confetti.alpha + ")");
		particle.x = Math.random() * width;
		particle.y = Math.random() * height - height;
		particle.diameter = Math.random() * 10 + 5;
		particle.tilt = Math.random() * 10 - 10;
		particle.tiltAngleIncrement = Math.random() * 0.07 + 0.05;
		particle.tiltAngle = Math.random() * Math.PI;
		return particle;
	}

	function toggleConfettiPause() {
		if (pause)
			resumeConfetti();
		else
			pauseConfetti();
	}

	function isConfettiPaused() {
		return pause;
	}

	function pauseConfetti() {
		pause = true;
	}

	function resumeConfetti() {
		pause = false;
		runAnimation();
	}

	function runAnimation() {
		if (pause)
			return;
		else if (particles.length === 0) {
			context.clearRect(0, 0, window.innerWidth, window.innerHeight);
			animationTimer = null;
		} else {
			var now = Date.now();
			var delta = now - lastFrameTime;
			if (!supportsAnimationFrame || delta > confetti.frameInterval) {
				context.clearRect(0, 0, window.innerWidth, window.innerHeight);
				updateParticles();
				drawParticles(context);
				lastFrameTime = now - (delta % confetti.frameInterval);
			}
			animationTimer = requestAnimationFrame(runAnimation);
		}
	}

	function startConfetti(timeout, min, max) {
		var width = window.innerWidth;
		var height = window.innerHeight;
		window.requestAnimationFrame = (function() {
			return window.requestAnimationFrame ||
				window.webkitRequestAnimationFrame ||
				window.mozRequestAnimationFrame ||
				window.oRequestAnimationFrame ||
				window.msRequestAnimationFrame ||
				function (callback) {
					return window.setTimeout(callback, confetti.frameInterval);
				};
		})();
		var canvas = document.getElementById("confetti-canvas");
		if (canvas === null) {
			canvas = document.createElement("canvas");
			canvas.setAttribute("id", "confetti-canvas");
			canvas.setAttribute("style", "display:block;z-index:999999;pointer-events:none;position:fixed;top:0");
			document.body.prepend(canvas);
			canvas.width = width;
			canvas.height = height;
			window.addEventListener("resize", function() {
				canvas.width = window.innerWidth;
				canvas.height = window.innerHeight;
			}, true);
			context = canvas.getContext("2d");
		} else if (context === null)
			context = canvas.getContext("2d");
		var count = confetti.maxCount;
		if (min) {
			if (max) {
				if (min == max)
					count = particles.length + max;
				else {
					if (min > max) {
						var temp = min;
						min = max;
						max = temp;
					}
					count = particles.length + ((Math.random() * (max - min) + min) | 0);
				}
			} else
				count = particles.length + min;
		} else if (max)
			count = particles.length + max;
		while (particles.length < count)
			particles.push(resetParticle({}, width, height));
		streamingConfetti = true;
		pause = false;
		runAnimation();
		if (timeout) {
			window.setTimeout(stopConfetti, timeout);
		}
	}

	function stopConfetti() {
		streamingConfetti = false;
	}

	function removeConfetti() {
		stop();
		pause = false;
		particles = [];
	}

	function toggleConfetti() {
		if (streamingConfetti)
			stopConfetti();
		else
			startConfetti();
	}

	function isConfettiRunning() {
		return streamingConfetti;
	}

	function drawParticles(context) {
		var particle;
		var x, y, x2, y2;
		for (var i = 0; i < particles.length; i++) {
			particle = particles[i];
			context.beginPath();
			context.lineWidth = particle.diameter;
			x2 = particle.x + particle.tilt;
			x = x2 + particle.diameter / 2;
			y2 = particle.y + particle.tilt + particle.diameter / 2;
			if (confetti.gradient) {
				var gradient = context.createLinearGradient(x, particle.y, x2, y2);
				gradient.addColorStop("0", particle.color);
				gradient.addColorStop("1.0", particle.color2);
				context.strokeStyle = gradient;
			} else
				context.strokeStyle = particle.color;
			context.moveTo(x, particle.y);
			context.lineTo(x2, y2);
			context.stroke();
		}
	}

	function updateParticles() {
		var width = window.innerWidth;
		var height = window.innerHeight;
		var particle;
		waveAngle += 0.01;
		for (var i = 0; i < particles.length; i++) {
			particle = particles[i];
			if (!streamingConfetti && particle.y < -15)
				particle.y = height + 100;
			else {
				particle.tiltAngle += particle.tiltAngleIncrement;
				particle.x += Math.sin(waveAngle) - 0.5;
				particle.y += (Math.cos(waveAngle) + particle.diameter + confetti.speed) * 0.5;
				particle.tilt = Math.sin(particle.tiltAngle) * 15;
			}
			if (particle.x > width + 20 || particle.x < -20 || particle.y > height) {
				if (streamingConfetti && particles.length <= confetti.maxCount)
					resetParticle(particle, width, height);
				else {
					particles.splice(i, 1);
					i--;
				}
			}
		}
	}

(function() {
	var bConfettiStarted = false;
	 startConfetti();
	 bConfettiStarted = true;
	 setTimeout(function(r){
	   stopConfetti();
	 }, 10000);
})();

                </script>';
        }

        public static function echoArrayAsTable($aData, $bShowHeaders = true, $sLayOut = 'horizontal')
        {
            echo(static::getArrayAsTable($aData, $bShowHeaders, $sLayOut));
        }

        public static function getArrayAsTable($aTableData, $bShowHeaders = true, $sLayOut = 'horizontal')
        {
            if ($sLayOut === 'stacked') {
                return static::getArrayAsStackedTable($aTableData, $bShowHeaders);
            }

            return static::getArrayAsTableHorizontal($aTableData, $bShowHeaders);
        }

        protected static function getArrayAsTableHorizontal($aTableData, $bShowHeaders = true)
        {
            $aHTML   = [];
            $aHTML[] = '<table>';

            $iRowNr = 1;
            foreach ($aTableData as $aRowData) {

                // header
                if ($iRowNr === 1 && $bShowHeaders === true) {
                    $aHTML[] = '<tr>';

                    foreach ($aRowData as $sFieldLabel => $sFieldContent) {
                        $aHTML[] = '<th>' . $sFieldLabel . '</th>';
                    }

                    $aHTML[] = '</tr>';
                }

                // normale regel
                $aHTML[] = '<tr>';

                foreach ($aRowData as $sFieldLabel => $sFieldContent) {
                    $aHTML[] = '<td>' . $sFieldContent . '</td>';
                }

                $aHTML[] = '</tr>';

                ++$iRowNr;
            }

            $aHTML[] = '</table>';

            return implode('', $aHTML);
        }

        protected static function getArrayAsStackedTable($aTableData, $bShowHeaders = true)
        {
            $aHTML   = [];
            $aHTML[] = '<table>';

            $iRowNr = 1;
            foreach ($aTableData as $aRowData) {


                foreach ($aRowData as $sFieldLabel => $sFieldContent) {
                    if ($bShowHeaders) {
                        $aHTML[] = '<tr>';
                        $aHTML[] = '  <th style="text-align: left" ">' . $sFieldLabel . ':</th>';
                        $aHTML[] = '</tr>';
                    }

                    $aHTML[] = '<tr>';
                    $aHTML[] = '  <td>' . $sFieldContent . '</td>';
                    $aHTML[] = '</tr>';
                }

                $aHTML[] = '<tr>';
                $aHTML[] = '  <td>&nbsp;</td>';
                $aHTML[] = '</tr>';

                ++$iRowNr;
            }

            $aHTML[] = '</table>';

            return implode('', $aHTML);
        }
    }
