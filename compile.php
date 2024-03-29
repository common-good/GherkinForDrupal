<?php

/**
 * @program
 * Gherkin compiler
 *
 * Usage: compile.php?lang=programming-language&path=path-to-program-folder
 * Where lang is PHP or JS
 *
 * Create a test for each Gherkin-described program feature
 * Analyze .feature files in each features folder within the program directory or any subdirectory.
 * This program should be run immediately before running any of the tests it creates,
 * to make sure the latest feature descriptions are what we're testing.
 */
 
$SHOWERRORS = TRUE;
error_reporting($SHOWERRORS ? E_ALL : 0); ini_set('display_errors', $SHOWERRORS); ini_set('display_startup_errors', $SHOWERRORS);
date_default_timezone_set(@$_GET['timezone']); // avoid time discrepancies

define('TESTING', 1); // this should always be set to 1
define('NOW', @$_GET['time']);
define('TODAY', strtotime('today', NOW)); // align times, to make tests easier (need timezone of developer for strtotime('today') etc, because this is called indirectly)

list ($compilerPath, $lang, $path) = @$argv ?: ['./', strtoupper(@$_GET['lang']), @$_GET['path']];
if (!in_array($lang, ['PHP', 'JS'])) error('Language parameter (lang) must be PHP or JS.');
define('LANG', $lang);
define('TEST_EXT', '.test' . (LANG == 'JS' ? '.js' : '')); // test file extension
$gherkinPath = dirname($compilerPath);

if (!$path or !$features = findFiles("$path/features", '/\.feature$/', FALSE)) error('No feature files found.');
$ext = strtolower($lang);
if (!$stepsHeader = str_replace("\r", '', file_get_contents("$gherkinPath/steps-header.$ext"))) error("Missing steps header file for $lang.");
if (!file_exists($testDir = "$path/test")) mkdir($testDir);
if (!$testTemplate = file_get_contents("$gherkinPath/test-template.$ext")) error("Missing test template file for $lang.");

$module = strtolower(basename($path));
$Module = ucfirst($module);
$moduleSubs = ['MODULE' => $module, 'MMODULE' => $Module]; // for % replacements in template files
$stepsFilename = "$path/$module.steps" . (LANG == 'JS' ? '.js' : '');
if (file_exists($stepsFilename)) {
  $stepsText = str_replace("\r", '', file_get_contents($stepsFilename));
  if (LANG == 'PHP') include $stepsFilename; // for defs, extra substitutions, and syntax errors may prevent corruption of steps
  if (LANG == 'JS') {
    if (substr($stepsText, -1, 1) != '}') error('Last character of steps file must be "}".');
    $stepsText = substr($stepsText, 0, strlen($stepsText) - 1);
  }
} else $stepsText = strtr2($stepsHeader, $moduleSubs);

$argPatterns = '"(.*?)"|([\-\+]?[0-9]+(?:[\.\,\-][0-9]+)*)|(%[a-z][A-Za-z0-9\-\+]+)'; // what forms the step arguments can take

/** $steps array
 *
 * Associative array of step function information, indexed by step function name
 * Each step is in turn an associative array:
 *   'original' is "new", "changed", or the original function header from the steps file
 *   'english' is the english language description of the step
 *   'toReplace' is text to replace in the steps file function header when the callers change: from the first caller through the function name
 *   'callers' array is the list of tests that use this specific step function
 *   'argCount' is the number of arguments (for new steps only)
 */
$steps = getSteps($stepsText); // global
doFeatures($steps, $features, $module, $testTemplate);
doSteps($steps, $stepsText);
if (LANG == 'JS') $stepsText .= '}';
if (!file_put_contents($stepsFilename, $stepsText)) error("Cannot write stepsfile $stepsFilename.");
/**/ echo "\n\n<br>Updated $stepsFilename -- SUCCESS! Done. " . date('g:ia') . "\n";

// END of program

/**
 * Process features, creating a test file for each.
 */
function doFeatures(&$steps, $features, $module, $testTemplate) {
  global $moduleSubs;
  foreach ($features as $featureFilename) {
    $base = basename($featureFilename);
    $testFilename = str_replace('features/', 'test/', str_replace('.feature', TEST_EXT, $featureFilename));
    $testData = doFeature($steps, $featureFilename);
    $test = strtr2($testTemplate, $testData + ($moduleSubs ?: []));
    file_put_contents($testFilename, $test);
/**/    echo "Created: $testFilename<br>";
  }
}

