<?php
//http://localhost/devcore/rcredits/test?module=rcredits/rsms&menu=1
use rCredits\Util as u;
global $okAll, $noAll; $okAll = $noAll = 0; // overall results counters
global $the_menu, $the_feature, $the_scene, $the_variant; // these allow for arbitrarily selective testing
global $resumeAt; // tracks where we left off on previous run
global $programPath; $programPath = $_SERVER['REDIRECT_URL'];
define('TESTING', 1); // use this to activate extra debugging statements (if (@TESTING==1))

$cmdline = $_SERVER['QUERY_STRING'];
if (strpos($cmdline, 'menu=1') or strpos($cmdline, 'restart=1')) {
  u\deb("zapping resume cache, cmdline=$cmdline");
  cache_set('t_resume', FALSE);
  cache_set('t_messages', FALSE);
}

$args = array();
if ($resume = @cache_get('t_resume')->data) {
  u\deb('extracting resume data: '.print_r($resume, 1));
  extract($resume); // q, okAll, the_menu, etc. and resumeAt
}
if (!@$q) $q = $cmdline; // get query string from cache if possible
if ($q) {
  foreach (explode('&', $q) as $one) { // gotta do it the long way, because Drupal suppresses $_POST
    list ($key, $value) = explode('=', $one);
    $args[$key] = $value;
  }
}
extract(u\just('menu module feature scene variant', $args), EXTR_PREFIX_ALL, 'the'); // overwrite resume, as specified
//if (@$the_scene) $the_variant = 0;
$modules = @$the_module ? array($the_module) : array('rcredits/rsms', 'rcredits/rsmart', 'rcredits/rweb'); // and admin
u\deb("top of test.php: okAll=$okAll modules=" . print_r($modules, 1));

foreach($modules as $module) doModule($module);
if (!@$the_menu) if (count($modules) > 1) report('OVERALL', $okAll, $noAll);
u\deb("*** normal finish, zapping resume cache\n\n\n");
cache_set('t_resume', FALSE); // finished normally, so no need to resume
cache_set('t_messages', FALSE);

// END OF PROGRAM

// SMS: OpenAnAccountForTheCaller AbbreviationsWork ExchangeForCash GetHelp GetInformation Transact Undo OfferToExchangeUSDollarsForRCredits
// Smart: Startup IdentifyQR Transact UndoCompleted UndoPending UndoAttack Insufficient Change
// Web: Signup

//  $features = array('UndoPending'); // uncomment to run just one feature (test set)
//  $the_scene = 'testTheCallerAsksToPayAMemberId'; // uncomment to run just one test scenario
//  $the_variant = 0; // uncomment to focus on a single variant (usually 0)

/**
 * Run tests for one module
 */
function doModule($module) {
  global $ok, $no, $fails, $okAll, $noAll, $the_feature, $the_menu, $programPath;
  global $base_url;
  $fails = $ok = $no = 0; // results counters

  $moduleName = strtoupper(basename($module));
  $path = __DIR__ . "/../$module"; // relative path from test program to module directory
  $compilerPath = "$base_url/sites/all/modules/gherkin/compile.php?module=$module";
  $compilation = file_get_contents($compilerPath); // recompile tests first
  if (strpos($compilation, 'ERROR:') !== FALSE) {
    report($moduleName, 0, "<a href='$compilerPath'>compile error</a>", $module);
    return;
  }
  $features = str_replace("$path/features/", '', str_replace('.feature', '', findFiles("$path/features", '/.*\.feature$/')));
  if (@$the_feature) $features = array($the_feature);
  $link = testLink('ALL', $module);
  
  if (@$the_menu) { // just show the choices
    $menu = array();
    foreach ($features as $feature) $menu[] = testLink($feature, $module, $feature);
    insertMessage("<h1>$moduleName: $link</h1>" . join('<br>', $menu));
  } else {
    foreach ($features as $feature) doTest($module, $feature);
    $featureLink = @$the_feature ? ' (' . testLink($feature, $module, $feature) . ')' : '';
    report($moduleName . $featureLink, $ok, $no, $module);
  }
}  