/**
 * Add or change steps in the steps file code, as appropriate.
 * @param string $stepsText: (MODIFIED) the step file code
 */
function doSteps($steps, &$stepsText) {

  foreach ($steps as $functionName => $step) {
    $varName = LANG == 'PHP' ? '$arg' : 'arg';
    extract($step); // original, english, toReplace, callers, TMB, functionName, argCount
    $newCallers = replacement($callers, @$TMB, $functionName); // (replacement includes function's opening parenthesis)
    if ($original == 'new') {
      for ($argList = '', $i = 0; $i < $argCount; $i++) {
        $argList .= ($argList ? ', ' : '') . $varName . ($i + 1);
      }
      $global = LANG == 'PHP' ? "  global \$testOnly;\n" : '';
      $stepsText .= <<<EOF

/**
 * $english
 *
 * in: {$newCallers}$argList) {
$global  todo;
}

EOF;
    } else $stepsText = str_replace($toReplace, $newCallers, $stepsText);
  }
}

/**
 * Do Feature
 *
 * Get the specific parameters for the feature's tests
 *
 * @param array $steps: (MODIFIED)
 *   an associative array of empty step function objects, keyed by function name
 *   returned: the original array, with unique new steps added (old steps are not duplicated)
 *
 * @param string $featureFilename
 *   feature path and filename relative to module
 *
 * @return associative array:
 *   GROUP: sub-project name (currently unused in template)
 *   FEATURE_NAME: titlecase feature name, with no spaces
 *   FEATURE_LONGNAME: feature name in normal english
 *   FEATURE_HEADER: standard Gherkin feature header, formatted as a comment
 *   TESTS: all the tests and steps
 */
function doFeature(&$steps, $featureFilename) {
  global $firstScenarioOnly, $FEATURE_NAME, $FEATURE_LONGNAME;
  global $skipping;     
  $GROUP = basename(dirname(dirname($featureFilename)));
  $FEATURE_NAME = strtr(basename($featureFilename), ['.feature' => '', '-' => '']);
  $FEATURE_LONGNAME = $FEATURE_NAME; // default English description of feature, in case it's missing from feature file
  $FEATURE_HEADER = '';
  $TESTS = '';
  $SETUP_LINES = '';
  $skipping = FALSE;
  
  $lines = explode("\n", file_get_contents($featureFilename));

  // Parse into sections and scenarios
  $section_headers = explode(' ', 'Feature Variants Setup Scenario');
  $sections = $scenarios = array();
  $variantGroups = array();

  while (!is_null($line = array_shift($lines))) {
    if (!($line = trim($line))) continue; // ignore blank lines
    if (substr($line, 0, 1) == '#') continue; // ignore comment lines
    $any = preg_match('/^([A-Z]+)/i', $line, $matches);
    $word1 = $word1_original = $any ? $matches[1] : '';
    $tail = trim(substr($line, strlen($word1) + 1));

    if ($word1 == 'Skip' or $word1 == 'Resume') {
      $skipping = ($word1 == 'Skip');
    } elseif (@$skipping) {
      continue;
    } elseif (in_array($word1, $section_headers)) {
      $state = $word1;
      switch ($word1) {
        case 'Feature':
          $FEATURE_HEADER .= "//\n// $line\n";
          $FEATURE_LONGNAME = $tail;
          break;
        case 'Scenario': 
          $testFunction = 'test' . (preg_replace("/[^A-Z]/i", '', ucwords($tail))); 
          $scenarios[$testFunction] = array($line);
          break;
        case 'Variants':
          $variantGroups[] = $variantCount = count(@$sections['Variants'] ?: []);
          if (@$sections['Setup'] and !isset($firstVariantAfterSetup)) $firstVariantAfterSetup = $variantCount;
      }
    } elseif ($state == 'Scenario') {
      $scenarios[$testFunction][] = $line;
    } else $sections[$state][] = $line;
  }

  foreach (@$sections['Feature'] ?: [] as $line) $FEATURE_HEADER .= "//   $line\n"; // parse features

  $variants = parseVariants(@$sections['Variants']); // if empty, return a single line that will get replaced with itself
  if (!isset($firstVariantAfterSetup)) $firstVariantAfterSetup = count($variants); // in case all variants are pre-setup
  if (!@$variantGroups) $variantGroups = array(0);
  $g9 = count($variantGroups);
  $variantGroups[] = count($variants); // point past the end of the last group (for convenience)

  for ($g = 0; $g < $g9; $g++) { // for each variant group, parse setups and scenarios with all their variants
    $start = $variantGroups[$g]; // pointer to first line of variant group
    $next = $variantGroups[$g + 1]; // pointer past last line of variant group
    $preSetup = ($start < $firstVariantAfterSetup); // whether to make changes to setup steps as well as scenarios
    for ($i = $start + ($start > 0 ? 1 : 0); $i < $next; $i++) { // for each variant in group (do unaltered scenario only once)
      if ($i == 0 or $preSetup) if (@$sections['Setup']) $SETUP_LINES .= doSetups($steps, $sections['Setup'], $variants, $start, $i);
      foreach ($scenarios as $testFunction => $lines) $TESTS .= doScenario($steps, $testFunction, $lines, $variants, $start, $i);
    }
  }

  return compact(ray('GROUP,FEATURE_NAME,FEATURE_LONGNAME,FEATURE_HEADER,SETUP_LINES,TESTS'));
}

function doSetups(&$steps, $lines, $variants, $start, $i) {
  adjustLines($lines, $variants, $start, $i); // adjust for current variant
  $scene = parseScenario($steps, 'featureSetup', $lines);
  if (LANG == 'PHP') return <<< EOF
    case($i):
$scene
    break;
EOF;

  if (LANG == 'JS') return $scene;
}

function doScenario(&$steps, $testFunction, $lines, $variants, $start, $i) {
  adjustLines($lines, $variants, $start, $i); // adjust for current variant
  $line = array_shift($lines); // get the original Scenario line back
  $scene = parseScenario($steps, $testFunction, $lines);
  $lineQuoted = str_replace("'", "\\'", $line);
  if (LANG == 'PHP') return <<<EOF

  // $line
  public function {$testFunction}_$i() {
    global \$testOnly;
    \$this->setUp(__FUNCTION__, $i);
$scene  }
EOF;

  if (LANG == 'JS') return <<<EOF

  it('$lineQuoted', function () {
$scene  });

EOF;

}

function adjustLines(&$lines, $variants, $start, $i) {
  if ($i > $start) foreach ($lines as $key => $line) $lines[$key] = strtr($line, rayCombine($variants[$start], $variants[$i]));
}

function parseVariants($lines) {
  if (!$lines) return [[1]];
  if (substr(@$lines[0], -1, 1) != '*') error('Missing star at end of first Variants line (things to replace).');
  $lines[0] = substr($lines[0], 0, strlen($lines[0]) - 1); // discard the star
  while (substr(trim(@$lines[0]), 0, 1) == '|') {
    $line = squeeze(preg_replace('/ *\| */', '|', trim(array_shift($lines))), '|');
    $result[] = explode('|', $line);
  }
  return @$result ?: [];
}

/**
 * Find Files
 *
 * Return an array of files matching the given pattern.
 *
 * @param string $path (optional, defaults to current directory)
 *   the directory to search
 *
 * @param string $pattern (optional, defaults to all files)
 *   return filenames matching this pattern
 *
 * @param array $result (optional)
 *   partial results. if this array is supplied, then recurse subdirectories
 *
 * @return
 *   an array of filenames, qualified by path (including the initial directory $path)
 */
function findFiles($path = '.', $pattern = '/./', $result = '') {
  if (!($recursed = is_array($result))) $result = array();
  if (!is_dir($path)) error('No features folder found for that module.');
  $dir = dir($path);
  
  while ($filename = $dir->read()) {
    if ($filename == '.' or $filename == '..') continue;
    $filename = "$path/$filename";
    if (is_dir($filename) and $recursed) $result = findFiles($filename, $pattern, $result);
    if (preg_match($pattern, $filename)){
      $result[] = $filename;
    }
  }
  return $result;
}

/**
 * Get steps
 *
 * Given the text of the steps file, return an array of steps (see $steps)
 *
 */
function getSteps($stepsText) {
  global $module;
  $stepKeys = ray('original,english,toReplace,callers,functionName');
  $pattern = ''
  . '^/\\*\\*\\s?$\\s'
  . '^ \\* ([^\*]*?)\\s?$\\s'
  . '^ \\*\\s?$\\s'
  . '^ \\* in: ((.*?)\\s?$\\s'
  . '^ \\*/\\s?$\\s'
  . (LANG == 'PHP'
     ? '^function (.*?)\()'
     : '^this\.(.*?) = function \()');
  
  preg_match_all("~$pattern~ms", $stepsText, $matches, PREG_SET_ORDER);
///  if (!$matches) die(print_r(compact('stepsText','pattern'), 1));
  $steps = [];
  foreach ($matches as $step) {
    $step = rayCombine($stepKeys, $step);
//    $step['callers'] = explode("\n *     ", $step['callers']); // add to the list, but don't delete
    $step['callers'] = array(); // rebuild this list every time
    $steps[$step['functionName']] = $step; // use the function name as the index for the step
  }
  return $steps;
}

/**
 * Replacement text
 *
 * When updating an existing step function, replace the header with this.
 * (guaranteed to be unique for each step)
 */
function replacement($callers, $TMB, $functionName) {
  foreach ($callers as $key => $func) $callers[$key] = $TMB[$func] . ' ' . $callers[$key];
  $callers = join("\n *     ", $callers);
  return LANG == 'PHP'
  ? "$callers\n */\nfunction $functionName("
  : "$callers\n */\nthis.$functionName = function (";
}

/**
 * Parse a Step line and return an array of step function arguments.
 */
function getArgs($line) {
// $line = 'And next random code is "%whatever"';
  global $argPatterns;
  preg_match_all("/$argPatterns/ms", $line, $matches);
  foreach ($matches[0] as $arg) $args[] = fixArg(squeeze($arg, '"'), TRUE);
///  error(print_r(compact('line','argPatterns','matches', 'arg', 'args'), 1));
  return @$args ?: [];
}

/**
 * Fix one step function argument by replacing any magic variables.
 * @param string $arg: the argument to process
 * @param bool $quote: <wrap the argument in quotes unless it is a number>
 * @param bool $arrayOk: <if the result is an array, return it as an array, rather than a var_export>
 */
function fixArg($arg, $quote = FALSE, $arrayOk = FALSE) {
  global $specialSubs;
  global $standardSubs; // static

  $arg = trim($arg);
  if (is_array($arg = getArrayArg($arg))) return $arrayOk ? $arg : var_export($arg, TRUE);
  $arg = doConstants($arg); // replace any defined constants
  $arg = strtr($arg, @$standardSubs ?: ($standardSubs = standardSubs()));
  $arg = strtr($arg, @$specialSubs ?: []); // if any
  $arg = timeSubs($arg);
  
  if (strpos($arg, '%(') !== FALSE) { // evaluate %(expression), which must currently be at end of arg
    try {
      $arg = preg_replace_callback('/%\((.*?)\)$/', function($m) {
        return eval("return $m[1];");
      }, $arg0 = $arg);
    } catch (Exception $e) {$arg = '';}
    if ($arg === '') error("Bad expression in arg: $arg0");
  }
  
  if (preg_match('/^%[^a-z\(]/i', $arg)) return $arg; // starts with percent but not a parameter
  if (substr($arg, 0, 1) == '%') return $arg; // allow this to be handled dynamically by defining parameters as Given
//  error("Unhandled percent arg = $arg" . print_r(debug_backtrace(),1));

  return (!$quote or preg_match('/^(0|[1-9]\\d*)$/', $arg)) ? $arg : ("'" . str_replace("'", "\\'", $arg) . "'"); // avoid implied octal (integers starting with 0)
}

/**
 * Return one row of a matrix argument, as an array.
 * @param string $line: the line to parse (starting with '|', spaces already trimmed)
 */
function matrixRow($line) {
  if (substr($line, -1, 1) != '|') error('Missing closing vertical bar on line: "!line".', compact('line'));
  $line = squeeze($line, '|');
  $line = str_replace("\t", '  ', $line); // make sure we don't get confused by spurious (tab) white space
  $line = str_replace('%|', "\t", $line); // temporarily hide literal vertical bar as a tab
  $res = explode('|', $line);
  foreach ($res as $i => $v) {
    $v = str_replace("\t", '|', $v); // put the bar back (if any)
    $res[$i] = fixArg(trim($v), FALSE, TRUE);
  }
  return $res;
}

/**
 * See if the next few lines represent a matrix argument using the following syntax, and handle it:
 *   | a1     | b1     | c1     |
 *   | a2     | b2     | c2     |
 *   | a3     | b3     | c3     |
 * Any one or more spaces next to vertical bars are ignored.
 * If the first line has a single star after the final bar, it is treated as the field list of an associative array.
 * If the first line has a double star after the final bar, the field names are the first item on each following line.
 * If either of those (* or **) markers is followed by a one ("1"), the associative array is returned, not an array of them.
 * 
 * @param array $lines: the remaining lines of the feature file
 *                      (RETURNED IMPLICIT) the remaining lines of the feature file, after handling the arg
 * @param array $matrixLines: (RETURNED IMPLICIT) the original interpreted lines
 * @return the matrix argument, printable (empty if this is not a matrix argument)
 */
function matrixArg(&$lines, &$matrixLines) {
  $assocV = $assocH = $single = FALSE;
  $res = $matrixLines = [];
  while ($lines and substr(trim($lines[0]), 0, 1) == '|') {
    $matrixLines[] = $line = trim(array_shift($lines));
    if (!$res) { // first line
      $single = lineEnds('1', $line);
      $assocV = lineEnds('**', $line);
      $assocH = lineEnds('*', $line);
    }
    $row = matrixRow($line);
    if (!$fldCount = count($row)) error('Bad multiline argument syntax: ' . $line);
    if (@$xfldCount and $fldCount != $xfldCount) error('Your field count is off in line: ' . $line);
    $xfldCount = $fldCount;
//    if ($assocH and !$res) foreach ($row as $k) if 
    $res[] = ($assocH and $res) ? rayCombine($res[0], $row) : $row;
  }
  if ($assocH) unset($res[0]); // discard the horizontal array's key array (it's included in each row)
  if (!$res) return ''; else $res = array_values($res);
  if ($assocV) { // interpret vertical array of records
    $rowCnt = count($res[0]);
    $new = [];
    foreach ($res as $one) {
      $k = $one[0];
      for ($i = 1; $i < $rowCnt; $i++) $new[$i - 1][$k] = $one[$i];
    }
    $res = $new;
  }
  if ($single) {
    if (count($res) > 1) error('An object cannot be an array of objects, in multiline argument ending with line: ' . $line);
    $res = $res[0];
  }
  
  return LANG == 'JS' ? jsonEncode($res) : var_export($res, TRUE);
}

function lineEnds($s, &$line) {
  $len = strlen($s);
  if (substr($line, -$len) == $s) {
    $line = trim(substr($line, 0, strlen($line) - $len));
    return TRUE;
  } else return FALSE;
}

/**
 * Standard Subtitutions
 *
 * Set some common parameters that will remain constant throughout the Scenario
 * These may or may not get used in any particular Scenario, but it is convenient to have them always available.
 */
function standardSubs() {
  $subs = $randoms = [];
  for ($i = 3; $i > 0; $i--) $randoms[] = "%whatever$i";
  for ($i = 3; $i > 0; $i--) $randoms[] = "%random$i";
  $randoms[] = '%whatever';
  $randoms[] = '%random';
  for ($i = 5; $i > 0; $i--) $randoms[] = "%number$i"; // phone numbers

  foreach ($randoms as $k) {
    while (in_array($r = substr($k, 0, 7) == '%number' ? randomPhone() : randomString(), $subs));
    $subs[$k] = $r;
  }
  foreach ([20, 32] as $i) $subs["%whatever$i"] = randomString($i);
  $subs['%_'] = ' ';

  return $subs;
}

/**
 * Return the date, formatted as desired.
 * @param string $s: the string containing replaceable time/date variables
 * @param string $fmt: what fmtDt format to use (none if empty)
 * @param int $time: base *nix time (defaults to now)
 */ 
function subAgo($s, $fmt = '', $time = NOW) {
  if (!preg_match('/(%[a-z0]+)((-\d+|\+\d+)([a-z]+))?/i', $s, $m)) error("Bad time sub: $s (fmt = $fmt)");
  list ($all, $a, $mod, $n, $p) = @$m[2] ? $m : [$m[0], $m[1], '+0d', '+0', 'd'];

  $periods = array('min' => 'minutes', 'n' => 'minutes', 'h' => 'hours', 'd' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years');
  $period = $periods[$p];
  $time0 = $time;
  $time = strtotime("$n $period", $time);
  $when = $fmt ? fmtDt($time, $fmt) : $time;
///  if ($fmt == '%Y%m%d') print_r(compact(explode(' ', 's fmt time all a mod n p m period time0 when')));
  return str_replace($all, $when, $s);
}

/**
 * Interpret complex time substitutions -- a named date format, dash, how long ago
 * For example, %dmy-3d means 3 days ago in "dmy" format
  for ($i = 2; $i <= 5; $i++) $specialSubs["%chunk$i"] = R_CHUNK * $i;
  for ($i = 1; $i <= 3; $i++) $specialSubs["%id$i"] = mt_rand(r\cgfId() + R_REGION_MAX, PHP_INT_MAX);
//  $line = str_replace('%last_qid', r\qid(r\Acct::nextId() - 1), $line);
 */
function timeSubs($s) {
  $fmts = [
    'ymd' => 'yyyy-MM-dd',
    'dmy' => 'ddMMMyyyy',
    'dm' => 'dd-MMM',
    'mdy' => 'MM/dd/yy',
    'mdY' => 'MM/dd/yyyy',
    'md' => 'MMM dd',
    'mY' => 'MMM yyyy',
    'lastmy' => 'MMMyyyy',
    'lastmd' => 'MMM dd',
    'lastmdy' => 'MM/dd/yy',
    'lastm' => '',
    'thism' => '',
    'todayn' => 'yyyyMMdd',
    'today' => '', // deprecated (use now)
    'yesterday' => '',
    'tomorrow' => '',
    'now' => '',
    'daystart' => '', // deprecated (use now0)
    'yearAgo' => '',
    'monthAgo' => '',
  ];
  $times = [
    'yesterday' => strtotime('-1 day', NOW),
    'tomorrow' => strtotime('+1 day', NOW),
    'daystart' => TODAY,
    'yearAgo' => strtotime('-1 year', NOW),
    'monthAgo' => strtotime('-1 month', NOW),
    'lastm' => Monthday1(Monthday1() - 1),
    'thism' => Monthday1(),
  ];
  foreach (['lastmy', 'lastmd', 'lastmdy'] as $k) $times[$k] = $times['lastm'];
  
  foreach ($fmts as $k => $fmt) {
    while (($i = strpos($s, "%$k")) !== FALSE) {
      $tm = @$times[$k] ?: NOW;
      if (strpos($s, "%$k" . '0') === $i) $tm = strtotime('today', $tm); // "0" means use start of day
      $s = subAgo($s, $fmt, $tm); // might have multiple variations
    }
  }
  return $s;
}

function randomPhone() {return '+1' . mt_rand(2, 9) . randomString(9, '9');}

function starts($s, $starts, $noCase = FALSE) {
  $s = @$s . ''; // allow null
  if ($noCase) list ($starts, $s) = [strtolower($starts), strtolower($s)];
  return (substr($s, 0, strlen($starts)) == $starts);
}

function t($s) {return $s;} // stand-in for translation wrapper

/**
 * Random String Generator
 *
 * int $len: length of string to generate (0 = random 1->50)
 * string $type: ?=any printable 9=digits A=letters
 * return semi-random string with no single or double quotes in it (but maybe spaces)
 */
function randomString($len = 0, $type = '?'){
  if (!$len) $len = mt_rand(1, 50);

 	$symbol = '-_~=+;!@#^*(){}[]<>.?\' '; // no double percents, quotes, commas, vertical bars, or ampersands (messes up args or URL parameters)
  $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $lower = 'abcdefghijklmnopqrstuvwxwz';
  $digits = '0123456789';
  
  $chars = $upper . $lower . $digits . $symbol;
  if ($type == '9') $chars = $digits;
  if ($type == 'A') $chars = $upper . $lower;
  
  for($s = ''; $len > 0; $len--) $s .= $chars[mt_rand(0, strlen($chars)-1)];
  $s = str_replace('=>', '->', $s); // don't let it look like a sub-argument
//  $s0 = preg_replace('/[%@][A-Z]/e', 'strtolower("$0")', $s); // percent and ampersand occasionally look like substitution parameters
  $s = preg_replace_callback('/[%@][A-Z]/', function($m) {return strtolower($m[0]);}, $s); // percent and commercial "at" occasionally look like substitution parameters
  return($s); //  return str_shuffle($s); ?
}

function newStepFunction($original, $testFuncQualified, $english, $isThen, $tail) {
  global $argPatterns;
  $callers = array($testFuncQualified);
  $TMB = [$testFuncQualified => $isThen ? 'TEST' : 'MAKE'];
  preg_match_all("/$argPatterns/ms", $tail, $matches);
  $argCount = @$matches[1] ? count($matches[1]) : 0;
  return compact(ray('original,english,callers,TMB,argCount'));
}

function fixStepFunction(&$funcArray, $testFunction, $testFuncQualified, $english, $isThen, $tail, $errArgs) {
  if (!@$funcArray) return $funcArray = newStepFunction('new', $testFuncQualified, $english, $isThen, $tail);
  
  if(($old_english = @$funcArray['english']) != $english) error(
    "<br>You tried to redefine step function \"!stepFunction\". "
    . "Delete the old one first.<br>\n"
    . "Old: $old_english<br>\n"
    . "New: $english<br>\n"
    . "  in Feature: !FEATURE_LONGNAME<br>\n"
    . "  in Scenario: $testFunction<br>\n"
    . "  in Step: !line<br>\n", 
    $errArgs
  );
  
  if ($funcArray['original'] != 'new') $funcArray['original'] = 'changed';
  if (!in_array($testFuncQualified, $funcArray['callers'])) {
    $funcArray['callers'][] = $testFuncQualified;
    $funcArray['TMB'][$testFuncQualified] = $isThen ? 'TEST' : 'MAKE';
  } else {
    $TMB_changes = $isThen ? array('MAKE' => 'BOTH') : array('TEST' => 'BOTH');
///    print_r(compact('TMB_changes','isThen','testFuncQualified') + array('zot'=>$funcArray['TMB'][$testFuncQualified]));
    $funcArray['TMB'][$testFuncQualified] = strtr($funcArray['TMB'][$testFuncQualified], $TMB_changes);
  }
  return $funcArray;
}

function ray($s, $m = ',') {return explode(',', $s);}

function strtr2($string, $subs, $prefix = '%') {
  foreach($subs as $from => $to) $string = str_replace("$prefix$from", $to, $string);
  return $string;
}

/**/ function error($message, $subs = array()) {die(strtr2("\n\nERROR (See howto.txt): $message.", $subs, '!'));}

function expect($bool, $message) {
  global $FEATURE_NAME;
  if(!$bool) error(@$FEATURE_NAME . ": $message");
}

/**
 * Squeeze a string
 *
 * If the first and last char of $string is $char, shrink the string by one char at both ends.
 */
function squeeze($string, $char) {
  $first = substr($string, 0, 1);
  $last = substr($string, -1);
  return ($first == $char and $last == $char)? substr($string, 1, strlen($string) - 2) : $string;
}

function jsonEncode($s) {return json_encode($s) ?: json_encode(purify($s));} // , JSON_UNESCAPED_SLASHES
function fmtDate($time = NOW, $numeric = FALSE) {return fmtDt($time, $numeric ? 'MM/dd/yyyy' : 'dd-MM-yyyy');}

function purify($s) {
  if (is_array($s)) {
    foreach ($s as $key => $value) $s[$key] = purify($value);
    return $s;
  } else return preg_replace('/( [\x00-\x7F] | [\xC0-\xDF][\x80-\xBF] | [\xE0-\xEF][\x80-\xBF]{2} | [\xF0-\xF7][\x80-\xBF]{3} ) | ./x', '$1', $s);
}

/**
 * Translate constant parameters in a string.
 * @param string $string: the string to fix
 * @return string: the string with constant names (preceded by %) replaced by their values
 * Constants must be uppercase and underscores -- and digits as long as a digit isn't first
 * (for example, if A_TIGER is defined as 1, %A_TIGER gets replaced with "1")
 */
function doConstants($string) {
  preg_match_all("/%([A-Z_]+[A-Z0-9_]*)/ms", $string, $matches);
  foreach ($matches[1] as $one) if (defined($one)) $map["%$one"] = constant($one);
  return strtr($string, @$map ?: []);
}

/**
 * Parse an in-line array parameter (especially useful within a vertical bar-delimited array)
 */
function getArrayArg($arg) {
  if (!strpos($arg, '=>')) return $arg;
  foreach (explode(',', $arg) as $row) {
    if (strpos($row, '=>') === FALSE) error("bad subvalue syntax: $row");
    list ($k, $v) = explode('=>', $row);
    $new[$k] = fixArg($v);
  }
  return @$new ?: [];
}

function parseScenario(&$steps, $testFunction, $lines) { 
  global $argPatterns, $module, $FEATURE_NAME, $FEATURE_LONGNAME, $skipping;
  $result = $state = '';

  while (!is_null($line = array_shift($lines))) {
    $any = preg_match('/^([A-Z]+)/i', $line, $matches);
    $word1 = $any ? $matches[1] : '';
    $tail = trim(substr($line, strlen($word1) + 1));
    if (in_array($word1, array('Given', 'When', 'Then', 'And'))) {
      $isThen = (int) ($word1 == 'Then' or ($word1 == 'And' and $state == 'Then'));
      if ($word1 == 'And') $word1 = 'And__'; else $state = $word1;
      if ($word1 == 'When' or $word1 == 'Then') $word1 .= '_';

      $args = getArgs($line);
      $english = preg_replace("/$argPatterns/ms", '(ARG)', $tail);
      if ($matrixArg = matrixArg($lines, $matrixLines)) {
        $args[] = $matrixArg;
        $matrixLines = "\n" . join("\n", $matrixLines);
        $english .= ' (ARG)';
      } else $matrixLines = '';
      $args = join(', ', $args);
      $stepFunction = lcfirst(preg_replace("/$argPatterns|[^A-Z]/msi", '', ucwords($tail)));
      $step = str_replace("'", "\\'", "$line$matrixLines");
      $result .= LANG == 'PHP'
      ? "    list(\$testOnly, \$this->state, \$this->step, \$this->func) = [$isThen, '$state', '$step', '$stepFunction'];\n    expect($stepFunction($args));\n"
      : "    steps.testOnly = $isThen;\n    steps.state = '$state';\n    steps.func = '$stepFunction';\n    expect(steps.$stepFunction($args)).toBe(true);\n";
      
      $testFuncQualified = str_replace('- test', '', str_replace('- feature', '', "$FEATURE_NAME - $testFunction"));
      $errArgs = compact(ray('stepFunction,FEATURE_LONGNAME,line')); // for error reporting, just in case 
///      print_r(compact('stepFunction') + ['step' => $steps[$stepFunction]]);
      fixStepFunction($steps[$stepFunction], $testFunction, $testFuncQualified, $english, $isThen, $tail, $errArgs);
    } elseif ($word1 == 'Skip' or $word1 == 'Resume') {
      $skipping = ($word1 == 'Skip'); // might call And skip 
    }
  }
 
  return $result;
}

/**
 * Return array_combine of the two parameters, after checking for length mismatch.
 */
function rayCombine($a, $b) {
/**/  if (count($a) != count($b)) error("Length mismatch combining arrays: \n" . print_r($a, 1) . "\n" . print_r($b, 1));
  return array_combine($a, $b);
}

function monthDay1($time = NOW) {return strtotime(fmtDt($time, '1MMMyyyy'));}

/**
 * Return a formatted date or date/time.
 * See format options here: http://framework.zend.com/manual/1.12/en/zend.date.constants.html#zend.date.constants.selfdefinedformats
 */
function fmtDt($dt = NOW, $fmt = DATE_FMT) {
  global $dtFormatter; if (!$dtFormatter) $dtFormatter = new IntlDateFormatter('en_US', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
  datefmt_set_pattern($dtFormatter, $fmt ?: DATE_FMT);
  return strtr($dtFormatter->format($dt), ['AM'=>'am', 'PM'=>'pm']);
}