function doTest($module, $feature) {
  global $results, $user, $the_menu, $the_module, $the_feature, $the_scene, $the_variant, $resumeAt, $skipToStep;
  global $okAll, $noAll;
  
  if (@$resumeAt and strpos($resumeAt, "$module:$feature:") === FALSE) {
  u\deb("skipping $module:$feature: resumeAt=$resumeAt");
    return; // not to the right feature yet
  }
  include ($feature_filename = __DIR__ . "/../$module/tests/$feature.test");

  $featureLink = testLink($feature, $module, $feature);
  $classname = basename($module . $feature);
  $t = new $classname();
  $s = file_get_contents($feature_filename);
  preg_match_all('/function (test.*?)\(/sm', $s, $matches);

  foreach ($matches[1] as $one) {
    list ($scene, $variant) = explode('_', $one);
    if (@$the_scene) if ($scene != $the_scene) continue;
    if (@$the_variant !== '') if ($variant != $the_variant) continue;

    if ("$module:$feature:$one" == @$resumeAt) u\deb('resuming now'); elseif (@$resumeAt) u\deb("skipping $module:$feature:$one");

    if ("$module:$feature:$one" == @$resumeAt) {
      $resumeAt = FALSE; // don't skip any more modules, scenes, or features after this one
    } elseif (@$resumeAt) continue; // skipping until scene where we left off

    u\deb("DOING $module:$feature:$one");
    u\deb("saving resume point: $module:$feature:$one");
    $q = $_SERVER['QUERY_STRING'];
    $vars = compact('q', 'okAll', 'noAll', 'the_menu', 'the_module', 'the_feature', 'the_scene', 'the_variant')
    + array('resumeAt' => "$module:$feature:$one");
    cache_set('t_resume', $vars); // save in case we need to resume
    
    $results = array('PASS!');
    $t->$one(); // run one test
    
    // Display results intermixed with debugging output, if any (so don't collect results before displaying)
    $link = testLink($one, $module, $feature, $scene, $variant);
    $results[0] .= " [$featureLink] $link";
    $results[0] = color($results[0], 'darkkhaki');
    \drupal_set_message(join(PHP_EOL, $results));
    u\deb("done with $module:$feature:$one resumeAt=$resumeAt skipToStep=$skipToStep");
  }
    u\deb("done with $module:$feature resumeAt=$resumeAt skipToStep=$skipToStep");
}

class DrupalWebTestCase {
  function setUp() {}
  function assertTrue($bool, $step, $sceneName) {
    global $results, $skipToStep, $resumeAt;
    global $ok, $no, $fails, $okAll, $noAll;

    $step = str_replace('"\\', '', $step); // for example "\user_login"
    $step = str_replace('\\', "\n     ", $step); // end of data lines
    $step = str_replace("''", '"', $step); // convention in .feature files

    if (@$skipToStep) {
        u\deb("SKIPPING scene=$sceneName step=$step resumeAt=$resumeAt skipToStep=$skipToStep");
      return;
    }

//    u\deb("NOT SKIPPING step=$step resumeAt=$resumeAt skipToStep=$skipToStep");
    $where = $sceneName == 'Setup' ? "[$sceneName] " : '';
    list ($result, $color) = $bool ? array('OK', 'lightgreen') : array('NO', 'yellow');
    $results[] = $result = color("$result: $where$step", $color);
    if ($bool) {
      $ok++; $okAll++;
    } else {
      $no++; $noAll++;
      if (!strpos($results[0], 'FAIL')) {
        $fails++;
        $results[0] = gotoError('FAIL', $fails);
      }
    }
  }
}

function color($msg, $color) {
  return "<pre style='background-color:$color;'>$msg</pre>";
}

function insertMessage($s, $type = 'status') {
  if (!@$_SESSION['messages'][$type]) $_SESSION['messages'][$type] = array();
  array_unshift($_SESSION['messages'][$type], $s);
}

function report($moduleName, $ok, $no, $module = '') {
  $moduleName = testLink($moduleName, $module);
  if ($no) {
    if (!strpos($no, 'a href')) $no = gotoError($no); // add link unless it's already there
  } else $no = '_';
  
  $msg = <<<EOF
  <h1>
  $moduleName - 
  ok: <span style='color:lightgreen; font-size:300%;'>$ok</span> 
  no: <span style='color:red; font-size:300%;'>$no</span>
  </h1>
EOF;
  insertMessage($msg);
}

function testLink($description, $module, $feature = '', $scene = '', $variant = '') {
  global $programPath;
  return "<a href='$programPath?module=$module&feature=$feature&scene=$scene&variant=$variant&restart=1'>$description</a>";
}

function gotoError($title, $errorNum = 0) {
  $next = $errorNum + 1;
  $link = "javascript:document.getElementById('testError$next').scrollIntoView(true); window.scrollBy(0, -50);";
  return "<a id='testError$errorNum' href=\"$link\">$title</a>";
}

/**
 * Find Files (copied from gherkin)
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
  if (!($recurse = is_array($result))) $result = array();
  if (!is_dir($path)) die("No features folder found for that module (path $path).");
  $dir = dir($path);
  
  while ($filename = $dir->read()) {
    if ($filename == '.' or $filename == '..') continue;
    $filename = "$path/$filename";
    if (is_dir($filename) and $recurse) $result = findFiles($filename, $pattern, $result);
    if (preg_match($pattern, $filename)){
      $result[] = $filename;
    }
  }
  return $result;
}
